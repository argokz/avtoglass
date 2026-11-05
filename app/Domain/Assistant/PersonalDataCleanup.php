<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Ai\Models\AiCheckout;
use App\Ai\Runtime\ChatStore;
use App\Ai\Runtime\Conversation;
use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Ordering\OrderProposal;
use App\Domain\Pricing\Models\CheckoutQuote;
use App\Models\Customer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Called by the retention command after its eligibility checks, inside a transaction. */
final class PersonalDataCleanup
{
    public function customer(Customer $customer): void
    {
        if (! Schema::hasTable('assistant_memories')) {
            return;
        }
        CustomerMemory::query()->where('customer_id', $customer->id)->delete();
        $orders = $customer->orders()->get();
        $guestHashes = $orders->pluck('purchase_guest_owner_hash')->filter()->unique();
        $ownerKeys = $guestHashes->map(fn ($hash) => 'guest:'.$hash)->push('customer:'.$customer->id);
        // Ownership comes from orders and account links, never from a matching telephone.
        $actorIds = DB::table('users')->where('customer_id', $customer->id)->pluck('id');
        Conversation::query()->where(fn ($q) => $q->whereIn('actor_id', $actorIds)->orWhereIn('owner_key', $ownerKeys))->get()->each(function ($chat) {
            app(ChatStore::class)->delete($chat->buyer(), $chat->id);
        });
        AiCheckout::query()->whereIn('owner_scope', $ownerKeys->map(fn ($key) => hash('sha256', $key)))->delete();
        $drafts = OrderDraft::query()->where(fn ($q) => $q->where('customer_id', $customer->id)->orWhereIn('id', $orders->pluck('purchase_draft_id')->filter()))->get();
        foreach ($drafts as $draft) {
            $draft->forceFill(['contacts' => [], 'vehicle' => $this->clean($draft->vehicle ?? []), 'fulfillment' => $this->clean($draft->fulfillment ?? [])])->save();
            foreach (CheckoutQuote::query()->where('draft_id', $draft->id)->get() as $quote) {
                DB::table('checkout_quotes')->where('id', $quote->id)->update(['snapshot' => Crypt::encryptString(json_encode($this->clean($quote->snapshot), JSON_THROW_ON_ERROR))]);
            }
        }
        foreach ($orders as $order) {
            $order->forceFill(['purchase_snapshot' => $this->clean($order->purchase_snapshot ?? []), 'purchase_guest_owner_hash' => null])->saveQuietly();
            foreach (OrderProposal::query()->where('order_id', $order->id)->get() as $proposal) {
                $proposal->forceFill(['snapshot' => $this->clean($proposal->snapshot)])->save();
            }
        }
    }

    private function clean(array $data): array
    {
        foreach (['contacts', 'billing', 'address', 'vin', 'comment'] as $field) {
            unset($data[$field]);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->clean($value);
            }
        }

        return $data;
    }
}
