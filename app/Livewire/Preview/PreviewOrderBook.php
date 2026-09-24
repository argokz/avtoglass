<?php

namespace App\Livewire\Preview;

/** Session fixtures only; never reads or writes customer/order tables. */
final class PreviewOrderBook
{
    public const DEMO_PHONE = '+77000000001';

    public function owner(): string
    {
        return session('modernization.preview_identity', 'guest');
    }

    public function all(): array
    {
        $key = $this->key();
        if (! session()->has($key)) {
            session()->put($key, $this->seed());
        }

        return session($key, []);
    }

    public function find(string $number): ?array
    {
        return $this->all()[$number] ?? null;
    }

    public function save(array $order): void
    {
        $orders = $this->all();
        $orders[$order['number']] = $order;
        session()->put($this->key(), $orders);
    }

    public function nextNumber(): string
    {
        $number = (int) session('modernization.preview_sequence', 1043);
        while ($this->find('DEMO-'.$number)) {
            $number++;
        }
        session()->put('modernization.preview_sequence', $number + 1);

        return 'DEMO-'.$number;
    }

    public function adoptGuestOrders(): void
    {
        foreach (session('modernization.preview_orders.guest', []) as $order) {
            if (($order['created_in_preview'] ?? false) && ! $this->find($order['number'])) {
                $this->save($order);
            }
        }
        session()->forget('modernization.preview_orders.guest');
    }

    private function key(): string
    {
        return 'modernization.preview_orders.'.$this->owner();
    }

    private function seed(): array
    {
        if ($this->owner() !== self::DEMO_PHONE) {
            return [];
        }
        $orders = [];
        for ($index = 0; $index < 25; $index++) {
            $number = $index === 0 ? 'DEMO-1042' : 'DEMO-'.(1500 + $index);
            $id = $index % 3 + 1;
            $price = [1 => 3500000, 2 => 4800000, 3 => 4200000][$id];
            $status = ['confirmed', 'pending', 'new', 'scheduled', 'completed', 'cancelled'][$index % 6];
            $payment = $index === 2 ? 'pending' : (in_array($status, ['completed', 'scheduled'], true) ? 'paid' : 'unpaid');
            $orders[$number] = [
                'number' => $number, 'mode' => $index % 2 ? 'wholesale' : 'retail', 'status' => $status,
                'version' => 1, 'cart' => [$id => 1], 'prices' => [$id => $price], 'total' => $price,
                'fulfillment' => 'pickup', 'changes' => [], 'created_at' => now()->subDays($index * 4)->toIso8601String(),
                'payment' => $payment, 'paid' => $payment === 'paid' ? $price : 0, 'proposal' => null,
                'items' => [$id => ['title' => [1 => 'windshield', 2 => 'camera_glass', 3 => 'rear_glass'][$id], 'article' => 'DEMO-'.$id, 'manufacturer' => 'XYG']],
                'events' => [['kind' => 'created', 'at' => now()->subDays($index * 4)->toIso8601String()]],
            ];
        }

        return $orders;
    }
}
