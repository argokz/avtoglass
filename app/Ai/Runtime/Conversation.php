<?php

namespace App\Ai\Runtime;

use App\Domain\Identity\BuyerContext;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $table = 'ai_conversations';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['guest_token', 'owner_key'];

    protected function casts(): array
    {
        return ['guest_token' => 'encrypted'];
    }

    public function getTitleAttribute(?string $value): ?string
    {
        return $value === null ? null : MessageText::decode($value);
    }

    public function setTitleAttribute(?string $value): void
    {
        $this->attributes['title'] = $value === null ? null : MessageText::encode($value);
    }

    public function buyer(): BuyerContext
    {
        return $this->actor_id !== null
            ? BuyerContext::account((int) $this->actor_id, $this->locale)
            : BuyerContext::guest($this->guest_token, $this->locale);
    }
}
