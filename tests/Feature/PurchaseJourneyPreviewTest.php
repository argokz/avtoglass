<?php

namespace Tests\Feature;

use App\Livewire\Preview\PurchaseJourney;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseJourneyPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function preview()
    {
        $this->app->instance('env', 'preview');

        return Livewire::test(PurchaseJourney::class);
    }

    private function contact($component)
    {
        return $component->set('name', 'Демо клиент')->set('phone', '+77000000001')->set('consent', true);
    }

    public function test_prototype_is_hidden_outside_preview(): void
    {
        $this->get(route('preview.ux'))->assertNotFound();
        Livewire::test(PurchaseJourney::class)->assertNotFound();
    }

    public function test_retail_flow_produces_only_a_demo_result(): void
    {
        $count = Order::count();
        $component = $this->preview()->call('toggle', 1)->call('go', 'checkout');
        $this->contact($component)->call('submit')->assertHasNoErrors()
            ->assertSet('screen', 'result')->assertSet('demoOrder.status', 'confirmed')
            ->assertSet('demoOrder.total', 3500000);
        $this->assertSame($count, Order::count());
    }

    public function test_wholesale_always_requires_review_even_for_available_glass(): void
    {
        $component = $this->preview()->call('go', 'wholesale')->call('toggle', 1)->call('go', 'checkout');
        $this->contact($component)->call('submit')->assertHasNoErrors()->assertSet('demoOrder.status', 'pending');
    }

    public function test_unknown_price_stays_unknown_and_changed_price_requires_acceptance(): void
    {
        $component = $this->contact($this->preview()->call('toggle', 1)->set('scenario', 'unknown_price'));
        $this->assertNull($component->instance()->quote()['total']);
        $component->call('submit')->assertSet('demoOrder.status', 'pending')->assertSet('demoOrder.total', null);
        $component->set('scenario', 'changed')->call('submit')->assertHasErrors('checkout');
        $component->set('accepted', true)->call('submit')->assertHasNoErrors()->assertSet('demoOrder.total', 3650000);
    }

    public function test_list_errors_block_submission_and_corrected_rows_are_retained(): void
    {
        $component = $this->preview()->call('go', 'wholesale')
            ->set('list', "DEMO-1;4\nUNKNOWN;2")->call('matchList')
            ->assertSet('cart', [])->assertSet('importRows.2.state', 'not_found');
        $this->contact($component)->call('submit')->assertHasErrors('checkout');
        $component->set('list', "DEMO-1;4\nDEMO-2;2")->call('matchList')->call('applyImport')->call('submit')
            ->assertHasNoErrors()->assertSet('demoOrder.cart.2', 2)->assertSet('demoOrder.status', 'pending');
    }

    public function test_repeating_and_requesting_changes_do_not_overwrite_original_order(): void
    {
        $component = $this->preview()->set('changeText', 'Изменить адрес доставки')->call('submitChange')
            ->assertSet('demoOrder.total', 3500000)->assertSet('demoOrder.status', 'confirmed')
            ->assertSet('notice', 'change_sent');
        $component->call('repeatOrder')->assertSet('scenario', 'changed')->assertSet('demoOrder.total', 3500000)
            ->assertSet('screen', 'checkout')->assertSet('editing', false);
    }

    public function test_confirmed_order_cannot_be_edited_directly(): void
    {
        $this->preview()->call('editOrder')->assertForbidden();
    }

    public function test_order_snapshot_keeps_delivery_and_editing_keeps_number(): void
    {
        $component = $this->contact($this->preview()->call('toggle', 1))
            ->set('fulfillment', 'delivery')->set('address', 'Демонстрационная улица, 1')
            ->call('submit')->assertSet('demoOrder.fulfillment', 'delivery');
        $component->call('go', 'order')->assertSee('Доставка стекла')
            ->call('setOrderState', 'new')->call('editOrder')->assertSee('Сохранить изменения')
            ->set('cart.1', 2)->call('submit')->assertHasNoErrors()
            ->assertSet('demoOrder.number', 'DEMO-1043')->assertSet('demoOrder.status', 'new')
            ->assertSet('demoOrder.total', 7300000);
    }

    public function test_retail_and_wholesale_selections_are_kept_separately(): void
    {
        $this->preview()->call('toggle', 1)->set('cart.1', 2)
            ->call('go', 'wholesale')->assertSet('cart', [])
            ->call('toggle', 2)->call('go', 'catalog')->assertSet('cart', [1 => 2])
            ->call('go', 'wholesale')->assertSet('cart', [2 => 1]);
    }

    public function test_repeat_preserves_an_existing_draft_for_later_resume(): void
    {
        $component = $this->preview()->call('toggle', 2)->set('cart.2', 3)->call('repeatOrder')
            ->assertSet('cart', [1 => 1])->assertSet('savedDrafts.0.cart', [2 => 3]);
        $component->call('resumeDraft', 0)->assertSet('cart', [2 => 3])->assertSet('savedDrafts.0.cart', [1 => 1]);
    }

    public function test_failed_autosave_keeps_previous_saved_draft_and_retry_recovers(): void
    {
        $component = $this->preview()->call('toggle', 1)->set('scenario', 'save_error')->set('cart.1', 3)
            ->assertSet('saveState', 'error');
        $this->assertSame(1, session('modernization.purchase_preview.owners.'.hash('sha256', 'guest').'.cart.1'));
        $component->call('retrySave')->assertSet('saveState', 'saved');
        $this->assertSame(3, session('modernization.purchase_preview.owners.'.hash('sha256', 'guest').'.cart.1'));
    }

    public function test_excess_quantity_is_rejected_without_truncating_saved_selection(): void
    {
        $component = $this->preview()->call('toggle', 1)->set('cart.1', 100)
            ->assertHasErrors('cart.1')->assertSet('cart.1', 100);
        $this->assertNull($component->instance()->quote()['total']);
        $this->assertSame(1, session('modernization.purchase_preview.owners.'.hash('sha256', 'guest').'.cart.1'));
        $component->set('cart.1', 3)->assertHasNoErrors();
        $this->assertSame(3, session('modernization.purchase_preview.owners.'.hash('sha256', 'guest').'.cart.1'));
    }

    public function test_validation_and_views_use_kazakh_dictionary(): void
    {
        $ru = require lang_path('ru/shop.php');
        $kk = require lang_path('kk/shop.php');
        $this->assertSame(array_keys($ru), array_keys($kk));
        $this->preview()->set('locale', 'kk')->call('toggle', 1)->call('go', 'checkout')
            ->assertSee('Тапсырыс беру')->call('submit')->assertHasErrors(['name', 'phone', 'consent'])
            ->assertSee('Атыңызды көрсетіңіз.')->assertSee('Дұрыс телефон нөмірін көрсетіңіз.');
    }
}
