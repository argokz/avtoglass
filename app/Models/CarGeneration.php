<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarGeneration extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_restyling' => 'boolean',
        ];
    }

    public function carModel(): BelongsTo
    {
        return $this->belongsTo(CarModel::class);
    }

    /**
     * Как поколение называют вслух: «XV70», «E60», «III».
     *
     * Код и имя заполнены не всегда и не всегда оба, поэтому берём то,
     * что есть. Пустая строка — значит показывать нечего.
     */
    public function title(): string
    {
        $parts = array_filter([$this->name, $this->code]);

        return trim(implode(' ', array_unique($parts)));
    }

    /** "2013 — н.в." / "2004 — 2015" */
    public function yearsLabel(): string
    {
        if (! $this->year_from) {
            return '';
        }

        return $this->year_from.' — '.($this->effectiveYearTo() ?: 'н.в.');
    }

    /**
     * Год окончания выпуска, достроенный по следующему поколению.
     *
     * В прайсах конец выпуска почти никогда не пишут — там важно начало.
     * Из-за этого Camry 50 хранится как «2011 — ...», и без поправки витрина
     * говорит покупателю «2011 — н.в.» рядом с Camry 70, которая идёт с 2018-го.
     * Раз следующее поколение началось, предыдущее закончилось.
     */
    public function effectiveYearTo(): ?int
    {
        if ($this->year_to) {
            return (int) $this->year_to;
        }

        if (! $this->year_from) {
            return null;
        }

        // Рестайлинг не считается сменой поколения: он идёт внутри того же.
        $next = static::query()
            ->where('car_model_id', $this->car_model_id)
            ->where('is_restyling', false)
            ->whereKeyNot($this->getKey())
            ->where('year_from', '>', $this->year_from)
            ->min('year_from');

        return $next ? (int) $next - 1 : null;
    }
}
