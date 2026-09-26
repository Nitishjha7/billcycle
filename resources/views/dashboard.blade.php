@extends('layouts.app')

@section('title', 'Dashboard - BillCycle')

@section('content')
<h1 class="mb-6 text-2xl font-semibold text-slate-900">Dashboard</h1>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm text-slate-500">MRR</p>
        <p class="mt-2 text-2xl font-semibold text-slate-900">Rs {{ number_format($mrrPaise / 100, 2) }}</p>
        <p class="mt-1 text-sm text-emerald-600">{{ $counts['active'] }} active subscriptions</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm text-slate-500">Active subscriptions</p>
        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $counts['active'] }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ $counts['past_due'] }} past due &middot; {{ $counts['suspended'] }} suspended</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm text-slate-500">Failed payments</p>
        <p class="mt-2 text-2xl font-semibold text-amber-600">{{ $failedPaymentCount }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ $counts['past_due'] }} retrying, {{ $counts['suspended'] }} suspended</p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-sm text-slate-500">Pending invoices</p>
        <p class="mt-2 text-2xl font-semibold text-slate-900">Rs {{ number_format($pendingInvoiceTotalPaise / 100, 2) }}</p>
        <p class="mt-1 text-sm text-slate-500">across {{ $pendingInvoiceCustomerCount }} customer{{ $pendingInvoiceCustomerCount === 1 ? '' : 's' }}</p>
    </div>
</div>

<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-1">
        <h2 class="border-b border-slate-100 px-5 py-4 text-base font-semibold text-slate-900">Recent activity</h2>
        <div class="divide-y divide-slate-100">
            @forelse ($activity as $item)
                @php
                    $icons = [
                        'invoice' => ['bg' => 'bg-sky-100 text-sky-600', 'icon' => 'invoice'],
                        'failed_payment' => ['bg' => 'bg-red-100 text-red-600', 'icon' => 'x'],
                        'plan_change' => ['bg' => 'bg-emerald-100 text-emerald-600', 'icon' => 'arrow-up'],
                        'suspended' => ['bg' => 'bg-slate-200 text-slate-600', 'icon' => 'pause'],
                    ];
                    $style = $icons[$item['type']] ?? $icons['invoice'];
                @endphp
                <div class="flex gap-3 px-5 py-4">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $style['bg'] }}">
                        <x-activity-icon :name="$style['icon']" class="h-4 w-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-slate-900">{{ $item['description'] }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ $item['at']->format('d M') }}
                            @if ($item['detail'])
                                &middot; {{ $item['detail'] }}
                            @endif
                        </p>
                    </div>
                    @if (! is_null($item['amount_paise']))
                        <span class="shrink-0 text-sm font-medium {{ $item['amount_paise'] < 0 ? 'text-red-600' : 'text-slate-900' }}">
                            {{ $item['amount_paise'] < 0 ? '-' : '+' }}Rs {{ number_format(abs($item['amount_paise']) / 100, 2) }}
                        </span>
                    @endif
                </div>
            @empty
                <div class="px-5 py-8 text-center text-sm text-slate-500">No activity yet.</div>
            @endforelse
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <h2 class="text-base font-semibold text-slate-900">Customers</h2>
            <a href="{{ route('customers.index') }}" class="text-sm font-medium text-sky-600 hover:text-sky-700">View all &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Plan</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Next billing</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($recentCustomers ?? [] as $subscription)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <a href="{{ route('customers.show', $subscription->customer) }}" class="font-medium text-slate-900 hover:underline">
                                    {{ $subscription->customer->name }}
                                </a>
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $subscription->plan->name }}</td>
                            <td class="px-5 py-3"><x-status-badge :status="$subscription->status" /></td>
                            <td class="px-5 py-3 text-slate-600">{{ $subscription->current_period_end->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-8 text-center text-slate-500">No customers yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
