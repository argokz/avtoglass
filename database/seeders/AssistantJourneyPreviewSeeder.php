<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\CarModel;
use App\Models\Fitment;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ServicePoint;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class AssistantJourneyPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('preview') || DB::selectOne('select current_database() as name')->name !== 'avtoglasskz_modernization_preview') {
            throw new \RuntimeException('Assistant fixtures require isolated preview.');
        }
        DB::transaction(function () {
            settings()->save(['payments' => true, 'ai_assistant' => true, 'reservations' => true,
                'legal_name' => 'Демонстрационная организация (не оплачивать)', 'iik' => 'KZ000000000000000000']);
            $model = CarModel::query()->where('name', 'Camry 70')->firstOrFail();
            $supplier = Supplier::query()->where('slug', 'xyg-demo')->firstOrFail();
            $policies = array_values(array_filter(settings()->get('delivery_policies', []), fn ($policy) => ($policy['city'] ?? '') !== 'Караганда'));
            $policies[] = ['city' => 'Караганда', 'fee' => '1500.25', 'verified' => true,
                'supplier_ids' => [$supplier->id], 'source' => 'synthetic-preview'];
            settings()->save(['delivery_policies' => $policies]);
            $point = ServicePoint::query()->where('is_active', true)->firstOrFail();
            $flags = array_fill_keys(array_keys(Product::OPTION_FLAGS), false);
            $product = Product::query()->firstOrCreate(['slug' => 'assistant-journey-preview'], [
                'signature' => sha1('assistant-journey-preview'),
                'name' => 'Лобовое Toyota Camry 70 · тест покупки', 'eurocode' => 'JOURNEY-001', 'glass_type' => 'windshield',
                'is_published' => true, 'needs_review' => false, 'origin_country' => 'KZ', ...$flags,
            ]);
            Fitment::query()->updateOrCreate(['product_id' => $product->id, 'car_model_id' => $model->id], [
                'year_from' => 2017, 'year_to' => 2024, 'is_verified' => true, 'verification_source' => 'synthetic-preview', 'verified_at' => now(),
            ]);
            $offer = Offer::query()->updateOrCreate(['product_id' => $product->id, 'supplier_id' => $supplier->id, 'article' => 'JOURNEY-001'], [
                'is_active' => true, 'price_retail' => '35000.55', 'price_wholesale' => '25000.00', 'currency' => 'KZT', 'stock_qty' => 50,
                'raw_name' => 'Синтетическое предложение для проверки покупки',
                'auto_checkout_enabled' => true, 'price_valid_until' => now()->addDay(), 'stock_verified_at' => now(), 'verification_source' => 'synthetic-preview',
            ]);
            DB::table('offer_fulfillment_points')->updateOrInsert(['offer_id' => $offer->id, 'service_point_id' => $point->id], ['pickup_enabled' => true, 'installation_enabled' => true]);
            $product->refreshPricing();
            $accessPath = base_path('.codex/modernization/preview-access.json');
            if (is_readable($accessPath)) {
                $access = json_decode(file_get_contents($accessPath), true, flags: JSON_THROW_ON_ERROR);
                User::query()->firstOrCreate(['email' => 'assistant-manager@preview.invalid'], [
                    'name' => 'Демонстрационный менеджер', 'role' => UserRole::Manager, 'is_active' => true,
                    'password' => $access['password'],
                ]);
            }
        });
    }
}
