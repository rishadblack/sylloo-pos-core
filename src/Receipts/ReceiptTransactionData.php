<?php

namespace Rishadblack\SyllooPosCore\Receipts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Rishadblack\SyllooPosCore\Models\Transaction;

/**
 * A payment on the receipt ("order.transactions"). Same keys as the ERP's TransactionData.
 *
 * @mixin Transaction
 */
class ReceiptTransactionData extends JsonResource
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
            'date' => $this->transaction_date?->format('d M Y h:i A'),
            'code' => $this->code,
            'flow' => $this->flow,
            'payment_method' => $this->store_credit ? 'Store credit' : $this->paymentMethod?->name,
            'amount' => numberFormat($this->amount),
            'charge' => $this->charge ? numberFormatOrPercent($this->charge, true) : null,
            'charge_amount' => numberFormat($this->charge_amount),
            'pay_change_amount' => numberFormat($this->pay_change_amount),
            'net_amount' => numberFormat($this->net_amount),
            'note' => $this->note,
            'status' => $this->status,
        ];
    }
}
