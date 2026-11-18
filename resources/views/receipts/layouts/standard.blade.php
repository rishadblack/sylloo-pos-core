{{-- Standard: logo and header, items with price × quantity (with VAT, as the price shows), totals, payments, footer. --}}
@extends('sylloo-pos-core::receipts.document', ['layout' => 'standard'])

@section('items')
    <table class="items">
        <thead>
            <tr><th>Item</th><th class="qty">Qty</th><th class="amount">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($order['items'] as $item)
                <tr><td colspan="3">{{ $loop->iteration }}. {{ $item['name'] }}</td></tr>
                <tr class="item-sub">
                    <td>&nbsp;&nbsp;@ {{ $item['amount'] }}@if (($options['show_unit'] ?? false) && filled($item['unit_name'])) / {{ $item['unit_name'] }}@endif</td>
                    <td class="qty">{{ $item['quantity'] + 0 }}</td>
                    <td class="amount">{{ numberFormat((float) $item['quantity'] * (float) $item['amount']) }}</td>
                </tr>
                @include('sylloo-pos-core::receipts.partials.item-extras')
            @endforeach
        </tbody>
    </table>
@endsection
