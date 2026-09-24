<?php

namespace Tests\Feature;

use App\Livewire\Preview\PreviewCustomerData;
use App\Livewire\Preview\PreviewOrderBook;
use App\Livewire\Preview\PurchaseJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchasePreferencesPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function preview()
    {
        $this->app->instance('env', 'preview');

        return Livewire::test(PurchaseJourney::class);
    }

    private function login($component = null, string $phone = '+77000000001')
    {
        $component ??= $this->preview();
        $component->set('loginPhone', $phone)->call('requestLoginCode')->assertHasNoErrors();
        $component->set('loginCode', $component->get('demoSmsCode'))->call('verifyLoginCode')->assertHasNoErrors();

        return $component;
    }

    private function address(string $label = 'Склад'): array
    {
        return ['label' => $label, 'city' => 'Караганда', 'line' => 'Демонстрационная улица, 1', 'recipient' => 'Демо получатель', 'phone' => '+77000000003'];
    }

    public function test_profile_is_private_and_login_returns_to_account(): void
    {
        $component = $this->preview()->call('go', 'account')->assertSee('Вход в кабинет');
        $this->login($component)->assertSet('screen', 'account');
        $component->set('profileName', 'Демо покупатель')->set('profileEmail', 'demo@example.test')->call('saveCustomerProfile')->assertHasNoErrors();
        $this->preview()->assertSet('profileName', 'Демо покупатель')->assertSet('profileEmail', 'demo@example.test');
        $component->call('logoutCabinet')->assertSet('profileName', '')->assertSet('profileEmail', '');
        $this->login($component, '+77000000002')->assertSet('profileName', '')->assertSet('profileEmail', '');
        $this->assertSame([], (new PreviewCustomerData)->read()['addresses']);
    }

    public function test_profile_address_and_template_writes_require_sign_in(): void
    {
        $this->preview()->call('saveCustomerProfile')->assertForbidden();
        $this->preview()->call('saveCustomerAddress')->assertForbidden();
        $this->preview()->call('savePurchaseTemplate')->assertForbidden();
    }

    public function test_profile_validation_preserves_saved_values(): void
    {
        $component = $this->login()->set('profileName', 'Демо имя')->call('saveCustomerProfile')->assertHasNoErrors();
        $component->set('profileEmail', 'invalid')->call('saveCustomerProfile')->assertHasErrors('profileEmail');
        $this->assertSame('', (new PreviewCustomerData)->read()['profile']['email']);
    }

    public function test_addresses_have_one_default_and_do_not_rewrite_order_or_draft_snapshots(): void
    {
        $component = $this->login()->set('addressForm', $this->address())->call('saveCustomerAddress')->assertHasNoErrors();
        $component->set('addressForm', $this->address('Дом'))->set('addressDefault', true)->call('saveCustomerAddress')->assertHasNoErrors();
        $data = (new PreviewCustomerData)->read();
        $this->assertFalse($data['addresses'][1]['default']);
        $this->assertTrue($data['addresses'][2]['default']);
        $component->call('toggle', 1)->set('fulfillment', 'delivery')->call('chooseCustomerAddress', 1)
            ->assertSet('address', 'Караганда, Демонстрационная улица, 1')->set('consent', true)->call('submit')->assertHasNoErrors();
        $number = $component->get('demoOrder.number');
        $component->call('editCustomerAddress', 1)->set('addressForm.line', 'Другой адрес, 2')->call('saveCustomerAddress')->call('deleteCustomerAddress', 2);
        $this->assertTrue((new PreviewCustomerData)->read()['addresses'][1]['default']);
        $this->assertSame('Караганда, Демонстрационная улица, 1', (new PreviewOrderBook)->find($number)['address']);
        $component->assertSet('address', 'Караганда, Демонстрационная улица, 1');
    }

    public function test_templates_keep_quantities_without_prices_and_preserve_previous_draft(): void
    {
        $component = $this->login()->call('go', 'wholesale')->call('toggle', 1)->set('cart.1', 2)
            ->set('templateName', 'Еженедельная закупка')->call('savePurchaseTemplate')->assertHasNoErrors();
        $template = (new PreviewCustomerData)->read()['templates'][1];
        $this->assertArrayNotHasKey('price', $template['items'][1]);
        $component->call('toggle', 1)->call('toggle', 4)->call('applyPurchaseTemplate', 1)
            ->assertSet('cart', [1 => 2])->assertSet('savedDrafts.0.cart', [4 => 1])->assertSet('editing', false);
        $component->call('renamePurchaseTemplate', 1)->set('templateName', 'Плановая закупка')->call('savePurchaseTemplate');
        $this->assertSame('Плановая закупка', (new PreviewCustomerData)->read()['templates'][1]['name']);
        $component->call('deletePurchaseTemplate', 1)->assertSet('cart', [1 => 2]);
        $this->assertSame([], (new PreviewCustomerData)->read()['templates']);
    }

    public function test_template_missing_offer_is_not_silently_dropped(): void
    {
        $component = $this->login();
        $data = (new PreviewCustomerData)->read();
        $data['templates'][1] = ['id' => 1, 'name' => 'Архив', 'items' => [99 => ['article' => 'OLD-CAMRY', 'manufacturer' => 'AGC', 'title' => 'archived_glass', 'quantity' => 2]]];
        (new PreviewCustomerData)->save($data);
        $component->call('applyPurchaseTemplate', 1)->assertSet('unresolvedRows.99.quantity', 2)->assertSet('cart', []);
        $this->assertNull($component->instance()->quote()['total']);
        $this->preview()->assertSet('unresolvedRows.99.quantity', 2);
    }

    public function test_incomplete_import_cannot_be_saved_or_displaced_by_a_template(): void
    {
        $component = $this->login()->call('go', 'wholesale')->call('toggle', 1)->set('templateName', 'Шаблон')->call('savePurchaseTemplate');
        $component->set('list', 'UNKNOWN;1')->call('matchList')->set('templateName', 'Нельзя')->call('savePurchaseTemplate')->assertHasErrors('templateName');
        $component->call('applyPurchaseTemplate', 1)->assertHasErrors('templateName')->assertSet('importRows.1.article', 'UNKNOWN');
        $component->call('go', 'catalog')->call('applyPurchaseTemplate', 1)->assertHasErrors('templateName');
    }

    public function test_wholesale_review_changes_prices_only_after_approval_and_preserves_unknown_price(): void
    {
        $component = $this->login()->call('go', 'wholesale')->call('toggle', 1)
            ->set('companyName', 'Демо организация')->set('companyBin', '123')->call('requestWholesaleAccess')->assertHasErrors('companyBin');
        $component->set('companyBin', '')->call('requestWholesaleAccess')->assertHasNoErrors();
        $this->assertSame(3500000, $component->instance()->quote()['total']);
        $component->call('simulateWholesaleDecision', 'rejected')->call('requestWholesaleAccess')->call('simulateWholesaleDecision', 'approved')
            ->assertSet('customerTermsChanged', true)->assertSet('accepted', false);
        $this->assertSame(3200000, $component->instance()->quote()['total']);
        $component->set('name', 'Демо клиент')->set('phone', '+77000000001')->set('consent', true)->call('submit')->assertHasErrors('checkout');
        $component->set('accepted', true)->call('submit')->assertHasNoErrors()->assertSet('demoOrder.status', 'pending')->assertSet('demoOrder.total', 3200000);
        $component->call('go', 'catalog')->call('prepareProposal')->assertHasNoErrors()->assertSet('proposal.prices.1', 3285000);
        $component->call('go', 'wholesale')->set('scenario', 'unknown_price');
        $this->assertNull($component->instance()->quote()['total']);
        $component->call('logoutCabinet')->call('go', 'wholesale')->call('toggle', 1);
        $this->assertSame(3500000, $component->instance()->quote()['total']);
    }

    public function test_approval_while_viewing_retail_invalidates_saved_wholesale_terms_on_return(): void
    {
        $component = $this->login()->call('go', 'wholesale')->call('toggle', 1)->set('accepted', true)->call('go', 'catalog');
        $component->set('companyName', 'Демо компания')->call('requestWholesaleAccess')->call('simulateWholesaleDecision', 'approved');
        $component->call('go', 'wholesale')->assertSet('customerTermsChanged', true)->assertSet('accepted', false);
    }

    public function test_editing_context_and_inputs_survive_reload_mode_switch_and_submission(): void
    {
        $component = $this->login()->call('openHistoryOrder', 'DEMO-1501')->call('editOrder')->set('cart.2', 2)
            ->set('fulfillment', 'delivery')->set('address', 'Новый адрес, 5')->set('name', 'Демо имя')->set('phone', '+77000000001');
        $restored = $this->preview()->assertSet('editing', true)->assertSet('editingVersion', 1)->assertSet('editContext.number', 'DEMO-1501')
            ->assertSet('cart.2', 2)->assertSet('address', 'Новый адрес, 5');
        $restored->call('go', 'catalog')->assertSet('editing', false)->call('go', 'wholesale')->assertSet('editing', true)
            ->set('consent', true)->call('submit')->assertHasNoErrors()->assertSet('demoOrder.number', 'DEMO-1501')->assertSet('demoOrder.version', 2)->assertSet('editing', false);
        $this->preview()->assertSet('editing', false);
        $this->assertSame(2, (new PreviewOrderBook)->find('DEMO-1501')['cart'][2]);
    }

    public function test_conflict_keeps_inputs_until_explicit_reload_or_detach(): void
    {
        $component = $this->login()->call('openHistoryOrder', 'DEMO-1501')->call('editOrder')->set('cart.2', 3);
        $order = (new PreviewOrderBook)->find('DEMO-1501');
        $order['version'] = 2;
        $order['cart'][2] = 2;
        (new PreviewOrderBook)->save($order);
        $restored = $this->preview()->call('go', 'checkout')->assertSet('cart.2', 3)->assertSee('Ваш ввод сохранён');
        $this->assertTrue($restored->instance()->editDraftConflict());
        $restored->set('name', 'Демо имя')->set('phone', '+77000000001')->set('consent', true)->call('submit')->assertHasErrors('checkout');
        $restored->call('reloadEditingOrder')->assertSet('cart.2', 2)->assertSet('editingVersion', 2)->assertSet('savedDrafts.0.cart.2', 3);
        $restored->call('detachEditingDraft')->assertSet('editing', false)->assertSet('cart.2', 2);
        $this->assertSame(2, (new PreviewOrderBook)->find('DEMO-1501')['version']);
    }

    public function test_owner_drafts_are_isolated_and_guest_selection_is_archived_when_returning(): void
    {
        $component = $this->login()->call('go', 'catalog')->call('toggle', 1)->set('address', 'Личный адрес')->call('logoutCabinet')
            ->assertSet('cart', [])->assertSet('address', '');
        $component->call('toggle', 4);
        $this->travel(61)->seconds();
        $this->login($component)->assertSet('cart', [1 => 1])->assertSet('address', 'Личный адрес')->assertSet('savedDrafts.0.cart', [4 => 1]);
        $component->call('logoutCabinet');
        $this->login($component, '+77000000002')->assertSet('cart', [])->assertSet('address', '');
    }

    public function test_legacy_session_draft_is_migrated_without_losing_selection(): void
    {
        session()->put('modernization.purchase_preview', ['cart' => [1 => 2], 'drafts' => ['retail' => [1 => 2], 'wholesale' => []], 'mode' => 'retail', 'name' => 'Старый подбор']);
        $this->preview()->assertSet('cart', [1 => 2])->assertSet('name', 'Старый подбор')->call('saveDraft');
        $this->assertFalse(session()->has('modernization.purchase_preview.cart'));
        $this->assertSame(2, session('modernization.purchase_preview.owners.'.hash('sha256', 'guest').'.cart.1'));
    }

    public function test_stale_tab_cannot_save_profile_under_a_different_identity(): void
    {
        $component = $this->login()->set('profileName', 'Первый покупатель');
        session()->put('modernization.preview_identity', '+77000000002');
        $component->call('saveCustomerProfile')->assertStatus(409);
        $this->assertSame('', (new PreviewCustomerData)->read()['profile']['name']);
    }

    public function test_guest_review_without_selected_products_survives_login_into_existing_draft(): void
    {
        $component = $this->login()->call('go', 'catalog')->call('toggle', 1)->call('logoutCabinet');
        $component->call('go', 'wholesale')->set('list', 'UNKNOWN;1')->call('matchList');
        $this->travel(61)->seconds();
        $this->login($component)->assertSet('cart', [1 => 1])->assertSet('savedDrafts.0.details.review.importRows.1.article', 'UNKNOWN');
        $component->call('go', 'drafts')->assertSee('Сохранена проверка списка закупки.');
        $component->call('resumeDraft', 0)->assertSet('mode', 'wholesale')->assertSet('screen', 'wholesale')->assertSet('importRows.1.article', 'UNKNOWN');
        $this->assertTrue($component->instance()->hasPendingImport());
    }

    public function test_guest_selection_does_not_overwrite_an_owner_review_without_products(): void
    {
        $component = $this->login()->call('go', 'wholesale')->set('list', 'UNKNOWN;1')->call('matchList')->call('logoutCabinet');
        $component->call('go', 'catalog')->call('toggle', 4);
        $this->travel(61)->seconds();
        $this->login($component)->assertSet('mode', 'wholesale')->assertSet('importRows.1.article', 'UNKNOWN')->assertSet('savedDrafts.0.cart', [4 => 1]);
    }

    public function test_repeating_and_editing_archive_pending_review_without_carrying_it_into_the_order(): void
    {
        foreach (['repeatOrder', 'editOrder'] as $action) {
            $component = $this->login()->call('go', 'wholesale')->set('list', 'UNKNOWN;1')->call('matchList');
            $component->call('openHistoryOrder', 'DEMO-1501')->call($action)->assertSet('importRows', [])->assertSet('list', '')
                ->assertSet('savedDrafts.0.details.review.importRows.1.article', 'UNKNOWN');
            $component->call('resumeDraft', 0)->assertSet('importRows.1.article', 'UNKNOWN')->assertSet('editing', false);
            $component->call('restart');
        }
    }
}
