<?php

namespace Tests\Feature;

use App\Cart\Cart;
use App\Livewire\AiChat;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_keeps_cart_and_conversation_and_sets_preference(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.keys' => ['test-key']]);
        $product = Product::factory()->create();
        app(Cart::class)->add($product, 2);
        $messages = [['role' => 'user', 'text' => 'Нужно стекло на Camry 2018']];

        $this->withSession([AiChat::SESSION_KEY => $messages])
            ->post(route('storefront.mode'), ['mode' => 'chat'])
            ->assertRedirect(route('assistant'))
            ->assertCookie('storefront_mode', 'chat')
            ->assertSessionHas(AiChat::SESSION_KEY, $messages);
        $this->assertSame(2, app(Cart::class)->count());

        $this->post(route('storefront.mode'), ['mode' => 'store'])
            ->assertRedirect(route('home'))
            ->assertCookie('storefront_mode', 'store')
            ->assertSessionHas(AiChat::SESSION_KEY, $messages);
        $this->assertSame(2, app(Cart::class)->count());
    }

    public function test_remembered_chat_does_not_replace_home_or_direct_product_link(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.keys' => ['test-key']]);
        $product = Product::factory()->create();

        $this->withCookie('storefront_mode', 'chat')->get(route('home'))
            ->assertOk()->assertViewIs('home')->assertSee('Продолжить в чате');
        $this->get(route('catalog.product', $product))->assertOk()->assertViewIs('catalog.product');
    }

    public function test_disabled_assistant_cannot_be_selected_and_store_still_opens(): void
    {
        config(['ai.keys' => []]);
        $this->post(route('storefront.mode'), ['mode' => 'chat'])->assertNotFound();
        $this->withCookie('storefront_mode', 'chat')->get(route('home'))
            ->assertOk()->assertViewIs('home')->assertDontSee('Продолжить в чате');
        $this->post(route('storefront.mode'), ['mode' => 'store'])->assertRedirect(route('home'));
    }

    public function test_unknown_mode_cannot_redirect_outside_storefront(): void
    {
        $this->post(route('storefront.mode'), ['mode' => 'https://example.test'])
            ->assertSessionHasErrors('mode');
    }
}
