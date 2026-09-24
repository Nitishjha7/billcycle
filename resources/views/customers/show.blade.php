@extends('layouts.app')

@section('title', $customer->name.' - BillCycle')

@section('content')
<div class="rounded-lg border border-gray-200 bg-white p-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold">{{ $customer->name }}</h1>
            <p class="text-sm text-gray-500">{{ $customer->email }}</p>
        </div>
        @if ($subscription)
            <x-status-badge :status="$subscription->status" />
        @endif
    </div>

    @if ($subscription)
        <div class="mt-4 flex items-center justify-between text-sm text-gray-600">
            <p>
                {{ $subscription->plan->name }} - Rs {{ number_format($subscription->plan->price_paise / 100, 2) }}/{{ $subscription->plan->interval }}
                &middot; Period: {{ $subscription->current_period_start->format('d M Y') }} - {{ $subscription->current_period_end->format('d M Y') }}
            </p>
            @if (! in_array($subscription->status, ['cancelled'], true))
                <a href="{{ route('plan-change.show', $customer) }}" class="rounded-md border border-gray-300 px-3 py-1.5 font-medium hover:bg-gray-50">
                    Change plan
                </a>
            @endif
        </div>
    @else
        <p class="mt-4 text-sm text-gray-500">No subscription.</p>
    @endif
</div>

@if ($timelineInvoice)
    <div class="mt-6 rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">
            Payment timeline - {{ $timelineInvoice->number }}
        </h2>

        <ol class="space-y-4 border-l border-gray-200 pl-4">
            <li>
                <p class="text-sm font-medium">{{ $timelineInvoice->issued_at->format('d M') }} &middot; Invoice issued</p>
                <p class="text-sm text-gray-500">Rs {{ number_format($timelineInvoice->total_paise / 100, 2) }}</p>
            </li>
            @foreach ($timelineInvoice->attempts as $attempt)
                <li>
                    <p class="text-sm font-medium">
                        {{ $attempt->attempted_at->format('d M') }} &middot; Attempt {{ $attempt->attempt_number }} -
                        <span class="text-red-600">FAILED</span>
                        <span class="font-normal text-gray-500">{{ $attempt->failure_code }}</span>
                    </p>
                    @if ($attempt->next_retry_at)
                        <p class="text-sm text-gray-500">
                            next retry: +{{ $attempt->attempted_at->diffInDays($attempt->next_retry_at) }} day(s)
                        </p>
                    @endif
                </li>
            @endforeach
            @if ($subscription->status === 'suspended')
                <li>
                    <p class="text-sm font-semibold text-red-600">
                        {{ $subscription->updated_at->format('d M') }} &middot; SUBSCRIPTION SUSPENDED
                    </p>
                </li>
            @endif
        </ol>
    </div>
@endif

<div class="mt-6 overflow-hidden rounded-lg border border-gray-200 bg-white">
    <h2 class="border-b border-gray-100 px-4 py-3 text-sm font-semibold uppercase tracking-wide text-gray-500">Invoices</h2>
    @forelse ($invoices as $invoice)
        <a href="{{ route('invoices.show', $invoice) }}" class="flex items-center justify-between border-b border-gray-100 px-4 py-3 text-sm last:border-b-0 hover:bg-gray-50">
            <div>
                <span class="font-medium">{{ $invoice->number }}</span>
                <span class="ml-2 text-gray-500">{{ $invoice->period_start->format('d M') }} - {{ $invoice->period_end->format('d M Y') }}</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="font-medium">Rs {{ number_format($invoice->total_paise / 100, 2) }}</span>
                <x-status-badge :status="$invoice->status" />
            </div>
        </a>
    @empty
        <p class="px-4 py-6 text-center text-sm text-gray-500">
            No invoices yet - first invoice will be issued on
            {{ $subscription?->current_period_end?->format('d M Y') ?? 'the next billing date' }}.
        </p>
    @endforelse
</div>
@endsection
