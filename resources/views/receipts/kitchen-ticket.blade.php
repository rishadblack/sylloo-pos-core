{{--
    A kitchen ticket (KitchenTickets::render, erp: docs/restaurant.md): the fields of the ERP's kitchen_ticket layout, with
    table, mode, ticket number and time in large type, add-ons indented and notes for the cook. Never a price. Every value
    is escaped.
--}}
@php
    $narrow = $paperWidth === 58;
    $content = $narrow ? 48 : 72;
    $fontSize = $narrow ? 12 : 14;
    $qty = fn ($value) => \Rishadblack\SyllooPosCore\Services\StockAvailability::quantityText((float) $value);
    $kindTitle = match ($ticket->kind) {
        \Rishadblack\SyllooPosCore\Models\KitchenTicket::KIND_CANCEL => 'CANCEL',
        \Rishadblack\SyllooPosCore\Models\KitchenTicket::KIND_REPRINT => 'REPRINT',
        default => null,
    };
    $mode = match ($order?->sale_mode) {
        'dine-in' => 'Dine-in',
        'takeaway' => 'Takeaway',
        default => null,
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Kitchen ticket {{ $ticket->number }}</title>
    <style>
        @page { size: {{ $paperWidth }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; }
        body {
            width: {{ $content }}mm;
            margin: 0 auto;
            padding: 3mm 0 6mm;
            color: #000;
            font-family: "Courier New", Courier, monospace;
            font-size: {{ $fontSize }}px;
            line-height: 1.35;
        }
        .center { text-align: center; }
        .notice { margin-bottom: 2mm; padding: 1mm; border: 2px solid #000; font-weight: bold; text-align: center; }
        .kind { font-size: 1.6em; font-weight: bold; letter-spacing: 2px; text-align: center; }
        .table { font-size: 2em; font-weight: bold; text-align: center; }
        .big { font-size: 1.3em; font-weight: bold; }
        .rule { margin: 1.5mm 0; border: 0; border-top: 1px dashed #000; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 0.6mm 0; vertical-align: top; }
        td.qty { width: 12mm; font-size: 1.2em; font-weight: bold; }
        .name { font-size: 1.15em; font-weight: bold; }
        .addon { padding-left: 4mm; }
        .note { padding-left: 4mm; font-style: italic; }
        .late { font-weight: bold; text-align: center; }
    </style>
</head>
<body>
    @if ($notice)
        <div class="notice">{{ $notice }}</div>
    @endif
    @if ($kindTitle)
        <div class="kind">*** {{ $kindTitle }} ***</div>
    @endif
    @if (filled($order?->table_name))
        <div class="table">{{ $order->table_name }}</div>
    @endif
    <div class="center big">{{ $mode ?? 'Kitchen' }} · #{{ $ticket->number }}</div>
    <div class="center">{{ $ticket->station }}</div>
    <div class="center big">{{ $ticket->created_at?->format('h:i A') }}</div>
    @if ($late)
        <div class="late">LATE · printed {{ now()->format('h:i A') }}</div>
    @endif
    <table>
        @if (filled($order?->code))
            <tr><td>Order</td><td style="text-align:right">{{ $order->code }}</td></tr>
        @endif
        @if ($order?->guest_count)
            <tr><td>Guests</td><td style="text-align:right">{{ $order->guest_count }}</td></tr>
        @endif
        @if (filled($cashier))
            <tr><td>By</td><td style="text-align:right">{{ $cashier }}</td></tr>
        @endif
    </table>

    <hr class="rule">
    <table>
        @foreach ($lines as $line)
            <tr>
                <td class="qty">{{ $ticket->kind === \Rishadblack\SyllooPosCore\Models\KitchenTicket::KIND_CANCEL ? '-' : '' }}{{ $qty($line['quantity']) }}</td>
                <td>
                    <div class="name">{{ $line['name'] }}@if (filled($line['unit'] ?? null)) ({{ $line['unit'] }})@endif</div>
                    @foreach ($line['addons'] ?? [] as $addon)
                        <div class="addon">+ {{ $qty($addon['quantity']) }} {{ $addon['name'] }}</div>
                    @endforeach
                    @if (filled($line['note'] ?? null))
                        <div class="note">» {{ $line['note'] }}</div>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
    <hr class="rule">
    @if (filled($order?->note))
        <div class="note">{{ $order->note }}</div>
    @endif
</body>
</html>
