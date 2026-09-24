<?php

namespace Tests\Feature;

use App\Livewire\Preview\PurchaseJourney;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseReviewPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function preview()
    {
        $this->app->instance('env', 'preview');

        return Livewire::test(PurchaseJourney::class);
    }

    private function proposal(string $variant = 'ready')
    {
        return $this->preview()->call('setOrderState', 'pending')->call('prepareProposal', $variant);
    }

    public function test_import_waits_for_selection_and_explicit_application(): void
    {
        $component = $this->preview()->call('go', 'wholesale')->set('list', "DEMO-CAMRY;2\nDEMO-4;1")
            ->call('matchList')->assertSet('cart', [])->assertSet('importRows.1.state', 'choice');
        $component->call('chooseImportOffer', 1, 4)->assertSet('cart', []);
        $this->assertSame(3, $component->instance()->importPreview()['groups'][4]['total']);
        $component->call('applyImport')->assertSet('cart', [4 => 3])->assertSet('importRows.1.state', 'applied');
        $component->call('applyImport')->assertSet('cart', [4 => 3]);
        $this->assertFalse($component->instance()->hasPendingImport());
    }

    public function test_unresolved_rows_survive_partial_application_and_inline_correction(): void
    {
        $component = $this->preview()->call('go', 'wholesale')->set('list', "DEMO-1;2\nUNKNOWN;1")
            ->call('matchList')->call('applyImport')->assertSet('cart', [1 => 2])
            ->assertSet('importRows.2.state', 'not_found');
        $this->assertTrue($component->instance()->hasPendingImport());
        $component->set('importCorrections.2.article', 'DEMO-2')->call('correctImportRow', 2)
            ->call('applyImport')->assertSet('cart', [1 => 2, 2 => 1]);
        $this->assertFalse($component->instance()->hasPendingImport());
        $component->call('clearList')->assertSet('cart', [1 => 2, 2 => 1])->assertSet('importRows', []);
    }

    public function test_duplicate_overflow_includes_existing_selection_without_truncating(): void
    {
        $component = $this->preview()->call('go', 'wholesale')->call('toggle', 1)->set('cart.1', 50)
            ->set('list', "DEMO-1;40\nDEMO-1;30\nDEMO-2;1")->call('matchList');
        $this->assertSame('overflow', $component->instance()->importPreview()['rows'][1]['state']);
        $component->call('applyImport')->assertSet('cart', [1 => 50, 2 => 1]);
        $component->call('skipImportRow', 2)->call('applyImport')->assertSet('cart', [1 => 90, 2 => 1]);
    }

    public function test_oversized_list_is_rejected_as_a_whole(): void
    {
        $this->preview()->call('go', 'wholesale')->call('toggle', 1)
            ->set('list', implode("\n", array_fill(0, 1001, 'DEMO-2;1')))->call('matchList')
            ->assertSet('importLimitError', 'import_too_large')->assertSet('importRows', [])
            ->call('applyImport')->assertHasErrors('import')->assertSet('cart', [1 => 1]);
    }

    public function test_edited_source_requires_recheck_and_wrong_candidate_is_rejected(): void
    {
        $this->preview()->call('go', 'wholesale')->set('list', 'DEMO-1;1')->call('matchList')
            ->set('list', 'DEMO-1;2')->call('applyImport')->assertHasErrors('import')->assertSet('cart', []);
        $this->preview()->call('go', 'wholesale')->set('list', 'DEMO-CAMRY;1')->call('matchList')
            ->call('chooseImportOffer', 1, 2)->assertStatus(422);
    }

    public function test_review_rows_and_applied_flags_restore_from_session(): void
    {
        $this->preview()->call('go', 'wholesale')->set('list', "DEMO-1;2\nUNKNOWN;1")
            ->call('matchList')->call('applyImport');
        $this->preview()->assertSet('cart', [1 => 2])->assertSet('importRows.1.state', 'applied')
            ->assertSet('importRows.2.state', 'not_found')->call('applyImport')->assertSet('cart', [1 => 2]);
    }

    public function test_manager_proposal_is_a_snapshot_until_explicit_acceptance(): void
    {
        $count = Order::count();
        $component = $this->proposal()->assertSet('demoOrder.total', 3500000)
            ->assertSet('demoOrder.status', 'pending')->assertSet('proposal.total', 3650000);
        $component->call('acceptProposal', 1)->assertHasErrors('proposalConsent')->assertSet('demoOrder.total', 3500000);
        $component->set('proposalConsent', true)->call('acceptProposal', 1)->assertHasNoErrors()
            ->assertSet('demoOrder.total', 3650000)->assertSet('demoOrder.status', 'confirmed')
            ->assertSet('proposal.status', 'accepted')->assertSet('demoOrder.version', 3);
        $component->call('acceptProposal', 1)->assertSet('demoOrder.version', 3);
        $this->assertSame($count, Order::count());
    }

    public function test_expired_and_unavailable_proposals_cannot_be_accepted(): void
    {
        foreach (['expired', 'waiting', 'stock_changed', 'conflict'] as $variant) {
            $this->proposal($variant)->set('proposalConsent', true)->call('acceptProposal', 1)
                ->assertHasErrors('proposal')->assertSet('demoOrder.total', 3500000)
                ->assertSet('demoOrder.status', 'pending');
        }
    }

    public function test_expiration_is_checked_again_at_submission(): void
    {
        $component = $this->proposal()->set('proposalConsent', true);
        $this->travel(25)->hours();
        $component->call('acceptProposal', 1)->assertHasErrors('proposal')->assertSet('demoOrder.status', 'pending');
    }

    public function test_replacement_proposal_requires_its_own_acceptance(): void
    {
        $component = $this->proposal()->set('proposalConsent', true)->call('prepareProposal', 'ready')
            ->assertSet('proposal.version', 2)->assertSet('proposalConsent', false);
        $component->set('proposalConsent', true)->call('acceptProposal', 1)->assertHasErrors('proposal')
            ->assertSet('demoOrder.total', 3500000);
        $component->call('acceptProposal', 2)->assertHasNoErrors()->assertSet('demoOrder.status', 'confirmed');
    }

    public function test_declining_proposal_keeps_confirmed_order_and_allows_new_request(): void
    {
        $this->preview()->set('changeText', 'Изменить состав')->call('submitChange')->call('prepareProposal')
            ->set('proposalReply', 'Предложите другой вариант')->call('declineProposal', 1)
            ->assertSet('demoOrder.status', 'confirmed')->assertSet('demoOrder.total', 3500000)
            ->assertSet('proposal.status', 'declined')->call('requestFreshProposal')
            ->assertSet('proposal.status', 'refresh_requested')->assertSet('demoOrder.total', 3500000);
    }

    public function test_editing_pending_order_invalidates_manager_proposal(): void
    {
        $component = $this->proposal()->call('editOrder')->set('cart.1', 2)
            ->set('name', 'Демо клиент')->set('phone', '+77000000001')->set('consent', true)
            ->call('submit')->assertHasNoErrors()->assertSet('demoOrder.total', 7000000)
            ->assertSet('demoOrder.status', 'pending')->assertSet('proposal.status', 'superseded');
        $component->set('proposalConsent', true)->call('acceptProposal', 1)->assertHasErrors('proposal')
            ->assertSet('demoOrder.total', 7000000);
    }
}
