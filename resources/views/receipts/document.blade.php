{{--
    The receipt shared by every layout (ReceiptPrintService::LAYOUTS): header, sale details, totals, payments and
    footer. A layout fills in `items` and may hide the logo (`compact`). Every value is escaped: names and notes come
    from users. Thermal printers can't print to the paper's edges: 58 mm rolls print about 48 mm, 80 mm about 72 mm.
--}}
@php
    $narrow = $paperWidth === 58;
    $content = $narrow ? 48 : 72;
    $fontSize = ($narrow ? 10 : 11.5) * ($options['font_size'] === 'large' ? 1.2 : 1);
    $showLogo = ($options['show_logo'] ?? false) && ($layout ?? 'standard') !== 'compact' && filled($shop['logo'] ?? null);
    $contact = $order['contact'] ?? null;
    $isWalkIn = ! $contact || ! empty($contact['is_walk_in']);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt {{ $order['code'] }}</title>
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
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .muted { font-size: 0.9em; }
        .logo { display: block; max-width: 60%; max-height: 18mm; margin: 0 auto 2mm; }
        .shop-name { font-size: 1.45em; font-weight: bold; letter-spacing: 0.5px; }
        .title { margin: 2mm 0 1mm; font-size: 1.1em; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .reprint { margin: 1mm 0; padding: 0.5mm 0; border: 1px solid #000; font-weight: bold; letter-spacing: 2px; }
        .rule { margin: 1.5mm 0; border: 0; border-top: 1px dashed #000; }
        .rule-double { margin: 1.5mm 0; border: 0; border-top: 3px double #000; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 0.4mm 0; vertical-align: top; }
        th { font-weight: bold; text-align: left; }
        .meta td:first-child { width: 38%; white-space: nowrap; }
        .items .qty { width: {{ $narrow ? 18 : 16 }}%; text-align: center; }
        .items .amount { width: {{ $narrow ? 30 : 26 }}%; text-align: right; white-space: nowrap; }
        .items .item-sub { font-size: 0.88em; }
        .totals td:last-child { text-align: right; white-space: nowrap; }
        .grand td { padding: 1mm 0; font-size: 1.25em; font-weight: bold; }
        .words { margin-top: 1mm; font-size: 0.85em; text-transform: capitalize; }
        .barcode { display: block; max-width: 90%; height: 12mm; margin: 2mm auto 0; }
        .footer { margin-top: 2mm; }
    </style>
</head>
<body>
    <div class="center">
        @if ($showLogo)
            <img class="logo" src="{{ $shop['logo'] }}" alt="">
        @endif
        @if (filled($shop['name'] ?? null))
            <div class="shop-name">{{ $shop['name'] }}</div>
        @endif
        @if (($options['show_slogan'] ?? false) && filled($shop['sologan'] ?? null))
            <div class="muted">{{ $shop['sologan'] }}</div>
        @endif
        @if (($options['show_outlet_name'] ?? false) && filled($shop['outlet_name'] ?? null) && ($shop['outlet_name'] ?? null) !== ($shop['name'] ?? null))
            <div class="muted">{{ $shop['outlet_name'] }}</div>
        @endif
        @if (($options['show_address'] ?? false) && filled($shop['address'] ?? null))
            <div class="muted">{{ $shop['address'] }}</div>
        @endif
        @if (($options['show_contact'] ?? false) && filled($shop['mobile'] ?? $shop['phone'] ?? null))
            <div class="muted">Tel: {{ $shop['mobile'] ?? $shop['phone'] }}</div>
        @endif
        @if (($options['show_email'] ?? false) && filled($shop['email'] ?? null))
            <div class="muted">{{ $shop['email'] }}</div>
        @endif
        @if (($options['show_website'] ?? false) && filled($shop['website_address'] ?? null))
            <div class="muted">{{ $shop['website_address'] }}</div>
        @endif
        @if (($options['show_vat_no'] ?? false) && filled($shop['vat_reg_no'] ?? null))
            <div class="muted">VAT/BIN: {{ $shop['vat_reg_no'] }}</div>
        @endif
        @if (filled($options['header_text'] ?? null))
            <div class="muted bold">{{ $options['header_text'] }}</div>
        @endif
        <div class="title">{{ ($order['is_return'] ?? false) ? 'Refund Receipt' : 'Sales Receipt' }}</div>
        @if ($reprint)
            <div class="reprint">REPRINT</div>
        @endif
    </div>

    <hr class="rule">

    <table class="meta">
        <tr><td>Receipt No</td><td>: <span class="bold">{{ $order['code'] }}</span></td></tr>
        <tr><td>Date</td><td>: {{ $order['order_date'] }}</td></tr>
        @if (filled($order['table_name'] ?? null))
            <tr><td>Table</td><td>: {{ $order['table_name'] }}</td></tr>
        @endif
        @if (filled($order['return_of_code'] ?? null))
            <tr><td>Return of</td><td>: {{ $order['return_of_code'] }}</td></tr>
        @endif
        @if (($order['is_return'] ?? false) && filled($order['refund_reason'] ?? null))
            <tr><td>Reason</td><td>: {{ $order['refund_reason'] }}</td></tr>
        @endif
        @if (($options['show_cashier'] ?? false) && filled($order['order_by'] ?? null))
            <tr><td>Cashier</td><td>: {{ $order['order_by'] }}</td></tr>
        @endif
        @if (($options['show_customer'] ?? false) && ! $isWalkIn)
            <tr><td>Customer</td><td>: {{ $contact['name'] }}</td></tr>
            @if (filled($contact['mobile'] ?? null))
                <tr><td>Mobile</td><td>: {{ $contact['mobile'] }}</td></tr>
            @endif
        @endif
    </table>

    <hr class="rule">

    @yield('items')

    <hr class="rule">

    <table class="totals">
        <tr>
            <td>Subtotal{{ (float) $order['vat_amount'] > 0 ? ' excl. VAT' : '' }} ({{ $order['total_qty'] + 0 }} {{ $order['total_qty'] == 1 ? 'item' : 'items' }})</td>
            <td>{{ $order['subtotal'] }}</td>
        </tr>
        @if ((float) $order['discount_amount'] > 0)
            <tr><td>Discount</td><td>-{{ $order['discount_amount'] }}</td></tr>
        @endif
        @if ((float) $order['vat_amount'] > 0)
            <tr><td>VAT</td><td>{{ $order['vat_amount'] }}</td></tr>
        @endif
        @if ((float) $order['shipping_charge'] > 0)
            <tr><td>Delivery charge</td><td>{{ $order['shipping_charge'] }}</td></tr>
        @endif
        @if ((float) $order['additional_charge'] > 0)
            <tr><td>{{ $order['additional_charge_details'] ?: 'Additional charge' }}</td><td>{{ $order['additional_charge'] }}</td></tr>
        @endif
        @if ((float) ($order['rounding_amount'] ?? 0) != 0)
            <tr><td>Rounding</td><td>{{ $order['rounding_amount'] }}</td></tr>
        @endif
    </table>

    @if (! empty($order['exchange']))
        <hr class="rule">
        <div class="bold">Exchange: returned ({{ $order['exchange']['code'] }})</div>
        <table class="totals">
            @foreach ($order['exchange']['items'] as $returned)
                <tr><td>{{ $returned['name'] }} × {{ $returned['quantity'] + 0 }}</td><td>-{{ $returned['amount'] }}</td></tr>
            @endforeach
        </table>
    @endif

    <hr class="rule-double">

    <table class="totals grand">
        <tr><td>{{ ($order['is_return'] ?? false) ? 'REFUND' : 'TOTAL' }}</td><td>{{ numberFormat($order['net_amount'], true) }}</td></tr>
    </table>

    <hr class="rule">

    <table class="totals">
        @foreach ($order['transactions'] as $transaction)
            <tr><td>{{ $transaction['payment_method'] ?: 'Payment' }}</td><td>{{ $transaction['amount'] }}</td></tr>
        @endforeach
        <tr class="bold"><td>{{ ($order['is_return'] ?? false) ? 'Refunded' : 'Paid' }}</td><td>{{ $order['paid_amount'] }}</td></tr>
        @if ((float) $order['pay_change_amount'] > 0)
            <tr><td>Change</td><td>{{ $order['pay_change_amount'] }}</td></tr>
        @endif
        @if ((float) $order['due_amount'] > 0)
            <tr class="bold"><td>{{ ($order['is_return'] ?? false) ? 'Off the amount owed' : 'Due' }}</td><td>{{ $order['due_amount'] }}</td></tr>
            @if (filled($order['due_date'] ?? null))
                <tr><td>Due date</td><td>{{ $order['due_date'] }}</td></tr>
            @endif
        @endif
    </table>

    @if ($amountInWords !== '')
        <div class="words">In words: {{ Str::ucfirst(Str::lower($amountInWords)) }}</div>
    @endif

    @if (($options['show_note'] ?? false) && filled($order['note'] ?? null))
        <hr class="rule">
        <div class="muted">Note: {{ $order['note'] }}</div>
    @endif

    <hr class="rule">

    <div class="center footer">
        @foreach ($footerLines as $line)
            <div @class(['bold' => $loop->first])>{{ $line }}</div>
        @endforeach
        @if (($options['show_barcode'] ?? false) && filled($order['barcode'] ?? null))
            <img class="barcode" src="{{ $order['barcode'] }}" alt="{{ $order['code'] }}">
            <div class="muted">{{ $order['code'] }}</div>
        @endif
        <div class="muted" style="margin-top: 2mm;">Printed {{ now()->format('d M Y h:i A') }} &middot; Powered by Sylloo</div>
    </div>
</body>
</html>
