<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\Assignment;
use App\Models\Document;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Печатные документы.
 *
 * В документах телефон клиента, адрес и суммы, а номер заказа в адресе
 * подбирается перебором. Поэтому одной аутентификации мало: каждый документ
 * проверяется политикой — менеджер видит все заявки, курьер только свои.
 */
class DocumentController extends Controller
{
    /** Документ по заказу: товарный чек, гарантия, акт, счёт. */
    public function order(Request $request, Order $order, string $type): View
    {
        $documentType = DocumentType::tryFrom($type);

        abort_if($documentType === null || ! $documentType->belongsToOrder(), 404);

        Gate::authorize('printDocuments', $order);

        $order->load(['items', 'customer', 'receipts', 'assignments.assignee']);

        $assignment = $order->assignments->sortBy('scheduled_at')->first();

        $document = Document::issue(
            type: $documentType,
            order: $order,
            userId: $request->user()?->id,
            payload: ['total' => $order->total_amount, 'items' => $order->items->count()],
        );

        return view($documentType->view(), [
            'document' => $document,
            'order' => $order,
            'assignment' => $assignment,
            'address' => $assignment?->address ?: $order->address,
            'warrantyMonths' => $this->warrantyMonths($order),
            'hasCamera' => $order->items->contains(fn ($item) => $item->product?->camera),
        ]);
    }

    /** Реестр отправлений: привязан к курьеру и дню, а не к заказу. */
    public function manifest(Request $request, User $courier): View
    {
        Gate::authorize('printManifest', $courier);

        $day = $request->date('day') ?? today();

        $assignments = Assignment::forDay($courier->id, $day)
            ->with(['order.customer', 'order.items', 'order.payments', 'servicePoint'])
            ->get();

        // Номер реестра свой на каждую смену: один курьер, один день.
        $document = Document::firstOrCreate(
            [
                'type' => DocumentType::CourierManifest,
                'assignment_id' => $assignments->first()?->id,
                'issued_on' => $day->toDateString(),
            ],
            [
                'number' => Document::nextNumber(DocumentType::CourierManifest),
                'issued_by' => $request->user()?->id,
                'payload' => ['courier_id' => $courier->id, 'count' => $assignments->count()],
            ]
        );

        return view(DocumentType::CourierManifest->view(), [
            'document' => $document,
            'courier' => $courier,
            'day' => $day,
            'assignments' => $assignments,
        ]);
    }

    /**
     * Срок гарантии берём из предложения поставщика: у премиальных стёкол
     * он больше, и обещать всем одинаковый год — значит либо занижать,
     * либо обещать лишнее.
     */
    private function warrantyMonths(Order $order): int
    {
        $fromOffers = $order->items
            ->map(fn ($item) => $item->offer?->warranty_months)
            ->filter()
            ->min();

        return (int) ($fromOffers ?: settings()->warrantyMonths());
    }
}
