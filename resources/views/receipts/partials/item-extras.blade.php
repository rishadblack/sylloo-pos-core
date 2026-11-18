{{-- Under an item: its code, discount, VAT, serial numbers and warranty end, as the options ask. --}}
@php($alwaysSerials = $alwaysSerials ?? false)
@if (! $alwaysSerials && ($options['show_code'] ?? false) && filled($item['code']))
    <tr class="item-sub"><td colspan="3">&nbsp;&nbsp;Code: {{ $item['code'] }}</td></tr>
@endif
@if (($options['show_line_discount'] ?? false) && (float) $item['discount_amount'] > 0)
    <tr class="item-sub"><td colspan="2">&nbsp;&nbsp;Discount</td><td class="amount">-{{ $item['discount_amount'] }}</td></tr>
@endif
@if (! $alwaysSerials && ($options['show_line_vat'] ?? false) && (float) $item['vat_amount'] > 0)
    <tr class="item-sub"><td colspan="2">&nbsp;&nbsp;incl. VAT</td><td class="amount">{{ $item['vat_amount'] }}</td></tr>
@endif
@if (($alwaysSerials || ($options['show_serials'] ?? false)) && ! empty($item['serials']))
    <tr class="item-sub"><td colspan="3">&nbsp;&nbsp;S/N: {{ implode(', ', $item['serials']) }}</td></tr>
    @if (($alwaysSerials || ($options['show_warranty'] ?? false)) && filled($item['warranty_until']))
        <tr class="item-sub"><td colspan="3">&nbsp;&nbsp;Warranty until {{ $item['warranty_until'] }}</td></tr>
    @endif
@endif
