<?php

namespace App\Livewire;

use App\Actions\BookInstallation;
use App\Http\Requests\OrderContactRules;
use App\Logistics\BookingSlots;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServicePoint;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Выбор времени замены.
 *
 * У человека с разбитым стеклом вопрос «когда» стоит вровень с «сколько»,
 * а ответа на него до сих пор не было ни у нас, ни у конкурентов: везде
 * «оставьте заявку, менеджер перезвонит». Здесь клиент видит свободные окна
 * и берёт время сам.
 *
 * Показываем только дни, в которых окна есть: пустой день в календаре
 * заставляет тыкать наугад.
 */
class SlotPicker extends Component
{
    public ?Product $product = null;

    #[Url(as: 'tochka', except: 0)]
    public int $pointId = 0;

    /** Выбранный день в формате Y-m-d: так он переживает перезагрузку страницы. */
    #[Url(as: 'den', except: '')]
    public string $day = '';

    /** Выбранное начало работы, оно же признак того, что пора показывать форму. */
    public string $chosen = '';

    public string $name = '';

    public string $phone = '';

    public string $comment = '';

    /** Ловушка для ботов — то же поле, что у обычной заявки. */
    public string $website = '';

    public bool $privacy_consent = false;

    /** Номер оформленной заявки — экран после этого показывает подтверждение. */
    public ?string $booked = null;

    public ?string $bookedAt = null;

    public ?string $bookedPoint = null;

    public ?string $bookedAddress = null;

    public ?string $error = null;

    public function mount(?Product $product = null): void
    {
        $this->product = $product;
        $this->pointId = $this->pointId ?: (int) ($this->points()->first()?->id ?? 0);
    }

    /** Смена точки обнуляет день и время: у другого сервиса своё расписание. */
    public function selectPoint(int $pointId): void
    {
        $this->pointId = $pointId;
        $this->day = '';
        $this->chosen = '';
        $this->error = null;
    }

    public function selectDay(string $day): void
    {
        $this->day = $day;
        $this->chosen = '';
        $this->error = null;
    }

    public function selectSlot(string $chosen): void
    {
        $this->chosen = $chosen;
        $this->error = null;
    }

    public function book(): void
    {
        $this->error = null;

        $this->validate(
            OrderContactRules::fields(withSlot: true),
            OrderContactRules::messages(),
            OrderContactRules::attributes(),
        );

        $digits = Customer::normalizePhone($this->phone);

        if ($digits === null || mb_strlen($digits) < 10) {
            $this->addError('phone', 'Похоже, в номере не хватает цифр');

            return;
        }

        // Тот же лимит, что у POST-заявок: 10 в минуту с одного адреса.
        $throttleKey = 'orders:'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $this->addError('phone', 'Слишком много заявок подряд. Подождите минуту и попробуйте снова.');

            return;
        }

        RateLimiter::hit($throttleKey, 60);

        $point = $this->point();

        if ($point === null) {
            $this->error = 'Выберите сервис';

            return;
        }

        $order = app(BookInstallation::class)->handle(
            data: [
                'name' => $this->name,
                'phone' => $this->phone,
                'comment' => $this->comment ?: null,
                'privacy_consent' => $this->privacy_consent,
            ],
            point: $point,
            start: Carbon::parse($this->chosen),
            product: $this->product,
        );

        if ($order === null) {
            // Единственный ожидаемый отказ: пока заполняли форму, пост заняли
            $this->chosen = '';
            $this->error = 'Это время только что заняли. Выберите другое — свободные обновились.';

            return;
        }

        $this->booked = $order->number;
        $this->bookedAt = $this->chosen;
        $this->bookedPoint = $point->name;
        $this->bookedAddress = $point->address;
    }

    /** @return Collection<int,ServicePoint> */
    public function points(): Collection
    {
        $city = currentCity();

        return ServicePoint::where('is_active', true)
            ->where(function ($query) use ($city) {
                $query->where('city', $city)->orWhereNull('city');
            })
            ->orderBy('name')
            ->get();
    }

    private function point(): ?ServicePoint
    {
        return $this->points()->firstWhere('id', $this->pointId);
    }

    private function service(): ?Service
    {
        return Service::defaultFor($this->product?->glass_type);
    }

    public function render(): View
    {
        $slots = app(BookingSlots::class);
        $point = $this->point();
        $service = $this->service();

        $days = $point ? $slots->days($point, $service) : collect();

        // Если день не выбран или в нём уже нет окон — предлагаем ближайший
        $selected = $days->first(fn (Carbon $d) => $d->toDateString() === $this->day) ?? $days->first();

        return view('livewire.slot-picker', [
            'points' => $this->points(),
            'days' => $days,
            'selected' => $selected,
            'times' => $point && $selected ? $slots->slots($point, $selected, $service) : collect(),
            'service' => $service,
            'duration' => $slots->duration($service),
        ]);
    }
}
