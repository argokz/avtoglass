<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
}
