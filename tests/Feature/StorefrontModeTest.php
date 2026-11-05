<?php

namespace Tests\Feature;

use App\Ai\Runtime\ChatIdentity;
use App\Ai\Runtime\ChatStore;
use App\Ai\Runtime\MessageText;
use App\Cart\Cart;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $token = ChatIdentity::guestToken();
        $store = app(ChatStore::class);
        $conversation = $store->conversation(ChatIdentity::buyer());
        $turn = $store->enqueue($conversation, 'Нужно стекло на Camry 2018');

        $this->post(route('storefront.mode'), ['mode' => 'chat'])
            ->assertRedirect(route('assistant'))
            ->assertCookie('storefront_mode', 'chat')
            ->assertSessionHas('ordering.guest_token', $token);
        $this->assertSame($conversation->id, $store->conversation(ChatIdentity::buyer())->id);
        $this->assertSame('Нужно стекло на Camry 2018', MessageText::decode((string) DB::table('ai_messages')->where('conversation_id', $conversation->id)->where('role', 'user')->value('text')));
        $this->assertSame(2, app(Cart::class)->count());

        $this->post(route('storefront.mode'), ['mode' => 'store'])
            ->assertRedirect(route('home'))
            ->assertCookie('storefront_mode', 'store')
            ->assertSessionHas('ordering.guest_token', $token);
        $this->assertSame($turn, $conversation->fresh()->active_turn_id);
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
