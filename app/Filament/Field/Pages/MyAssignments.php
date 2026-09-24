<?php

namespace App\Filament\Field\Pages;

use App\Actions\AcceptCashPayment;
use App\Analytics\CashOnHand;
use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Рабочий экран курьера и установщика.
 *
 * Один список на день, крупные кнопки, ничего лишнего: этим пользуются
 * стоя на улице, одной рукой, в перчатках.
 */
class MyAssignments extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Мои наряды';

    protected static ?string $title = 'Мои наряды';

    protected string $view = 'filament.field.pages.my-assignments';

    /** Смещение дня: 0 — сегодня, 1 — завтра. */
    public int $dayOffset = 0;

    public function getDay(): Carbon
    {
        return today()->addDays($this->dayOffset);
    }

    /**
     * Сколько наличных курьер везёт с собой.
     *
     * Сдать отсюда нельзя намеренно: принимает кассир, и вторая подпись
     * обязана быть чужой. Цифра нужна, чтобы курьер знал, сколько
     * пересчитывать на сдаче, а не узнавал сумму от принимающего.
     */
    public function getCashOnHandProperty(): float
    {
        return app(CashOnHand::class)->totalFor(Auth::user());
    }

    public function getAssignmentsProperty(): Collection
    {
        return Assignment::query()
            ->forDay(Auth::id(), $this->getDay())
            ->with(['order.customer', 'servicePoint'])
            ->get();
    }

    public function previousDay(): void
    {
        $this->dayOffset--;
    }

    public function nextDay(): void
    {
        $this->dayOffset++;
    }

    /**
     * Приём наличных на адресе.
     *
     * Сумма приходит из формы, а не берётся из заказа: клиент может отдать
     * часть, а остаток довезти позже, и курьер должен записать то, что взял
     * в руки, а не то, что значится в заявке.
     */
    public function acceptCash(int $assignmentId, string $amount): void
    {
        abort_unless(settings()->enabled('cash_in_field'), 403);

        $assignment = Assignment::query()
            ->where('assignee_id', Auth::id())   // чужой наряд не тронуть
            ->with('order')
            ->findOrFail($assignmentId);

        $payment = app(AcceptCashPayment::class)->handle(
            order: $assignment->order,
            amount: (float) str_replace([' ', ','], ['', '.'], $amount),
            receivedBy: Auth::user(),
            assignment: $assignment,
        );

        if ($payment === null) {
            Notification::make()
                ->title('Сумма больше остатка по заявке')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Принято '.number_format((float) $payment->amount, 0, ',', ' ').' ₸')
            ->success()
            ->send();
    }

    /**
     * Смена статуса наряда. Разрешённые переходы считает сама модель —
     * экран только рисует кнопки для того, что уже разрешено.
     */
    public function move(int $assignmentId, string $status): void
    {
        $assignment = Assignment::query()
            ->where('assignee_id', Auth::id())   // чужой наряд не тронуть
            ->findOrFail($assignmentId);

        $target = AssignmentStatus::from($status);

        if (! $assignment->transitionTo($target)) {
            Notification::make()->title('Так нельзя')->danger()->send();

            return;
        }

        Notification::make()->title($target->label())->success()->send();
    }
}
