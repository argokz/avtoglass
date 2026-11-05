<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Ai\Runtime\ChatIdentity;
use App\Ai\Runtime\Conversation;
use App\Domain\Assistant\MemoryService;
use App\Domain\Assistant\ShoppingService;
use App\Domain\Identity\BuyerContext;
use App\Domain\Shared\PurchaseException;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class AssistantMemory extends Component
{
    public array $facts = [];

    public bool $consent = false;

    public string $notice = '';

    #[Locked]
    public int $version = 0;

    public function mount(): void
    {
        $this->reload();
    }

    private function buyer(): BuyerContext
    {
        abort_unless(auth()->check() && ! auth()->user()->isStaff() && auth()->user()->customer_id !== null, 403);

        return ChatIdentity::buyer();
    }

    private function reload(): void
    {
        $memory = app(MemoryService::class)->get($this->buyer());
        $this->consent = $memory?->consented_at !== null;
        $this->version = $memory?->version ?? 0;
        $this->facts = app(MemoryService::class)->values($this->buyer());
        foreach ($this->facts['vehicles'] ?? [] as $index => $vehicle) {
            $this->facts['vehicles'][$index]['options'] = array_map(
                fn ($value) => $value === null ? '' : ($value ? '1' : '0'), $vehicle['options'] ?? [],
            );
        }
    }

    public function setConsent(): void
    {
        app(MemoryService::class)->consent($this->buyer(), $this->consent);
        $this->reload();
        $this->notice = $this->say('Настройки памяти сохранены.', 'Жад параметрлері сақталды.');
    }

    public function addVehicle(): void
    {
        $this->buyer();
        if (count($this->facts['vehicles'] ?? []) < 10) {
            $this->facts['vehicles'][] = ['id' => (string) Str::uuid(), 'label' => '', 'year' => null, 'options' => []];
        }
    }

    public function removeVehicle(string $id): void
    {
        $this->buyer();
        $this->facts['vehicles'] = array_values(array_filter($this->facts['vehicles'] ?? [], fn ($vehicle) => ($vehicle['id'] ?? null) !== $id));
        $this->save();
    }

    public function save(): void
    {
        try {
            $facts = $this->facts;
            foreach ($facts['vehicles'] ?? [] as $i => $vehicle) {
                $facts['vehicles'][$i]['year'] = empty($vehicle['year']) ? null : (int) $vehicle['year'];
                foreach ($vehicle['options'] ?? [] as $flag => $value) {
                    $facts['vehicles'][$i]['options'][$flag] = $value === '' || $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }
                // A changed label must be resolved again instead of retaining the old model ID.
                unset($facts['vehicles'][$i]['car_model_id']);
            }
            app(MemoryService::class)->save($this->buyer(), $facts, $this->version);
            $this->reload();
            $this->notice = $this->say('Сведения сохранены.', 'Мәліметтер сақталды.');
        } catch (PurchaseException $exception) {
            $this->notice = $exception->errorCode === 'MEMORY_VERSION_CONFLICT'
                ? $this->say('Профиль изменился в другой вкладке. Обновите страницу.', 'Профиль басқа қойындыда өзгерді. Бетті жаңартыңыз.')
                : $this->say('Сначала включите память.', 'Алдымен жадты қосыңыз.');
        }
    }

    public function forget(): void
    {
        app(MemoryService::class)->consent($this->buyer(), false);
        $this->reload();
        $this->notice = $this->say('Сохранённые сведения удалены. История заказов сохранена.', 'Сақталған мәліметтер жойылды. Тапсырыстар тарихы сақталды.');
    }

    public function importConversation(string $id): void
    {
        $token = session('ordering.guest_token');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        app(ShoppingService::class)->claimGuest($this->buyer(), $token, $id);
        $this->notice = $this->say('Диалог перенесён в кабинет.', 'Диалог кабинетке көшірілді.');
    }

    public function say(string $ru, string $kk): string
    {
        return app()->getLocale() === 'kk' ? $kk : $ru;
    }

    public function render()
    {
        $this->buyer();
        $token = session('ordering.guest_token');
        $guest = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? BuyerContext::guest($token) : null;

        return view('livewire.assistant-memory', ['memoryFacts' => app(MemoryService::class)->get($this->buyer())?->facts ?? [], 'guestChats' => $guest ? Conversation::query()->where('owner_key', ChatIdentity::ownerKey($guest))->latest('updated_at')->limit(50)->get() : collect()]);
    }
}
