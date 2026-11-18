{{--
    The receipt of old dues a customer paid without a sale (DueCollections, ReceiptPrintService::render), in the style
    of the X/Z report: the shop, the customer, each payment and what they owe now. Every value is escaped.
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
    <title>Payment {{ $order->code }}</title>
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
        .logo { max-width: 60%; max-height: 22mm; margin-bottom: 1mm; }
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
        @if (($options['show_logo'] ?? false) && filled($shop['logo'] ?? null))
            <img class="logo" src="{{ $shop['logo'] }}" alt="{{ $shop['name'] ?? 'Logo' }}">
        @endif
        @if (filled($shop['name'] ?? null))
            <div class="shop-name">{{ $shop['name'] }}</div>
        @endif
        @if (filled($shop['outlet_name'] ?? null) && ($shop['outlet_name'] ?? null) !== ($shop['name'] ?? null))
            <div class="muted">{{ $shop['outlet_name'] }}</div>
        @endif
        @if (($options['show_address'] ?? false) && filled($shop['address'] ?? null))
            <div class="muted">{{ $shop['address'] }}</div>
        @endif
        @if (($options['show_contact'] ?? false) && filled($shop['mobile'] ?? $shop['phone'] ?? null))
            <div class="muted">Tel: {{ $shop['mobile'] ?? $shop['phone'] }}</div>
        @endif
        <div class="title">Payment received</div>
        @if ($reprint)
            <div class="muted">Copy</div>
        @endif
    </div>

    <table>
        <tr><td>Receipt no.</td><td>{{ $order->code }}</td></tr>
        <tr><td>Date</td><td>{{ $order->order_date?->format('d M Y h:i A') }}</td></tr>
        <tr><td>Customer</td><td>{{ $order->contact?->name }}</td></tr>
        @if (filled($order->contact?->mobile))
            <tr><td>Mobile</td><td>{{ $order->contact->mobile }}</td></tr>
        @endif
        @if (filled($order->creator?->name))
            <tr><td>Cashier</td><td>{{ $order->creator->name }}</td></tr>
        @endif
    </table>

    <hr class="rule">
    <div class="bold">For what you owed</div>
    <table>
        @foreach ($order->transaction as $payment)
            <tr>
                <td>{{ $payment->paymentMethod?->name ?? 'Payment' }}{{ filled($payment->card_last4) ? ' ****'.$payment->card_last4 : '' }}{{ filled($payment->ref) ? ' · '.$payment->ref : '' }}</td>
                <td>{{ $money($payment->amount) }}</td>
            </tr>
        @endforeach
        <tr class="grand"><td>Paid</td><td>{{ $money($order->net_amount) }}</td></tr>
        @if ($owedNow !== null)
            <tr class="bold"><td>{{ $owedNow < 0 ? 'Credit kept for you' : 'You still owe' }}</td><td>{{ $money(abs($owedNow)) }}</td></tr>
        @endif
    </table>
    @if ($owedNow !== null)
        <div class="muted">Your oldest unpaid bills are paid first.</div>
    @endif

    @if (filled($order->note))
        <hr class="rule">
        <div class="muted">{{ $order->note }}</div>
    @endif

    @if ($footerLines !== [])
        <hr class="rule">
        @foreach ($footerLines as $line)
            <div class="center muted">{{ $line }}</div>
        @endforeach
    @endif
</body>
</html>
