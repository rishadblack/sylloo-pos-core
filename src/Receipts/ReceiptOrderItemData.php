<?php

namespace Rishadblack\SyllooPosCore\Receipts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Rishadblack\SyllooPosCore\Models\OrderItem;

/**
 * An order line on the receipt ("order.items"). Same keys as the ERP's OrderItemData.
 *
 * @mixin OrderItem
 */
class ReceiptOrderItemData extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            // An add-on of a restaurant's dish prints under it.
            'name' => (filled($this->parent_item_id) ? '+ ' : '').($this->name ?: $this->product?->name),
            'code' => $this->product?->code,
            'amount' => numberFormat($this->amount),
            'quantity' => numberFormat($this->quantity),
            // The unit the line was sold in when it is not the item's own unit ("Box"), else ''.
            'unit_name' => $this->otherUnitName(),
            'vat' => $this->vat ? numberFormatOrPercent($this->vat, true) : null,
            'vat_amount' => numberFormat($this->vat_amount),
            'discount' => $this->discount ? numberFormatOrPercent($this->discount, true) : null,
            'discount_amount' => numberFormat($this->lineDiscount()),
            'subtotal' => numberFormat($this->subtotal),
            // The serial numbers sold on the line and the warranty each unit gets (same keys as the ERP).
            'serials' => array_values(array_map('strval', (array) $this->serial_tracking)),
            'warranty' => $this->product?->warrantyLabel() ?? '',
            'warranty_until' => $this->product?->warrantyUntil($this->order_item_date ?? $this->created_at ?? now())?->format('d-M-Y'),
            'is_return' => $this->is_return,
            'status' => $this->status,
        ];
    }

    /**
     * The line's discount with VAT: the stored one, else what a line discount or lower price made at the till took
     * off (`price_change`: the price before and after, per unit).
     */
    protected function lineDiscount(): float
    {
        if ((float) $this->discount_amount > 0) {
            return (float) $this->discount_amount;
        }

        $change = is_array($this->price_change) ? $this->price_change : null;

        if (! $change || ! is_numeric($change['server_with_vat'] ?? null) || ! is_numeric($change['unit_with_vat'] ?? null)) {
            return 0.0;
        }

        return round(max(0.0, (float) $change['server_with_vat'] - (float) $change['unit_with_vat']) * (float) $this->quantity, 2);
    }
}
