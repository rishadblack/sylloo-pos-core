<?php

namespace Rishadblack\SyllooPosCore\Receipts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Milon\Barcode\Facades\DNS1DFacade;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Services\SaleReturns;

/**
 * An order as the receipt template sees it ("order"). Same keys as the ERP's OrderData,
 * so one template prints in both apps.
 *
 * @mixin Order
 */
class ReceiptOrderData extends JsonResource
{
    /**
     * Barcode symbology for the order code (receipt_barcode_type setting).
     */
    protected string $barcodeType = 'C128';

    public function barcodeType(string $barcodeType): static
    {
        $this->barcodeType = $barcodeType;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serial' => $this->serial,
            'code' => $this->code,
            'barcode' => $this->barcode(),
            'items' => ReceiptOrderItemData::collection($this->orderItem)->resolve($request),
            'transactions' => ReceiptTransactionData::collection($this->transaction)->resolve($request),
            'contact' => $this->contact ? ReceiptContactData::make($this->contact)->resolve($request) : null,
            'order_by' => $this->creator?->name,
            'outlet' => null,
            'ref' => $this->ref,
            'order_date' => $this->order_date?->format('d M Y h:i A'),
            'due_date' => $this->due_date?->format('d M Y'),
            'payment_method' => $this->paymentMethod?->name,
            'subtotal' => numberFormat($this->subtotal),
            'vat' => $this->vat ? numberFormatOrPercent($this->vat, true) : null,
            'vat_amount' => numberFormat($this->vat_amount),
            'discount' => $this->discount ? numberFormatOrPercent($this->discount, true) : null,
            'discount_amount' => numberFormat($this->discount_amount),
            'shipping_charge' => numberFormat($this->shipping_charge),
            'additional_charge_details' => $this->additional_charge_details,
            'table_name' => $this->table_name,
            // Without the VAT inside it, which is in the VAT row, so the printed amounts add up to the total.
            'additional_charge' => numberFormat(max(0, round((float) $this->net_amount - (float) $this->rounding_amount - (float) $this->subtotal + (float) $this->discount_amount - (float) $this->vat_amount - (float) $this->shipping_charge, 2))),
            // Cash rounding, signed (part of the net amount).
            'rounding_amount' => numberFormat($this->rounding_amount),
            'net_amount' => numberFormat($this->net_amount),
            'paid_amount' => numberFormat($this->paid_amount),
            'due_amount' => numberFormat($this->due_amount),
            'pay_change_amount' => numberFormat($this->pay_change_amount),
            'total_qty' => (float) $this->orderItem->sum('quantity'),
            'note' => $this->note,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'delivery_status' => $this->delivery_status,
            // A return prints as a refund receipt with its sale; an exchange sale lists what came back.
            'is_return' => $this->isReturn(),
            'return_of_code' => $this->isReturn() ? $this->returnOf?->code : null,
            'refund_reason' => $this->refund_reason,
            'exchange' => $this->exchangeReturn ? [
                'code' => $this->exchangeReturn->code,
                'amount' => numberFormat(SaleReturns::exchangeCredit($this->exchangeReturn)),
                'items' => $this->exchangeReturn->orderItem->map(fn ($item) => ['name' => (string) $item->name, 'quantity' => (float) $item->quantity, 'amount' => numberFormat((float) $item->amount * (float) $item->quantity)])->all(),
            ] : null,
        ];
    }

    /**
     * Order code as a barcode image (data URI), like the ERP's OrderData.
     */
    protected function barcode(): ?string
    {
        if (! $this->code) {
            return null;
        }

        $svg = DNS1DFacade::getBarcodeSVG((string) $this->code, $this->barcodeType, 1.4, 40, 'black', false);

        return $svg ? 'data:image/svg+xml;base64,'.base64_encode($svg) : null;
    }
}
