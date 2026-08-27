<?php

namespace App\Filament\Pages;

use App\Actions\SettleCash;
use App\Analytics\CashOnHand;
use App\Filament\Concerns\OnlySalesStaff;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Касса: кто держит наличные и приём их в офисе.
 *
 * Приём денег от клиента был закрыт — известно, кто взял и сколько. Но между
 * «курьер взял на адресе» и «деньги в кассе» стоит поездка, и она нигде
 * не отражалась. Это единственный отрезок денежного контура без второй
 * подписи, и наличные пропадают именно здесь.
 *
 * Отметить свои же деньги сданными нельзя: принимает кассир, а не тот,
 * кто везёт, — иначе вторая подпись ничего не значит.
 */
class CashDesk extends Page
{
    use OnlySalesStaff;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Касса';

    protected static ?string $title = 'Наличные на руках';

    protected static UnitEnum|string|null $navigationGroup = 'Заказы';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.cash-desk';

    /** Сумма, которую кассир пересчитал в руках, по каждому человеку. */
    public array $counted = [];

    /** В меню висит несданная сумма: за ней и приходят на этот экран. */
    public static function getNavigationBadge(): ?string
    {
        $total = app(CashOnHand::class)->total();

        return $total > 0 ? number_format($total, 0, ',', ' ').' ₸' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Collection<int,object> */
    public function getHoldersProperty(): Collection
    {
        return app(CashOnHand::class)->byPerson();
    }

    public function detailsFor(int $userId): Collection
    {
        $user = User::find($userId);

        return $user ? app(CashOnHand::class)->forPerson($user) : collect();
    }

    public function accept(int $userId): void
    {
        $from = User::find($userId);

        if ($from === null) {
            return;
        }

        if ($from->is(Auth::user())) {
            // Вторая подпись обязана быть чужой, иначе это не проверка
            Notification::make()
                ->title('Свои деньги принять нельзя')
                ->body('Наличные принимает другой сотрудник — в этом и смысл сверки.')
                ->danger()
                ->send();

            return;
        }

        $counted = $this->counted[$userId] ?? null;

        $closed = app(SettleCash::class)->handle(
            from: $from,
            cashier: Auth::user(),
            countedAmount: $counted !== null && $counted !== '' ? (float) $counted : null,
        );

        if ($closed === 0) {
            Notification::make()
                ->title('Сумма не сошлась')
                ->body('Пересчитайте деньги. Принимать при расхождении нельзя — разбирать его потом дороже.')
                ->danger()
                ->send();

            return;
        }

        unset($this->counted[$userId]);

        Notification::make()
            ->title('Принято в кассу')
            ->body("Закрыто платежей: {$closed}. Ответственным записаны вы.")
            ->success()
            ->send();
    }
}
