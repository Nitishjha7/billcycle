@extends('layouts.app')

@section('title', $invoice->number.' - BillCycle')

@section('content')
<div class="mx-auto max-w-2xl rounded-lg border border-gray-200 bg-white p-6">
    <div class="flex items-start justify-between border-b border-gray-100 pb-4">
        <div>
            <h1 class="text-lg font-semibold">{{ $invoice->number }}</h1>
            <p class="text-sm text-gray-500">
                {{ $invoice->subscription->customer->name }} &middot; {{ $invoice->issued_at->format('d M Y') }}
            </p>
        </div>
        <div class="flex items-center gap-4">
            <a href="{{ route('invoices.pdf', $invoice) }}" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">
                Download PDF
            </a>
            <a href="{{ route('customers.show', $invoice->subscription->customer) }}" class="text-sm text-gray-600 hover:underline">
                &larr; Back to customer
            </a>
        </div>
    </div>

    <table class="mt-4 w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                <th class="pb-2">Description</th>
                <th class="pb-2 text-right">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach ($invoice->lines as $line)
                <tr>
                    <td class="py-2">{{ $line->description }}</td>
                    <td class="py-2 text-right font-medium {{ $line->amount_paise < 0 ? 'text-red-600' : 'text-gray-900' }}">
                        {{ $line->amount_paise < 0 ? '- ' : ($line->type !== 'subscription' ? '+ ' : '') }}Rs {{ number_format(abs($line->amount_paise) / 100, 2) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="border-t border-gray-200 font-semibold">
                <td class="pt-2">Total</td>
                <td class="pt-2 text-right">Rs {{ number_format($invoice->total_paise / 100, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="mt-6 flex items-center justify-between border-t border-gray-100 pt-4 text-sm">
        <div class="flex items-center gap-2">
            <span class="text-gray-500">Status:</span>
            <x-status-badge :status="$invoice->status" />
            @if ($invoice->status === 'paid' && $invoice->payments->isNotEmpty())
                <span class="text-gray-500">- {{ $invoice->payments->last()->created_at->format('d M Y') }}</span>
            @endif
        </div>
    </div>
</div>
@endsection
