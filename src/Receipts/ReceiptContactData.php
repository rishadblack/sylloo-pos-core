<?php

namespace Rishadblack\SyllooPosCore\Receipts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Rishadblack\SyllooPosCore\Models\Contact;

/**
 * The customer on the receipt ("order.contact"). Same keys as the ERP's ContactData.
 *
 * @mixin Contact
 */
class ReceiptContactData extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'business_name' => $this->business_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'address' => $this->address,
            'is_walk_in' => (bool) $this->is_walk_in,
        ];
    }
}
