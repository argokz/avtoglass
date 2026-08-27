<?php

namespace App\Livewire;

use App\Catalog\Vin;
use App\Models\CarBrand;
use App\Models\CarModel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Подбор по VIN.
 *
 * Обещаем ровно то, что VIN даёт: марку, страну сборки и год. Модель он
 * не содержит — знаки 4-8 у каждого завода свои и в открытых таблицах их нет.
 * Поэтому экран сужает выбор до моделей одной марки с подставленным годом,
 * а не делает вид, что узнал машину целиком.
 *
 * Введённый VIN запоминается в сессии и подставляется в заявку: менеджер
 * пробьёт его по своим каталогам точно, а покупателю не придётся набирать
 * семнадцать знаков второй раз.
 */
class VinLookup extends Component
{
    /** В адресной строке, чтобы результат можно было переслать менеджеру. */
    #[Url(except: '', history: true)]
    public string $vin = '';

    public function updatedVin(): void
    {
        $parsed = Vin::parse($this->vin);

        // Запоминаем только разобранный: класть в сессию полунабранную строку
        // значит подставить в заявку мусор.
        if ($parsed !== null) {
            session(['vin' => $parsed->value]);
        }
    }

    public function clear(): void
    {
        $this->vin = '';
        session()->forget('vin');
    }

    /** Модели найденной марки — то, из чего покупателю осталось выбрать. */
    private function models(?CarBrand $brand): Collection
    {
        if ($brand === null) {
            return collect();
        }

        return CarModel::query()
            ->where('car_brand_id', $brand->getKey())
            ->where('products_count', '>', 0)
            ->orderBy('name')
            ->get();
    }

    /**
     * Марка из VIN сопоставляется с нашим справочником по имени.
     *
     * Совпадения может не быть даже при известной марке: в каталоге только те,
     * на что есть стекло. Тогда честнее сказать «такой марки у нас нет»,
     * чем показать пустой список моделей.
     */
    private function brand(?Vin $parsed): ?CarBrand
    {
        $name = $parsed?->brand();

        if ($name === null) {
            return null;
        }

        return CarBrand::whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->where('models_count', '>', 0)
            ->first();
    }

    public function render(): View
    {
        $parsed = Vin::parse($this->vin);
        $brand = $this->brand($parsed);

        return view('livewire.vin-lookup', [
            'parsed' => $parsed,
            'brand' => $brand,
            'brandName' => $parsed?->brand(),
            'models' => $this->models($brand),
            // Подсказку показываем только когда человек уже что-то набрал:
            // ругаться на пустое поле незачем.
            'problem' => $parsed === null ? Vin::explainFailure($this->vin) : null,
        ]);
    }
}
