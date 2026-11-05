<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartPayment;
use App\Ai\Runtime\ChatIdentity;
use App\Domain\Ordering\CustomerPurchases;
use App\Domain\Ordering\OrderProposal;
use App\Domain\Ordering\OrderProposalService;
use App\Domain\Ordering\PaymentOptions;
use App\Enums\DocumentType;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Document;
use Illuminate\Http\Request;

final class CustomerPurchaseController
{
    public function show(string $token, CustomerPurchases $purchases)
    {
        $order = $purchases->owned(ChatIdentity::buyer(), $token);
        $order->load(['items', 'customer', 'assignments.servicePoint']);

        return response()->view('account.purchase', ['order' => $order, 'summary' => $purchases->view($order),
            'proposal' => OrderProposal::query()->where('order_id', $order->id)->where('status', 'prepared')->latest('version')->first()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function pay(string $token, CustomerPurchases $purchases)
    {
        $buyer = ChatIdentity::buyer();
        $order = $purchases->owned($buyer, $token);
        abort_unless(array_key_exists($order->payment_method?->value ?? '', PaymentOptions::available($buyer)), 409);
        $driver = PaymentOptions::driver($order->payment_method?->value ?? '');
        abort_unless($driver, 409);
        session(['last_order_number' => $order->number]);
        $url = app(StartPayment::class)->begin($order, $driver);

        return $url ? redirect()->away($url) : back()->with('status', 'Оплата сейчас недоступна. Проверьте резерв и статус заказа.');
    }

    public function accept(Request $request, string $token, OrderProposalService $proposals)
    {
        $data = $request->validate(['proposal_id' => ['required', 'uuid'], 'consent' => ['accepted']]);
        $proposals->accept(ChatIdentity::buyer(), $token, $data['proposal_id']);

        return redirect()->route('purchase.show', $token)->with('status', app()->getLocale() === 'kk' ? 'Ұсыныс қабылданды.' : 'Предложение принято.');
    }

    public function repeat(Request $request, string $token, CustomerPurchases $purchases)
    {
        $data = $request->validate(['operation_id' => ['required', 'uuid']]);
        $draft = $purchases->repeat(ChatIdentity::buyer(), $token, 'repeat:'.$data['operation_id']);

        return redirect()->route('assistant', ['resume_draft' => $draft['id']]);
    }

    public function change(Request $request, string $token, CustomerPurchases $purchases)
    {
        $data = $request->validate(['description' => ['required', 'string', 'max:1000']]);
        $purchases->requestChange(ChatIdentity::buyer(), $token, $data['description']);

        return back()->with('status', app()->getLocale() === 'kk' ? 'Сұрау менеджерге жіберілді.' : 'Запрос передан менеджеру.');
    }

    public function document(string $token, string $type, CustomerPurchases $purchases)
    {
        $order = $purchases->owned(ChatIdentity::buyer(), $token);
        $documentType = DocumentType::tryFrom($type);
        abort_unless($documentType && $documentType->belongsToOrder() && in_array($order->purchase_state, ['confirmed', 'paid'], true), 404);
        abort_if($documentType === DocumentType::Invoice && $order->payment_method?->value !== 'invoice', 404);
        abort_if(in_array($documentType, [DocumentType::Warranty, DocumentType::HandoverAct], true) && $order->status !== OrderStatus::Completed, 404);
        $order->load(['items.product', 'customer', 'receipts', 'assignments.assignee']);
        $terms = $order->purchase_snapshot ?? [];
        $safeCustomer = new Customer(['name' => $terms['contacts']['name'] ?? '', 'phone' => $terms['contacts']['phone'] ?? '',
            'company_name' => $terms['billing']['company_name'] ?? null, 'bin' => $terms['billing']['bin'] ?? null]);
        $order->setRelation('customer', $safeCustomer);
        $document = Document::issue($documentType, $order, userId: auth()->id(), payload: ['draft_id' => $order->purchase_draft_id]);

        return response()->view($documentType->view(), ['order' => $order, 'document' => $document,
            'assignment' => $order->assignments->first(), 'address' => $order->address,
            'warrantyMonths' => settings()->warrantyMonths(), 'hasCamera' => $order->items->contains(fn ($line) => $line->product?->camera)])
            ->header('Cache-Control', 'private, no-store');
    }
}
