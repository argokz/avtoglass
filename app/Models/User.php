<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'is_active', 'customer_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /**
     * Умолчания дублируют схему намеренно: значение из DEFAULT появляется только
     * после перечитывания строки, а проверка доступа срабатывает сразу после
     * создания пользователя — на модели, где атрибут ещё пуст.
     */
    protected $attributes = [
        'role' => UserRole::Customer->value,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * Доступ в панели. Без этого метода Filament пускал бы туда любого,
     * кто просто завёл аккаунт на сайте.
     *
     * Панелей две, и права у них разные: в админке прайсы и заявки,
     * в полевой — только наряды исполнителя.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->role->canAccessAdminPanel(),
            'field' => $this->role->canAccessFieldPanel(),
            default => false,
        };
    }

    public function isStaff(): bool
    {
        return $this->role !== UserRole::Customer;
    }

    /**
     * Карточка покупателя в CRM. У сотрудников её нет, и это не ошибка:
     * мастер — не клиент.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Заявки, которые кабинет вправе показать.
     *
     * Пока карточка не привязана, список пустой — а не «все заказы с этим
     * телефоном». Зарегистрироваться может кто угодно и на чей угодно номер,
     * поэтому чужую историю аккаунт получает только после того, как человек
     * назвал номер одной из своих заявок.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id', 'customer_id');
    }
}
