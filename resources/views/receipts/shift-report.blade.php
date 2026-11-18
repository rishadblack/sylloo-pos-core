{{--
    The X report (a shift's figures so far) or the Z report (the shift closed with its cash count), on the receipt
    printer (ReceiptPrintService::renderShiftReport). Every value is escaped.
--}}
@php
    $narrow = $paperWidth === 58;
    $content = $narrow ? 48 : 72;
    $fontSize = $narrow ? 10 : 11.5;
    $money = fn ($value) => numberFormat($value, true);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $final ? 'Z' : 'X' }} report {{ $shift->number }}</title>
    <style>
        @page { size: {{ $paperWidth }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; }
        body {
            width: {{ $content }}mm;
            margin: 0 auto;
            padding: 3mm 0 5mm;
            color: #000;
            font-family: "Courier New", Courier, monospace;
            font-size: {{ $fontSize }}px;
            line-height: 1.35;
        }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .muted { font-size: 0.9em; }
        .shop-name { font-size: 1.3em; font-weight: bold; }
        .title { margin: 2mm 0 1mm; font-size: 1.15em; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .rule { margin: 1.5mm 0; border: 0; border-top: 1px dashed #000; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 0.4mm 0; vertical-align: top; }
        td:last-child { text-align: right; white-space: nowrap; }
        .grand td { padding: 1mm 0; font-size: 1.15em; font-weight: bold; }
    </style>
</head>
<body>
    <div class="center">
        @if (filled($shop['name'] ?? null))
            <div class="shop-name">{{ $shop['name'] }}</div>
        @endif
        @if (filled($shop['outlet_name'] ?? null) && ($shop['outlet_name'] ?? null) !== ($shop['name'] ?? null))
            <div class="muted">{{ $shop['outlet_name'] }}</div>
        @endif
        <div class="title">{{ $final ? 'Z report · shift closed' : 'X report · shift so far' }}</div>
    </div>

    <table>
        <tr><td>Shift no.</td><td>{{ $shift->number }}</td></tr>
        <tr><td>Cashier</td><td>{{ $cashier }}</td></tr>
        <tr><td>Opened</td><td>{{ $shift->opened_at?->format('d M Y h:i A') }}</td></tr>
        <tr><td>{{ $final ? 'Closed' : 'Printed' }}</td><td>{{ ($final ? $shift->closed_at : now())?->format('d M Y h:i A') }}</td></tr>
    </table>

    <hr class="rule">
    <table>
        <tr><td>Sales</td><td>{{ $summary['sales_count'] }}</td></tr>
        <tr class="bold"><td>Sales total</td><td>{{ $money($summary['sales_total']) }}</td></tr>
        @if (! empty($summary['open_tables_count']))
            <tr><td>Open tables: {{ $summary['open_tables_count'] }}</td><td>{{ $money($summary['open_tables_amount']) }}</td></tr>
        @endif
        <tr><td>Discounts</td><td>{{ $money($summary['discounts']) }}</td></tr>
        <tr><td>VAT</td><td>{{ $money($summary['vat']) }}</td></tr>
        <tr><td>Left due</td><td>{{ $money($summary['due']) }}</td></tr>
    </table>

    <hr class="rule">
    <div class="bold">Payments</div>
    <table>
        @forelse ($summary['payments'] as $payment)
            <tr><td>{{ $payment['method'] }}</td><td>{{ $money($payment['amount']) }}</td></tr>
        @empty
            <tr><td>No payments</td><td></td></tr>
        @endforelse
        @if (($summary['change_given'] ?? 0) > 0)
            <tr><td>− Change given</td><td>{{ $money($summary['change_given']) }}</td></tr>
            <tr class="bold"><td>Kept</td><td>{{ $money(collect($summary['payments'])->sum('amount') - $summary['change_given']) }}</td></tr>
        @endif
    </table>

    @if (($summary['refunds_count'] ?? 0) > 0)
        <hr class="rule">
        <div class="bold">Refunds</div>
        <table>
            <tr><td>Returns</td><td>{{ $summary['refunds_count'] }} · {{ $money($summary['refunds_total']) }}</td></tr>
            @foreach ($summary['refunds'] as $refund)
                <tr><td>{{ $refund['method'] }}</td><td>{{ $money($refund['amount']) }}</td></tr>
            @endforeach
            @if (($summary['store_credit'] ?? 0) > 0)
                <tr><td>Store credit</td><td>{{ $money($summary['store_credit']) }}</td></tr>
            @endif
        </table>
    @endif

    @if (($summary['due_collections_count'] ?? 0) > 0)
        <hr class="rule">
        <div class="bold">Old dues collected</div>
        <table>
            <tr><td>Payments</td><td>{{ $summary['due_collections_count'] }} · {{ $money($summary['due_collected']) }}</td></tr>
            @foreach ($summary['collections'] ?? [] as $collection)
                <tr><td>{{ $collection['method'] }}</td><td>{{ $money($collection['amount']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <hr class="rule">
    <div class="bold">Cash drawer</div>
    <table>
        <tr><td>Opening float</td><td>{{ $money($summary['opening_float']) }}</td></tr>
        <tr><td>+ Cash taken</td><td>{{ $money($summary['cash_received']) }}</td></tr>
        <tr><td>− Change given</td><td>{{ $money($summary['change_given']) }}</td></tr>
        <tr><td>− Cash refunded</td><td>{{ $money($summary['cash_refunded'] ?? 0) }}</td></tr>
        @if (($summary['cash_collected'] ?? 0) > 0)
            <tr><td>+ Cash for old dues</td><td>{{ $money($summary['cash_collected']) }}</td></tr>
        @endif
        <tr><td>+ Cash in</td><td>{{ $money($summary['cash_in']) }}</td></tr>
        <tr><td>− Cash out</td><td>{{ $money($summary['cash_out']) }}</td></tr>
        <tr class="grand"><td>Expected cash</td><td>{{ $money($summary['expected_cash']) }}</td></tr>
        @if (($summary['waiting_count'] ?? 0) > 0)
            <tr><td>Waiting for checking ({{ $summary['waiting_count'] }}), in the drawer</td><td>{{ $money($summary['waiting_amount']) }}</td></tr>
        @endif
        @if ($final)
            <tr class="bold"><td>Counted cash</td><td>{{ $money($shift->counted_cash) }}</td></tr>
            <tr class="bold"><td>{{ $shift->difference < 0 ? 'Short' : ($shift->difference > 0 ? 'Over' : 'Difference') }}</td><td>{{ $money(abs((float) $shift->difference)) }}</td></tr>
            @if ($shift->hasHandover())
                {{-- Cash handed over to the main office at the close (ShiftRegister::close); the rest stays in the drawer. --}}
                <tr><td>− Handed over to main office{{ filled($shift->handover_account) ? ' ('.$shift->handover_account.')' : '' }}</td><td>{{ $money($shift->handover_amount) }}</td></tr>
                <tr class="bold"><td>Closing float</td><td>{{ $money($shift->closingFloat()) }}</td></tr>
            @endif
        @endif
    </table>

    @if ($movements->isNotEmpty())
        <hr class="rule">
        <div class="bold">Cash in and out</div>
        <table>
            @foreach ($movements as $movement)
                <tr>
                    <td>{{ $movement->created_at?->format('h:i A') }} {{ $movement->type === 'in' ? 'In' : 'Out' }}{{ filled($movement->reason) ? ' · '.$movement->reason : '' }}</td>
                    <td>{{ $money($movement->amount) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <hr class="rule">
    <table>
        <tr><td>Removed lines</td><td>{{ $summary['voids'] }}</td></tr>
        <tr><td>Cleared orders</td><td>{{ $summary['cleared_orders'] }}</td></tr>
        <tr><td>Price changes</td><td>{{ $summary['price_changes'] }}</td></tr>
        <tr><td>Drawer opened</td><td>{{ $summary['drawer_opens'] }}</td></tr>
    </table>

    @if ($final && filled($shift->close_note))
        <hr class="rule">
        <div class="muted">{{ $shift->close_note }}</div>
    @endif

    @if ($final)
        <div class="center muted" style="margin-top: 6mm">Signature ____________________</div>
    @endif
</body>
</html>
