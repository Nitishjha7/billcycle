<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        th { text-align: left; text-transform: uppercase; font-size: 10px; color: #6b7280; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; }
        td { padding: 6px 0; border-bottom: 1px solid #f3f4f6; }
        .amount { text-align: right; }
        .negative { color: #dc2626; }
        tfoot td { border-top: 2px solid #1f2937; border-bottom: none; font-weight: bold; padding-top: 10px; }
        .status { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <h1>{{ $invoice->number }}</h1>
    <p class="muted">{{ $invoice->subscription->customer->name }} &middot; {{ $invoice->issued_at->format('d M Y') }}</p>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="amount {{ $line->amount_paise < 0 ? 'negative' : '' }}">
                        {{ $line->amount_paise < 0 ? '- ' : ($line->type !== 'subscription' ? '+ ' : '') }}Rs {{ number_format(abs($line->amount_paise) / 100, 2) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="amount">Rs {{ number_format($invoice->total_paise / 100, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="status">
        Status: {{ ucfirst($invoice->status) }}
        @if ($invoice->status === 'paid' && $invoice->payments->isNotEmpty())
            - {{ $invoice->payments->last()->created_at->format('d M Y') }}
        @endif
    </p>
</body>
</html>
