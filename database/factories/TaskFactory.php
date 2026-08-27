<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Перезвонить клиенту',
            'due_at' => now()->addHour(),
            'status' => TaskStatus::Open,
        ];
    }

    public function overdue(): static
    {
        return $this->state(['due_at' => now()->subHour(), 'status' => TaskStatus::Open]);
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function dueAt(string $when): static
    {
        return $this->state(['due_at' => $when]);
    }
}
