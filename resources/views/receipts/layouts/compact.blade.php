{{-- Compact: no logo, one line per item, the fewest lines (58 mm rolls, busy counters). --}}
@extends('sylloo-pos-core::receipts.document', ['layout' => 'compact'])

@section('items')
    <table class="items">
        <tbody>
            @foreach ($order['items'] as $item)
                <tr>
                    <td>{{ $item['quantity'] + 0 }} × {{ $item['name'] }}</td>
                    <td class="amount">{{ numberFormat((float) $item['quantity'] * (float) $item['amount']) }}</td>
                </tr>
                @if (($options['show_serials'] ?? false) && ! empty($item['serials']))
                    <tr class="item-sub"><td colspan="2">&nbsp;&nbsp;S/N: {{ implode(', ', $item['serials']) }}</td></tr>
                @endif
            @endforeach
        </tbody>
    </table>
@endsection
