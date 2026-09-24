<?php

namespace App\Livewire\Preview;

/** Synthetic customer preferences stored only in the current preview session. */
final class PreviewCustomerData
{
    public function read(): array
    {
        abort_unless(session()->has('modernization.preview_identity'), 403);

        return session($this->key(), [
            'profile' => ['name' => '', 'email' => ''], 'addresses' => [], 'templates' => [],
            'wholesale' => ['status' => 'none', 'company' => '', 'bin' => '', 'reason' => null],
            'addressSequence' => 0, 'templateSequence' => 0,
        ]);
    }

    public function save(array $data): void
    {
        abort_unless(session()->has('modernization.preview_identity'), 403);
        session()->put($this->key(), $data);
    }

    private function key(): string
    {
        return 'modernization.preview_customers.'.hash('sha256', session('modernization.preview_identity'));
    }
}
