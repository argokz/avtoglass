<?php

namespace Tests\Feature;

use App\Livewire\Preview\PreviewOrderBook;
use App\Livewire\Preview\PurchaseJourney;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseCabinetPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function preview()
    {
        $this->app->instance('env', 'preview');

        return Livewire::test(PurchaseJourney::class);
    }

    private function login($component = null, string $phone = PreviewOrderBook::DEMO_PHONE)
    {
        $component ??= $this->preview();
        $component->call('go', 'orders')->set('loginPhone', $phone)->call('requestLoginCode')->assertHasNoErrors();
        $component->set('loginCode', $component->get('demoSmsCode'))->call('verifyLoginCode')->assertHasNoErrors();

        return $component;
    }

    public function test_history_is_hidden_until_code_is_verified_and_no_database_order_is_created(): void
    {
        $before = Order::count();
        $component = $this->preview()->call('go', 'orders')->assertSee('Вход в кабинет')->assertDontSee('DEMO-1501');
        $this->assertSame([], $component->instance()->historyResults()['rows']);
        $this->login($component)->assertSee('DEMO-1501')->assertSet('screen', 'orders');
        $this->assertSame(25, $component->instance()->historyResults()['total']);
        $this->assertSame($before, Order::count());
        $this->assertNull(session('modernization.preview_challenge'));
        $component->call('verifyLoginCode')->assertHasErrors('loginCode');
    }

    public function test_expiry_attempt_limits_and_resend_are_checked_on_server(): void
    {
        $component = $this->preview()->call('go', 'orders')->call('requestLoginCode');
        $old = $component->get('demoSmsCode');
        $component->call('requestLoginCode')->assertHasErrors('loginPhone');
        for ($i = 0; $i < 5; $i++) {
            $component->set('loginCode', '000000')->call('verifyLoginCode')->assertHasErrors('loginCode');
        }
        $component->set('loginCode', $old)->call('verifyLoginCode')->assertHasErrors('loginCode');
        $this->travel(61)->seconds();
        $component->call('requestLoginCode')->assertHasNoErrors();
        $this->assertNotSame($old, $component->get('demoSmsCode'));
        $component->set('loginCode', $old)->call('verifyLoginCode')->assertHasErrors('loginCode');
        $this->travel(301)->seconds();
        $component->set('loginCode', $component->get('demoSmsCode'))->call('verifyLoginCode')->assertHasErrors('loginCode');
        $this->assertFalse($component->instance()->signedIn());
    }

    public function test_phone_attempt_budget_survives_changing_number_and_new_challenge(): void
    {
        $component = $this->preview()->call('requestLoginCode');
        for ($round = 0; $round < 2; $round++) {
            for ($i = 0; $i < 5; $i++) {
                $component->set('loginCode', '000000')->call('verifyLoginCode');
            }
            if ($round === 0) {
                $component->call('changeLoginPhone');
                $this->travel(61)->seconds();
                $component->call('requestLoginCode')->assertHasNoErrors();
            }
        }
        $component->call('changeLoginPhone');
        $this->travel(61)->seconds();
        $component->call('requestLoginCode')->assertHasErrors('loginPhone');
        $this->assertFalse($component->instance()->signedIn());
    }

    public function test_code_screen_is_restored_and_guest_checkout_keeps_selection(): void
    {
        $component = $this->preview()->call('toggle', 1)->call('go', 'orders')->call('requestLoginCode');
        $restored = $this->preview()->assertSet('loginState', 'code')->assertSet('demoSmsCode', $component->get('demoSmsCode'));
        $restored->call('go', 'checkout')->assertSet('cart', [1 => 1])->assertSee('Ваше имя');
    }

    public function test_list_filters_pagination_and_active_first_ordering(): void
    {
        $component = $this->login();
        $result = $component->instance()->historyResults();
        $this->assertCount(20, $result['rows']);
        $this->assertSame(2, $result['pages']);
        $this->assertSame('DEMO-1042', $result['rows'][0]['number']);
        $component->call('historyPage', 2);
        $this->assertCount(5, $component->instance()->historyResults()['rows']);
        $component->set('orderStatus', 'completed')->assertSet('orderPage', 1);
        $this->assertSame(['completed'], array_values(array_unique(array_column($component->instance()->historyResults()['rows'], 'status'))));
        $component->call('resetHistoryFilters')->set('orderMode', 'wholesale')->set('orderPeriod', '30');
        $this->assertSame(4, $component->instance()->historyResults()['total']);
        $component->call('resetHistoryFilters')->set('orderSearch', 'камер');
        $this->assertGreaterThan(0, $component->instance()->historyResults()['total']);
        $component->set('orderSearch', 'DEMO-1042');
        $this->assertSame(1, $component->instance()->historyResults()['total']);
        $component->set('orderSearch', 'NOT-FOUND')->assertSee('Заказов по этим условиям пока нет');
    }

    public function test_other_phone_cannot_read_demo_account_or_guess_its_order(): void
    {
        $component = $this->login(null, '+77000000002');
        $this->assertSame(0, $component->instance()->historyResults()['total']);
        $component->call('openHistoryOrder', 'DEMO-1501')->assertHasErrors('history')->assertDontSee('DEMO-1501');
    }

    public function test_deep_link_returns_to_selected_order_after_login_and_logout_hides_history(): void
    {
        $component = $this->preview()->set('historyOrder', 'DEMO-1501')->call('requestLoginCode');
        $component->set('loginCode', $component->get('demoSmsCode'))->call('verifyLoginCode')
            ->assertSet('screen', 'order')->assertSet('demoOrder.number', 'DEMO-1501');
        $component->call('logoutCabinet')->assertSee('Вход в кабинет')->assertDontSee('DEMO-1501');
        $component->call('openHistoryOrder', 'DEMO-1501')->assertForbidden();
    }

    public function test_new_orders_keep_distinct_numbers_and_survive_reopening_without_overwriting_original(): void
    {
        $component = $this->preview()->call('toggle', 1)->set('name', 'Демо клиент')->set('phone', '+77000000001')->set('consent', true);
        $component->call('submit')->assertHasNoErrors()->assertSet('demoOrder.number', 'DEMO-1043');
        $component->call('submit')->assertHasNoErrors()->assertSet('demoOrder.number', 'DEMO-1043');
        $component->call('go', 'catalog')->call('go', 'checkout')->call('submit')->assertHasNoErrors();
        $second = $component->get('demoOrder.number');
        $this->assertNotSame('DEMO-1043', $second);
        $this->login($component)->call('openHistoryOrder', 'DEMO-1043')->assertSet('demoOrder.total', 3500000);
        $this->assertSame(27, $component->instance()->historyResults()['total']);
        $this->preview()->call('openHistoryOrder', $second)->assertSet('demoOrder.total', 3500000);
        $this->assertSame(3500000, (new PreviewOrderBook)->find('DEMO-1042')['total']);
    }

    public function test_payment_blocks_direct_edit_and_cancellation_but_allows_change_request(): void
    {
        $component = $this->login()->call('openHistoryOrder', 'DEMO-1502')->assertSee('Платёж обрабатывается');
        $this->assertSame('history_payment_reason', $component->instance()->orderEditReason());
        $component->set('changeText', 'Изменить получение')->call('submitChange')->assertHasNoErrors();
        $this->preview()->call('openHistoryOrder', 'DEMO-1502')->assertSee('Изменить получение')->call('editOrder')->assertForbidden();
        $this->preview()->call('openHistoryOrder', 'DEMO-1502')->call('cancelUnconfirmed', 1)->assertForbidden();
    }

    public function test_cancellation_and_proposal_updates_are_retained_in_history(): void
    {
        $component = $this->login()->call('openHistoryOrder', 'DEMO-1501')->call('cancelUnconfirmed', 1)
            ->assertSet('demoOrder.status', 'cancelled')->assertSet('demoOrder.version', 2);
        $this->preview()->call('openHistoryOrder', 'DEMO-1501')->assertSee('Покупатель отменил заявку');
        $component->call('openHistoryOrder', 'DEMO-1042')->call('setOrderState', 'pending')->call('prepareProposal', 'ready');
        $this->preview()->call('openHistoryOrder', 'DEMO-1042')->assertSet('proposal.status', 'open')
            ->set('proposalConsent', true)->call('acceptProposal', 1)->assertSet('demoOrder.total', 3650000);
        $this->preview()->call('openHistoryOrder', 'DEMO-1042')->assertSet('demoOrder.total', 3650000)->assertSet('proposal.status', 'accepted');
    }

    public function test_stale_edit_detects_newer_saved_version(): void
    {
        $component = $this->login()->call('openHistoryOrder', 'DEMO-1501')->call('editOrder');
        $order = (new PreviewOrderBook)->find('DEMO-1501');
        $order['version']++;
        (new PreviewOrderBook)->save($order);
        $component->set('name', 'Демо клиент')->set('phone', '+77000000001')->set('consent', true)
            ->call('submit')->assertHasErrors('checkout');
        $this->assertSame(2, (new PreviewOrderBook)->find('DEMO-1501')['version']);
    }
}
