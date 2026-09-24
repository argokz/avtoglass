<?php

namespace Tests\Feature;

use App\Livewire\Preview\PreviewOrderBook;
use App\Livewire\Preview\PurchaseJourney;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseSettlementPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function preview()
    {
        $this->app->instance('env', 'preview');

        return Livewire::test(PurchaseJourney::class);
    }

    private function change()
    {
        return $this->preview()->set('changeText', 'Изменить получение и состав')->call('submitChange')->call('openProposalEditor');
    }

    private function paidWholesale()
    {
        return $this->preview()->call('go', 'wholesale')->call('toggle', 1)->set('cart.1', 2)
            ->set('name', 'Демо покупатель')->set('phone', '+77000000001')->set('consent', true)->call('submit')
            ->call('setOrderState', 'confirmed')->call('simulatePaidOrder')->set('changeText', 'Уменьшить количество')->call('submitChange')->call('openProposalEditor');
    }

    public function test_replacement_and_address_remain_a_proposal_until_explicit_acceptance(): void
    {
        $count = Order::count();
        $component = $this->change()->set('managerLines.1.offer', 4)->set('managerFulfillment', 'delivery')
            ->set('managerAddress', 'Караганда, Новый адрес, 5')->call('sendEditedProposal')->assertHasErrors('managerFitmentChecked');
        $component->set('managerFitmentChecked', true)->call('sendEditedProposal')->assertHasNoErrors()
            ->assertSet('proposal.cart', [4 => 1])->assertSet('proposal.total', 4200000)->assertSet('demoOrder.cart', [1 => 1])
            ->assertSet('demoOrder.fulfillment', 'pickup')->assertSet('proposal.before.cart', [1 => 1]);
        $component->call('acceptProposal', 1)->assertHasErrors('proposalConsent');
        $component->set('proposalConsent', true)->call('acceptProposal', 1)->assertHasNoErrors()
            ->assertSet('demoOrder.cart', [4 => 1])->assertSet('demoOrder.address', 'Караганда, Новый адрес, 5')->assertSet('demoOrder.items.4.manufacturer', 'FYG');
        $this->assertSame($count, Order::count());
    }

    public function test_installation_requires_a_slot_and_rechecks_its_availability(): void
    {
        $component = $this->change()->set('managerFulfillment', 'installation')->set('managerFitmentChecked', true)
            ->call('sendEditedProposal')->assertHasErrors('managerSlot');
        $slot = array_key_first($component->instance()->proposalSlotOptions());
        $component->set('managerSlot', $slot)->call('sendEditedProposal')->assertHasNoErrors()->assertSet('proposal.total', 4500000);
        $component->call('simulateProposalSlotLoss', 1)->set('proposalConsent', true)->call('acceptProposal', 1)
            ->assertHasErrors('proposal')->assertSet('demoOrder.fulfillment', 'pickup');
        $component->call('openProposalEditor')->set('managerFulfillment', 'installation')->set('managerSlot', $slot)
            ->set('managerFitmentChecked', true)->call('sendEditedProposal')->set('proposalConsent', true)->call('acceptProposal', 2)
            ->assertHasNoErrors()->assertSet('demoOrder.fulfillment', 'installation')->assertSet('demoOrder.point', 'demo-center');
        $this->assertNotNull($component->get('demoOrder.slot.start'));
    }

    public function test_installation_time_is_not_accepted_after_it_has_passed(): void
    {
        $this->travelTo(now()->startOfDay()->setHour(15));
        $component = $this->change()->set('managerFulfillment', 'installation')->set('managerFitmentChecked', true);
        $component->set('managerSlot', array_key_first($component->instance()->proposalSlotOptions()))->call('sendEditedProposal');
        $this->travel(20)->hours();
        $component->set('proposalConsent', true)->call('acceptProposal', 1)->assertHasErrors('proposal')->assertSet('demoOrder.fulfillment', 'pickup');
    }

    public function test_removed_and_unknown_lines_cannot_produce_an_empty_or_invented_order(): void
    {
        $component = $this->change()->set('managerFitmentChecked', true)->set('managerLines.1.remove', true)->call('sendEditedProposal')->assertHasErrors('manager');
        $component->set('managerLines.1.remove', false)->set('managerLines.1.offer', 999)->call('sendEditedProposal')->assertHasErrors('manager');
        $component->set('managerLines.1.offer', 4)->set('managerLines.1.quantity', 100)->call('sendEditedProposal')->assertHasErrors('managerLines.1.quantity');
    }

    public function test_merged_replacements_validate_combined_quantity_and_stock(): void
    {
        $component = $this->preview()->call('go', 'wholesale')->call('toggle', 1)->call('toggle', 4)
            ->set('name', 'Демо покупатель')->set('phone', '+77000000001')->set('consent', true)->call('submit')->call('openProposalEditor')
            ->set('managerFitmentChecked', true)->set('managerLines.1.offer', 4)->set('managerLines.1.quantity', 60)->set('managerLines.4.quantity', 60)
            ->call('sendEditedProposal')->assertHasErrors('manager');
        $component->set('managerLines.1.quantity', 3)->set('managerLines.4.quantity', 3)->call('sendEditedProposal')
            ->assertSet('proposal.cart', [4 => 6])->assertSet('proposal.available', false)
            ->set('proposalConsent', true)->call('acceptProposal', 1)->assertHasErrors('proposal');
        $component->call('openProposalEditor')->set('managerFulfillment', 'installation')->set('managerFitmentChecked', true)
            ->call('sendEditedProposal')->assertHasErrors('managerFulfillment');
    }

    public function test_unknown_price_stays_unknown_and_never_opens_payment(): void
    {
        $component = $this->change()->set('scenario', 'unknown_price')->set('managerFitmentChecked', true)->call('sendEditedProposal')
            ->assertSet('proposal.total', null)->assertSet('proposal.available', false);
        $component->set('proposalConsent', true)->call('acceptProposal', 1)->assertHasErrors('proposal')->assertSet('demoOrder.total', 3500000);
    }

    public function test_decline_preserves_original_address_and_composition(): void
    {
        $this->change()->set('managerLines.1.offer', 4)->set('managerFulfillment', 'visit')->set('managerAddress', 'Новый адрес')
            ->set('managerFitmentChecked', true)->set('managerSlot', now()->addDay()->format('Y-m-d').'-10')->call('sendEditedProposal')
            ->set('proposalReply', 'Оставить исходный вариант')->call('declineProposal', 1)
            ->assertSet('demoOrder.cart', [1 => 1])->assertSet('demoOrder.fulfillment', 'pickup')->assertSet('proposal.status', 'declined');
    }

    public function test_another_tab_new_proposal_cannot_be_accepted_or_declined_by_old_version(): void
    {
        $component = $this->change()->set('managerFitmentChecked', true)->call('sendEditedProposal');
        $stored = (new PreviewOrderBook)->find('DEMO-1042');
        $stored['proposal']['version'] = 2;
        (new PreviewOrderBook)->save($stored);
        $component->set('proposalConsent', true)->call('acceptProposal', 1)->assertHasErrors('proposal');
        $component->set('proposalReply', 'Замечание к старой версии')->call('declineProposal', 1)->assertHasErrors('proposal');
        $component->call('requestFreshProposal')->assertHasErrors('proposal');
        $this->assertSame('open', (new PreviewOrderBook)->find('DEMO-1042')['proposal']['status']);
        $component->call('loadLatestProposal')->assertSet('proposal.version', 2)->assertSet('proposalConsent', false)
            ->set('proposalConsent', true)->call('acceptProposal', 2)->assertHasNoErrors()->assertSet('demoOrder.status', 'confirmed');
    }

    public function test_another_tab_changed_order_rejects_the_prepared_editor(): void
    {
        $component = $this->change()->set('managerFitmentChecked', true);
        $stored = (new PreviewOrderBook)->find('DEMO-1042');
        $stored['version']++;
        $stored['total'] = 9990000;
        (new PreviewOrderBook)->save($stored);
        $component->call('sendEditedProposal')->assertHasErrors('proposal');
        $this->assertSame(9990000, (new PreviewOrderBook)->find('DEMO-1042')['total']);
    }

    public function test_supplement_uses_only_the_balance_and_repeated_results_do_not_double_payment(): void
    {
        $component = $this->preview()->call('simulatePaidOrder')->set('changeText', 'Заменить производителя')->call('submitChange')->call('openProposalEditor')
            ->set('managerLines.1.offer', 4)->set('managerFitmentChecked', true)->call('sendEditedProposal')->set('proposalConsent', true)->call('acceptProposal', 1)
            ->assertSet('demoOrder.paid', 3500000)->assertSet('demoOrder.payment', 'partial');
        $this->assertSame(400000, $component->instance()->settlement()['due']);
        $version = $component->get('demoOrder.version');
        $component->call('startDemoPayment', $version)->call('startDemoPayment', $version);
        $attempt = array_key_first($component->get('demoOrder.paymentAttempts'));
        $this->assertCount(1, $component->get('demoOrder.paymentAttempts'));
        $component->call('resolveDemoPayment', $attempt, 'failed')->assertSet('demoOrder.paid', 3500000)->call('startDemoPayment', $version);
        $attempt = array_key_last($component->get('demoOrder.paymentAttempts'));
        $component->call('resolveDemoPayment', $attempt, 'succeeded')->call('resolveDemoPayment', $attempt, 'succeeded')
            ->assertSet('demoOrder.paid', 3900000)->assertSet('demoOrder.payment', 'paid');
        $this->assertSame(0, $component->instance()->settlement()['due']);
    }

    public function test_refund_waits_for_staff_and_success_preserves_gross_payment_and_counts_once(): void
    {
        $component = $this->paidWholesale()->set('managerLines.1.quantity', 1)->set('managerFitmentChecked', true)->call('sendEditedProposal')
            ->set('proposalConsent', true)->call('acceptProposal', 1)->assertSet('demoOrder.paid', 7000000)->assertSet('demoOrder.total', 3500000);
        $id = array_key_first($component->get('demoOrder.refunds'));
        $component->assertSet('demoOrder.refunds.'.$id.'.status', 'requested');
        $this->assertSame(0, $component->instance()->settlement()['refunded']);
        $component->call('startDemoRefund', $id)->call('startDemoRefund', $id)->assertSet('demoOrder.refunds.'.$id.'.attempts', 1)
            ->call('resolveDemoRefund', $id, 'failed')->assertSet('demoOrder.paid', 7000000);
        $this->assertSame(0, $component->instance()->settlement()['refunded']);
        $component->call('startDemoRefund', $id)->call('resolveDemoRefund', $id, 'succeeded')->call('resolveDemoRefund', $id, 'succeeded')
            ->assertSet('demoOrder.paid', 7000000)->assertSet('demoOrder.payment', 'paid');
        $this->assertSame(3500000, $component->instance()->settlement()['refunded']);
        $component->call('openProposalEditor')->set('managerLines.1.quantity', 2)->set('managerFitmentChecked', true)->call('sendEditedProposal')
            ->set('proposalConsent', true)->call('acceptProposal', 2)->assertHasNoErrors();
        $this->assertSame(3500000, $component->instance()->settlement()['due']);
    }

    public function test_pending_payment_and_refund_block_new_terms_without_rewriting_money(): void
    {
        $component = $this->paidWholesale()->set('managerLines.1.quantity', 1)->set('managerFitmentChecked', true)->call('sendEditedProposal')
            ->set('proposalConsent', true)->call('acceptProposal', 1)->call('openProposalEditor')->set('managerLines.1.quantity', 2)
            ->set('managerFitmentChecked', true)->call('sendEditedProposal')->set('proposalConsent', true)->call('acceptProposal', 2)
            ->assertHasErrors('proposal')->assertSet('demoOrder.total', 3500000)->assertSet('demoOrder.paid', 7000000);
        $component->call('restart');
        $component = $this->change()->set('managerFitmentChecked', true)->call('sendEditedProposal');
        $stored = (new PreviewOrderBook)->find('DEMO-1042');
        $stored['payment'] = 'pending';
        (new PreviewOrderBook)->save($stored);
        $component->set('proposalConsent', true)->call('acceptProposal', 1)->assertHasErrors('proposal')->assertSet('demoOrder.payment', 'pending');
    }

    public function test_proposal_history_retains_previous_terms_and_new_version_clears_consent(): void
    {
        $component = $this->change()->set('managerLines.1.offer', 4)->set('managerFitmentChecked', true)->call('sendEditedProposal')->set('proposalConsent', true)
            ->call('openProposalEditor')->set('managerFitmentChecked', true)->call('sendEditedProposal')->assertSet('proposalConsent', false)
            ->assertSet('proposal.version', 2)->assertSet('demoOrder.proposalHistory.0.cart', [4 => 1])->assertSet('demoOrder.proposalHistory.0.status', 'superseded');
        $this->assertSame([1 => 1], (new PreviewOrderBook)->find('DEMO-1042')['cart']);
    }

    public function test_manager_editor_private_fields_are_cleared_when_identity_changes(): void
    {
        $component = $this->change()->set('managerAddress', 'Частный адрес');
        $component->set('loginPhone', '+77000000001')->call('requestLoginCode');
        $component->set('loginCode', $component->get('demoSmsCode'))->call('verifyLoginCode')->assertSet('managerAddress', '')->assertSet('showManagerEditor', false);
        $component->call('openHistoryOrder', 'DEMO-1042')->set('changeText', 'Другой адрес')->call('submitChange')->call('openProposalEditor')->set('managerAddress', 'Другой частный адрес');
        $component->call('logoutCabinet')->assertSet('managerAddress', '')->assertSet('managerLines', []);
    }
}
