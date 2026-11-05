<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Ai\Runtime\ChatIdentity;
use App\Ai\Runtime\Conversation;
use App\Domain\Identity\BuyerContext;
use App\Domain\Shared\PurchaseException;
use App\Filament\Resources\AssistantConversations\AssistantConversationResource;
use App\Models\Customer;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ManagerRequests
{
    public function create(BuyerContext $buyer, string $conversationId, array $contacts): Task
    {
        $buyer = $buyer->fresh();
        if ($buyer->customerId === null) {
            Validator::make($contacts, ['name' => ['required', 'string', 'min:2', 'max:100'], 'phone' => ['required', 'string', 'max:32']])->validate();
            if (! preg_match('/^7\d{10}$/D', Customer::normalizePhone($contacts['phone']) ?? '')) {
                throw new PurchaseException('CONTACT_PHONE_REQUIRED', 422);
            }
        }

        return DB::transaction(function () use ($buyer, $conversationId, $contacts) {
            $chat = Conversation::query()->whereKey($conversationId)->where('owner_key', ChatIdentity::ownerKey($buyer))->lockForUpdate()->firstOrFail();
            $session = app(ShoppingService::class)->get($chat);
            $state = $session->state;
            if (! empty($state['manager_task_id']) && ($task = Task::query()->open()->find($state['manager_task_id']))) {
                return $task;
            }
            $customer = $buyer->customerId ? Customer::query()->findOrFail($buyer->customerId) : Customer::forPhone($contacts['phone'], $contacts['name']);
            $safe = array_intersect_key($state['intent'] ?? [], array_flip(['query', 'year', 'car_model_id', 'glass_type', 'options', 'fulfillment', 'city']));
            $lines = array_map(fn ($line) => array_intersect_key($line, array_flip(['reference', 'quantity', 'manufacturer', 'offer_id', 'status', 'issue'])), array_values($state['lines'] ?? []));
            $task = Task::query()->create(['customer_id' => $customer->id, 'title' => 'Обращение из ИИ-помощника',
                'description' => 'Причина: клиент запросил помощь менеджера. Этап: '.($state['stage'] ?? 'vehicle')."\nДиалог: ".AssistantConversationResource::getUrl('view', ['record' => $chat->id], panel: 'admin')."\n".json_encode(['условия' => $safe, 'строки' => $lines, 'выбранные_предложения' => $state['selected'] ?? []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'due_at' => now()->addMinutes(15)]);
            $state['manager_task_id'] = $task->id;
            $session->forceFill(['state' => $state, 'version' => $session->version + 1])->save();

            return $task;
        });
    }
}
