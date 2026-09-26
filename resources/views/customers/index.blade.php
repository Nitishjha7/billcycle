@extends('layouts.app')

@section('title', 'Customers - BillCycle')

@section('content')
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <h1 class="text-2xl font-semibold text-slate-900">Customers</h1>
    <form method="GET" class="flex gap-2">
        <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input type="search" name="search" value="{{ $search }}" placeholder="Search customers..."
                class="w-56 rounded-lg border-slate-300 pl-9 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
        </div>
        <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">Search</button>
    </form>
</div>

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <table class="min-w-full divide-y divide-slate-100 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-5 py-3">Name</th>
                <th class="px-5 py-3">Plan</th>
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3">Next billing</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($customers as $customer)
                @php $subscription = $customer->subscriptions->first(); @endphp
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3.5">
                        <a href="{{ route('customers.show', $customer) }}" class="font-medium text-slate-900 hover:text-sky-600 hover:underline">
                            {{ $customer->name }}
                        </a>
                    </td>
                    <td class="px-5 py-3.5 text-slate-600">{{ $subscription?->plan?->name ?? '-' }}</td>
                    <td class="px-5 py-3.5">
                        @if ($subscription)
                            <x-status-badge :status="$subscription->status" />
                        @else
                            <span class="text-slate-400">no subscription</span>
                        @endif
                    </td>
                    <td class="px-5 py-3.5 text-slate-600">
                        @if (! $subscription)
                            -
                        @elseif ($subscription->status === 'suspended')
                            since {{ $subscription->updated_at->format('d M') }}
                        @elseif ($subscription->status === 'past_due')
                            {{ $subscription->invoices()->where('status', 'open')->first()?->attempts()->count() ?? 0 }} attempts
                        @elseif ($subscription->status === 'trialing')
                            trial ends {{ $subscription->trial_ends_at?->format('d M') }}
                        @else
                            {{ $subscription->current_period_end->format('d M Y') }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-5 py-10 text-center text-slate-500">No customers found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $customers->links() }}
</div>
@endsection
