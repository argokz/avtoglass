<?php

namespace Database\Seeders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Customer;
use App\Models\Fitment;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServicePoint;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class PreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('preview') || DB::connection()->getDatabaseName() !== 'avtoglasskz_modernization_preview') {
            throw new RuntimeException('Preview fixtures require the dedicated preview database.');
        }

        $credentialsPath = base_path('.codex/modernization/preview-access.json');
        if (Product::where('slug', 'preview-camry-70')->exists()) {
            Product::where('slug', 'preview-camry-70')->update(['origin_country' => 'KZ']);
            $this->command?->info('Preview fixtures already exist; left unchanged.');

            return;
        }

        $password = Str::password(24);

        DB::transaction(function () use ($password) {
            settings()->save([
                'brand_name' => 'AvtoGlass · демо',
                'ai_assistant' => true,
                'display_stock' => true,
                'reservations' => true,
                'payments' => false,
                'email_alerts' => false,
                'telegram_alerts' => false,
                'fiscal' => false,
            ]);

            $brand = CarBrand::create(['name' => 'Toyota', 'name_ru' => 'Тойота', 'slug' => 'toyota', 'models_count' => 1, 'is_popular' => true]);
            $model = CarModel::create(['car_brand_id' => $brand->id, 'name' => 'Camry 70', 'slug' => 'camry-70', 'products_count' => 3]);
            $supplier = Supplier::factory()->create(['name' => 'XYG Demo', 'slug' => 'xyg-demo']);

            foreach ([['preview-camry-70', 'Лобовое стекло Toyota Camry 70', 35000, 6], ['preview-camry-camera', 'Лобовое стекло Camry 70 с камерой', 48000, 2], ['preview-camry-rear', 'Заднее стекло Toyota Camry 70', 42000, 0]] as $index => [$slug, $name, $price, $stock]) {
                $product = Product::factory()->create([
                    'slug' => $slug, 'name' => $name, 'eurocode' => 'PREVIEW-'.($index + 1),
                    'camera' => $index === 1, 'glass_type' => $index === 2 ? 'rear' : 'windshield',
                    'origin_country' => $index === 0 ? 'KZ' : 'CN',
                ]);
                Fitment::create(['product_id' => $product->id, 'car_model_id' => $model->id, 'year_from' => 2017, 'year_to' => 2024, 'is_verified' => true]);
                Offer::factory()->price($price)->create(['product_id' => $product->id, 'supplier_id' => $supplier->id, 'article' => 'DEMO-'.($index + 1), 'stock_qty' => $stock]);
                $product->refreshPricing();
            }

            ServicePoint::factory()->create(['name' => 'Демонстрационный сервис', 'address' => 'Тестовая улица, 1', 'city' => 'Караганда']);
            $this->call(ServiceSeeder::class);

            foreach (['retail' => '77000000001', 'wholesale' => '77000000002'] as $type => $phone) {
                $factory = Customer::factory();
                $customer = ($type === 'wholesale' ? $factory->wholesale(10) : $factory)->create([
                    'name' => $type === 'retail' ? 'Демо покупатель' : 'Демо оптовик', 'phone' => $phone,
                    'email' => $type.'@example.test', 'default_city' => 'Караганда',
                ]);
                User::factory()->create([
                    'name' => $customer->name, 'email' => $customer->email, 'phone' => $phone,
                    'role' => UserRole::Customer, 'customer_id' => $customer->id, 'password' => Hash::make($password),
                ]);
                $order = Order::factory()->create([
                    'customer_id' => $customer->id, 'source' => $type === 'wholesale' ? OrderSource::Wholesale : OrderSource::Cart,
                    'status' => OrderStatus::New, 'fulfillment' => 'pickup', 'city' => 'Караганда',
                ]);
                $offer = Offer::firstOrFail();
                $order->items()->create(['product_id' => $offer->product_id, 'offer_id' => $offer->id, 'name' => $offer->product->name, 'price' => $offer->sellingPrice($customer), 'quantity' => 1]);
                $order->recalculateTotal();
            }
        });

        if (! is_dir(dirname($credentialsPath))) {
            mkdir(dirname($credentialsPath), 0770, true);
        }
        file_put_contents($credentialsPath, json_encode(['retail_phone' => '77000000001', 'wholesale_phone' => '77000000002', 'password' => $password], JSON_PRETTY_PRINT));
        $this->command?->info('Synthetic fixtures created. Local access details: .codex/modernization/preview-access.json');
    }
}
