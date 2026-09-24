<?php

namespace App\Livewire\Preview;

use App\Livewire\Preview\Concerns\BrowsesAssistantDialogs;
use App\Livewire\Preview\Concerns\BrowsesPurchaseHistory;
use App\Livewire\Preview\Concerns\GeneratesAssistantPreview;
use App\Livewire\Preview\Concerns\ManagesCustomerPreferences;
use App\Livewire\Preview\Concerns\ManagesPurchaseTemplates;
use App\Livewire\Preview\Concerns\PreparesManagerTerms;
use App\Livewire\Preview\Concerns\RepeatsPurchaseOrders;
use App\Livewire\Preview\Concerns\RestoresPurchaseDrafts;
use App\Livewire\Preview\Concerns\ReviewsManagerProposals;
use App\Livewire\Preview\Concerns\ReviewsPurchaseLists;
use App\Livewire\Preview\Concerns\ShowsOrderSettlement;
use App\Livewire\Preview\Concerns\SignsIntoCabinet;
use App\Livewire\Preview\Concerns\UploadsPurchaseLists;
use App\Livewire\Preview\Concerns\UsesAssistantDraft;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Interactive UX fixtures only. No catalog, order, payment or inventory writes. */
class PurchaseJourney extends Component
{
    use BrowsesAssistantDialogs, BrowsesPurchaseHistory, GeneratesAssistantPreview, ManagesCustomerPreferences, ManagesPurchaseTemplates, PreparesManagerTerms, RepeatsPurchaseOrders, RestoresPurchaseDrafts, ReviewsManagerProposals, ReviewsPurchaseLists, ShowsOrderSettlement, SignsIntoCabinet, UploadsPurchaseLists, UsesAssistantDraft;

    private const SESSION_KEY = 'modernization.purchase_preview';

    public const SCENARIOS = ['ready', 'unknown_price', 'out_of_stock', 'changed', 'review', 'empty', 'save_error'];

    #[Url(as: 'view', history: true)]
    public string $screen = 'catalog';

    public string $locale = 'ru';

    #[Locked]
    public string $renderedLocale = 'ru';

    public string $mode = 'retail';

    public string $scenario = 'ready';

    /** @var array<int,int|string> Invalid input remains visible until corrected. */
    public array $cart = [];

    /** Retail and wholesale selections must never silently replace one another. */
    public array $drafts = ['retail' => [], 'wholesale' => []];

    #[Locked]
    public array $savedDrafts = [];

    public string $fulfillment = 'pickup';

    public string $name = '';

    public string $phone = '';

    public string $address = '';

    public bool $consent = false;

    public bool $accepted = false;

    public string $saveState = 'saved';

    public string $search = '';

    public string $list = '';

    public bool $showList = false;

    public bool $showChange = false;

    public string $changeText = '';

    public string $notice = '';

    #[Locked]
    public bool $editing = false;

    #[Locked]
    public ?int $editingVersion = null;

    #[Locked]
    public array $demoOrder = [
        'number' => 'DEMO-1042', 'mode' => 'retail', 'status' => 'confirmed',
        'cart' => [1 => 1], 'total' => 3500000, 'changes' => [], 'fulfillment' => 'pickup',
        'version' => 1, 'prices' => [1 => 3500000],
    ];

    public function boot(): void
    {
        abort_unless(app()->environment('preview'), 404);
    }

    public function mount(): void
    {
        $this->restoreCurrentDraft();
        $this->normalize();
        $this->mountHistory();
        $this->restoreLoginChallenge();
        $this->loadCustomerForms();
        $this->mountAssistant();
        if ($this->screen === 'account' && ! $this->signedIn()) {
            $this->loginReturnScreen = 'account';
        }
    }

    public function hydrate(): void
    {
        if ($this->historyOwner !== (new PreviewOrderBook)->owner()) {
            $this->reset('demoOrder', 'proposal', 'proposalSequence', 'proposalConsent', 'proposalReply', 'changeText', 'showChange', 'editing', 'editingVersion');
            $this->historyOwner = (new PreviewOrderBook)->owner();
            $this->useLocale();
            abort(409, __('shop.session_owner_changed'));
        }
    }

    public function exception($e, $stopPropagation): void
    {
        if ($e instanceof ValidationException) {
            $this->dispatch('preview-validation-failed');
        }
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'chat')) {
            return;
        }
        if (in_array($property, ['orderSearch', 'orderStatus', 'orderMode', 'orderPeriod'], true)) {
            $this->orderPage = 1;
        }
        if (in_array($property, ['address', 'name', 'phone'], true)) {
            $this->selectedAddressId = null;
        }
        if ($property === 'scenario') {
            if ($this->screen === 'result') {
                $this->screen = 'checkout';
            }
            if (! in_array($this->scenario, self::SCENARIOS, true)) {
                $this->scenario = 'ready';
            }
            $this->accepted = false;
            if ($this->scenario === 'empty') {
                $this->cart = [];
            }
        }
        $this->normalize();
        if (str_starts_with($property, 'cart.')) {
            $this->useLocale();
            $this->saveState = $this->invalidQuantities() ? 'invalid' : $this->saveState;
            $this->validate(['cart.*' => ['required', 'integer', 'between:1,99']], ['cart.*.*' => __('shop.fix_quantity')]);
        }
        if ($property !== 'search' && $property !== 'list' && $property !== 'changeText') {
            $this->saveDraft();
        }
        $this->resetValidation();
    }

    public function go(string $screen): void
    {
        abort_unless(in_array($screen, ['catalog', 'checkout', 'wholesale', 'drafts', 'orders', 'order', 'account', 'chat'], true), 404);
        $this->screen = $screen;
        if ($screen === 'chat' && ! $this->chatDialogId) {
            $this->mountAssistant();
        }
        if ($screen !== 'chat') {
            $this->chatCheckoutOpen = false;
        }
        if (in_array($screen, ['orders', 'account'], true) && ! $this->signedIn()) {
            $this->loginReturnScreen = $screen;
        }
        if ($screen !== 'order') {
            $this->historyOrder = '';
        }
        if ($screen === 'wholesale') {
            $this->switchMode('wholesale');
            if (! in_array($this->fulfillment, ['pickup', 'delivery'], true)) {
                $this->fulfillment = 'pickup';
            }
        }
        if ($screen === 'catalog') {
            $this->switchMode('retail');
        }
        $this->resetValidation();
        $this->saveDraft();
    }

    public function toggle(int $id): void
    {
        abort_unless(isset($this->offers()[$id]), 422);
        if (isset($this->cart[$id])) {
            unset($this->cart[$id]);
            unset($this->replacementIds[$id]);
            unset($this->assistantReviewIds[$id]);
        } else {
            $this->cart[$id] = 1;
        }
        if ($this->scenario === 'empty') {
            $this->scenario = 'ready';
        }
        $this->saveDraft();
    }

    public function saveDraft(): void
    {
        $this->normalize();
        if ($this->invalidQuantities()) {
            $this->saveState = 'invalid';

            return;
        }
        if ($this->scenario === 'save_error') {
            $this->saveState = 'error';

            return;
        }
        $this->drafts[$this->mode] = $this->cart;
        $this->rememberDraftMetadata();
        session()->put($this->draftSessionKey(), array_intersect_key(get_object_vars($this), array_flip($this->draftFields())));
        $this->saveState = 'saved';
    }

    public function retrySave(): void
    {
        $this->scenario = 'ready';
        $this->saveDraft();
    }

    public function submit(): void
    {
        if ($this->screen === 'result' || ($this->screen === 'chat' && ! $this->chatCheckoutOpen)) {
            return;
        }
        $fromChat = $this->screen === 'chat';
        if ($fromChat) {
            abort_unless(! $this->currentChatDialog()['archived'], 409);
        }
        $this->normalize();
        $this->useLocale();
        $this->restoreEditingOrder();
        $this->validate([
            'cart.*' => ['required', 'integer', 'between:1,99'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'regex:/^\+?[0-9()\s-]{10,20}$/'],
            'address' => [in_array($this->fulfillment, ['delivery', 'visit'], true) ? 'required' : 'nullable', 'string', 'max:500'],
            'consent' => ['accepted'],
        ], [
            'cart.*.*' => __('shop.fix_quantity'),
            'name.*' => __('shop.validation_name'), 'phone.*' => __('shop.validation_phone'),
            'address.*' => __('shop.validation_address'), 'consent.*' => __('shop.validation_consent'),
        ]);
        $error = match (true) {
            $this->unresolvedRows !== [] => __('shop.repeat_resolve'),
            $this->cart === [] => __('shop.empty_title'),
            $this->hasPendingImport() => __('shop.validation_import'),
            $this->saveState === 'error' => __('shop.save_failed'),
            ($this->scenario === 'changed' || $this->customerTermsChanged) && ! $this->accepted => __('shop.validation_quote'),
            $this->editing && ($this->editDraftConflict() || $this->editingVersion !== $this->demoOrder['version'] || $this->orderEditReason() !== null
                || (($stored = (new PreviewOrderBook)->find($this->demoOrder['number'])) && $stored['version'] !== $this->editingVersion)) => __('shop.proposal_conflict'),
            default => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['checkout' => $error]);
        }

        $this->proposal = $this->editing && $this->proposal ? [...$this->proposal, 'status' => 'superseded'] : null;
        $this->proposalConsent = false;
        $previousOrder = $this->demoOrder;
        $this->demoOrder = [
            'number' => $this->editing ? $this->demoOrder['number'] : (new PreviewOrderBook)->nextNumber(),
            'mode' => $this->mode,
            'status' => $this->editing ? $this->demoOrder['status'] : ($this->requiresReview() ? 'pending' : 'confirmed'),
            'cart' => $this->cart, 'total' => $this->quote()['total'], 'changes' => [], 'fulfillment' => $this->fulfillment,
            'version' => $this->editing ? $this->demoOrder['version'] + 1 : 1,
            'prices' => array_intersect_key(array_column($this->offers(), 'price', 'id'), $this->cart),
            'repeated_from' => $this->repeatedFrom,
            'fitment_review_ids' => array_intersect_key($this->assistantReviewIds, $this->cart),
            'created_in_preview' => true,
            'address' => $this->address,
        ];
        if ($this->editing) {
            $this->demoOrder = array_replace($previousOrder, $this->demoOrder, ['changes' => $previousOrder['changes']]);
        }
        $this->persistDemoOrder($this->editing ? 'history_updated_event' : 'created');
        $this->editing = false;
        $this->editContext = null;
        $this->editingVersion = null;
        $this->customerTermsChanged = false;
        $this->saveDraft();
        if ($fromChat) {
            $this->recordChatOrder();
        } else {
            $this->screen = 'result';
        }
        $this->notice = '';
    }

    public function resumeDraft(int $index): void
    {
        abort_unless(isset($this->savedDrafts[$index]), 404);
        $draft = $this->savedDrafts[$index];
        unset($this->savedDrafts[$index]);
        $this->savedDrafts = array_values($this->savedDrafts);
        $this->rememberDraftMetadata();
        if ($this->cart !== [] || $this->unresolvedRows !== []) {
            $this->savedDrafts[] = ['mode' => $this->mode, 'cart' => $this->cart, 'details' => $this->draftDetails[$this->mode]];
        }
        $this->drafts[$this->mode] = $this->cart;
        $this->cart = $draft['cart'];
        $this->mode = $draft['mode'];
        $this->restoreDraftMetadata($draft['details'] ?? []);
        $this->screen = $this->hasPendingImport() ? 'wholesale' : 'checkout';
        $this->historyOrder = '';
        $this->notice = '';
        $this->saveDraft();
    }

    public function resumeMode(string $mode): void
    {
        abort_unless(in_array($mode, ['retail', 'wholesale'], true), 422);
        $this->switchMode($mode);
        $this->screen = $this->hasPendingImport() ? 'wholesale' : 'checkout';
        $this->historyOrder = '';
        $this->saveDraft();
    }

    public function editOrder(): void
    {
        abort_unless($this->orderEditReason() === null, 403);
        abort_unless(array_diff_key($this->demoOrder['cart'], $this->offers()) === [], 403);
        $order = $this->demoOrder;
        $this->archiveDraftForMode($order['mode']);
        $this->mode = $order['mode'];
        $this->restoreDraftMetadata([]);
        $this->demoOrder = $order;
        $this->cart = $this->demoOrder['cart'];
        $this->assistantReviewIds = $this->demoOrder['fitment_review_ids'] ?? [];
        $this->mode = $this->demoOrder['mode'];
        $this->fulfillment = $this->demoOrder['fulfillment'];
        $this->editing = true;
        $this->editingVersion = $this->demoOrder['version'];
        $this->editContext = ['owner' => (new PreviewOrderBook)->owner(), 'number' => $this->demoOrder['number'], 'version' => $this->editingVersion];
        $this->address = $this->demoOrder['address'] ?? '';
        if (! (new PreviewOrderBook)->find($this->demoOrder['number'])) {
            $this->persistDemoOrder();
        }
        $this->scenario = 'ready';
        $this->screen = 'checkout';
        $this->historyOrder = '';
        $this->saveDraft();
    }

    public function setOrderState(string $state): void
    {
        abort_unless(in_array($state, ['new', 'pending', 'confirmed', 'completed'], true), 422);
        $this->demoOrder['status'] = $state;
        $this->demoOrder['version']++;
        $this->proposal = null;
        $this->proposalConsent = false;
        $this->showChange = false;
        $this->persistDemoOrder('history_status_event');
    }

    public function submitChange(): void
    {
        abort_unless($this->canRequestChange(), 403);
        $this->useLocale();
        $this->validate(['changeText' => ['required', 'string', 'min:3', 'max:1000']], ['changeText.*' => __('shop.validation_change')]);
        $this->demoOrder['changes'][] = $this->changeText;
        $this->showChange = false;
        $this->notice = 'change_sent';
        $this->persistDemoOrder('change_sent');
    }

    public function restart(): void
    {
        if ($this->purchaseFile) {
            $this->purchaseFile->delete();
        }
        session()->forget(self::SESSION_KEY);
        session()->forget(['modernization.preview_identity', 'modernization.preview_challenge', 'modernization.preview_orders', 'modernization.preview_sequence', 'modernization.preview_sms', 'modernization.preview_sms_sequence', 'modernization.preview_customers', 'modernization.preview_conversations']);
        $this->reset();
    }

    public function offers(?string $mode = null, ?string $scenario = null): array
    {
        $mode ??= $this->mode;
        $scenario ??= $this->scenario;
        $offers = [
            1 => ['id' => 1, 'article' => 'DEMO-1', 'code' => 'DEMO-CAMRY', 'title' => 'windshield', 'manufacturer' => 'XYG', 'price' => $scenario === 'unknown_price' ? null : ($scenario === 'changed' ? 3650000 : 3500000), 'stock' => $scenario === 'out_of_stock' ? 0 : 6, 'verified' => $scenario !== 'review'],
            2 => ['id' => 2, 'article' => 'DEMO-2', 'title' => 'camera_glass', 'manufacturer' => 'XYG', 'price' => 4800000, 'stock' => 2, 'verified' => false],
            3 => ['id' => 3, 'article' => 'DEMO-3', 'title' => 'rear_glass', 'manufacturer' => 'XYG', 'price' => 4200000, 'stock' => 0, 'verified' => true],
            4 => ['id' => 4, 'article' => 'DEMO-4', 'code' => 'DEMO-CAMRY', 'title' => 'windshield', 'manufacturer' => 'FYG', 'price' => 3900000, 'stock' => 5, 'verified' => true],
        ];
        if ($mode === 'wholesale' && $this->signedIn() && $this->customerData()['wholesale']['status'] === 'approved') {
            $floors = [1 => 3200000, 2 => 4600000, 3 => 3700000, 4 => 3600000];
            foreach ($offers as $id => &$offer) {
                if ($offer['price'] !== null) {
                    $offer['price'] = max($floors[$id], intdiv($offer['price'] * 90 + 50, 100));
                }
            }
            unset($offer);
        }

        return $offers;
    }

    public function quote(): array
    {
        $known = 0;
        $unknown = count($this->unresolvedRows);
        foreach ($this->cart as $id => $quantity) {
            if (! $this->validQuantity($quantity)) {
                continue;
            }
            $price = $this->offers()[$id]['price'];
            $price === null ? $unknown++ : $known += $price * $quantity;
        }
        $units = array_sum(array_filter($this->cart, fn ($quantity) => $this->validQuantity($quantity)));
        $services = in_array($this->fulfillment, ['installation', 'visit'], true) ? 1000000 * $units : 0;
        $delivery = match ($this->fulfillment) {
            'delivery' => 300000, 'visit' => 450000, default => 0
        };

        $invalid = $this->invalidQuantities();

        return ['positions' => count($this->cart) + count($this->unresolvedRows), 'units' => $units + array_sum(array_column($this->unresolvedRows, 'quantity')), 'goods' => $unknown || $invalid ? null : $known, 'known' => $known + $services + $delivery, 'unknown' => $unknown, 'invalid' => $invalid, 'services' => $services, 'delivery' => $delivery, 'total' => $unknown || $invalid ? null : $known + $services + $delivery];
    }

    public function requiresReview(): bool
    {
        if ($this->mode === 'wholesale' || $this->fulfillment === 'visit' || $this->replacementIds !== [] || array_intersect_key($this->assistantReviewIds, $this->cart)) {
            return true;
        }
        foreach ($this->cart as $id => $quantity) {
            $offer = $this->offers()[$id];
            if ($offer['price'] === null || ! $offer['verified'] || $offer['stock'] < $quantity) {
                return true;
            }
        }

        return false;
    }

    private function normalize(): void
    {
        if (! in_array($this->screen, ['catalog', 'checkout', 'wholesale', 'orders', 'order', 'drafts', 'result', 'account', 'chat'], true)) {
            $this->screen = 'catalog';
        }
        $this->cart = array_intersect_key($this->cart, $this->offers());
        $this->assistantReviewIds = array_intersect_key($this->assistantReviewIds, $this->cart);
        foreach ($this->cart as &$quantity) {
            $quantity = $this->validQuantity($quantity) ? (int) $quantity : (is_scalar($quantity) ? $quantity : '');
        }
        $this->locale = in_array($this->locale, ['ru', 'kk'], true) ? $this->locale : 'ru';
        $this->mode = $this->mode === 'wholesale' ? 'wholesale' : 'retail';
        if (! in_array($this->fulfillment, ['pickup', 'delivery', 'installation', 'visit'], true)
            || ($this->mode === 'wholesale' && ! in_array($this->fulfillment, ['pickup', 'delivery'], true))) {
            $this->fulfillment = 'pickup';
        }
    }

    private function validQuantity(mixed $quantity): bool
    {
        return is_scalar($quantity) && filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]) !== false;
    }

    private function invalidQuantities(): bool
    {
        return count(array_filter($this->cart, fn ($quantity) => ! $this->validQuantity($quantity))) > 0;
    }

    private function switchMode(string $mode): void
    {
        if ($this->mode === $mode) {
            return;
        }
        $this->drafts[$this->mode] = $this->cart;
        $this->rememberDraftMetadata();
        $this->cart = $this->drafts[$mode] ?? [];
        $this->mode = $mode;
        $this->restoreDraftMetadata($this->draftDetails[$mode] ?? []);
        $this->normalize();
    }

    private function useLocale(): void
    {
        app()->setLocale(in_array($this->locale, ['ru', 'kk'], true) ? $this->locale : 'ru');
        $this->renderedLocale = app()->getLocale();
    }

    public function render(): View
    {
        $this->normalize();
        $this->useLocale();

        if ($this->screen === 'checkout' || ($this->screen === 'chat' && $this->chatCheckoutOpen)) {
            $this->restoreEditingOrder();
        }

        return view('livewire.preview.purchase-journey', [
            'offers' => $this->offers(), 'quote' => $this->quote(), 'needsReview' => $this->requiresReview(),
        ]);
    }
}
