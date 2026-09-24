@extends('layouts.app')

@section('title', 'Dashboard - BillCycle')

@section('content')
<h1 class="mb-6 text-xl font-semibold">Dashboard</h1>

<div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <p class="text-sm text-gray-500">MRR</p>
        <p class="mt-1 text-2xl font-semibold">Rs {{ number_format($mrrPaise / 100, 2) }}</p>
    </div>
    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <p class="text-sm text-gray-500">Active</p>
        <p class="mt-1 text-2xl font-semibold text-green-700">{{ $counts['active'] }}</p>
    </div>
    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <p class="text-sm text-gray-500">Past due</p>
        <p class="mt-1 text-2xl font-semibold text-amber-600">{{ $counts['past_due'] }}</p>
    </div>
    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <p class="text-sm text-gray-500">Suspended</p>
        <p class="mt-1 text-2xl font-semibold text-red-600">{{ $counts['suspended'] }}</p>
    </div>
</div>

<div class="mt-4 grid grid-cols-2 gap-4 sm:w-1/2">
    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <p class="text-sm text-gray-500">Overdue invoices</p>
        <p class="mt-1 text-2xl font-semibold">{{ $overdueInvoices }}</p>
    </div>
    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <p class="text-sm text-gray-500">In dunning</p>
        <p class="mt-1 text-2xl font-semibold">{{ $inDunning }}</p>
    </div>
</div>

<h2 class="mt-8 mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">Recent activity</h2>

<div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
    @forelse ($activity as $item)
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 text-sm last:border-b-0">
            <div>
                <span class="text-gray-500">{{ $item['at']->format('d M') }}</span>
                <span class="ml-2">{{ $item['description'] }}</span>
                @if ($item['detail'])
                    <span class="ml-1 text-gray-500">- {{ $item['detail'] }}</span>
                @endif
            </div>
            @if (! is_null($item['amount_paise']))
                <span class="font-medium {{ $item['amount_paise'] < 0 ? 'text-red-600' : 'text-gray-900' }}">
                    {{ $item['amount_paise'] < 0 ? '-' : '+' }}Rs {{ number_format(abs($item['amount_paise']) / 100, 2) }}
                </span>
            @endif
        </div>
    @empty
        <div class="px-4 py-6 text-center text-sm text-gray-500">No activity yet.</div>
    @endforelse
</div>
@endsection
