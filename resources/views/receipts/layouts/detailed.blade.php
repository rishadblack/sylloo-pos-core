{{-- Detailed: item code and unit under each item, VAT per line, serial numbers and warranty (electronics, pharmacy).
     A line total is the price with VAT × quantity, like the price it shows. --}}
@extends('sylloo-pos-core::receipts.document', ['layout' => 'detailed'])

@section('items')
    <table class="items">
        <thead>
            <tr><th>Item</th><th class="qty">Qty</th><th class="amount">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($order['items'] as $item)
                <tr><td colspan="3">{{ $loop->iteration }}. {{ $item['name'] }}</td></tr>
                @if (filled($item['code']))
                    <tr class="item-sub"><td colspan="3">&nbsp;&nbsp;Code: {{ $item['code'] }}</td></tr>
                @endif
                <tr class="item-sub">
                    <td>&nbsp;&nbsp;@ {{ $item['amount'] }}@if (filled($item['unit_name'])) / {{ $item['unit_name'] }}@endif</td>
                    <td class="qty">{{ $item['quantity'] + 0 }}</td>
                    <td class="amount">{{ numberFormat((float) $item['quantity'] * (float) $item['amount']) }}</td>
                </tr>
                @if ((float) $item['vat_amount'] > 0)
                    <tr class="item-sub">
                        <td colspan="2">&nbsp;&nbsp;incl. VAT{{ filled($item['vat']) ? ' '.$item['vat'] : '' }}</td>
                        <td class="amount">{{ $item['vat_amount'] }}</td>
                    </tr>
                @endif
                @include('sylloo-pos-core::receipts.partials.item-extras', ['alwaysSerials' => true])
            @endforeach
        </tbody>
    </table>
@endsection
