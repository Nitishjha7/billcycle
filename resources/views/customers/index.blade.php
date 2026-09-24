@extends('layouts.app')

@section('title', 'Customers - BillCycle')

@section('content')
<div class="mb-6 flex items-center justify-between">
    <h1 class="text-xl font-semibold">Customers</h1>
    <form method="GET" class="flex gap-2">
        <input type="search" name="search" value="{{ $search }}" placeholder="Search"
            class="rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500">
        <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Search</button>
    </form>
</div>

<div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">Name</th>
                <th class="px-4 py-3">Plan</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Next billing</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($customers as $customer)
                @php $subscription = $customer->subscriptions->first(); @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="{{ route('customers.show', $customer) }}" class="font-medium text-gray-900 hover:underline">
                            {{ $customer->name }}
                        </a>
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ $subscription?->plan?->name ?? '-' }}</td>
                    <td class="px-4 py-3">
                        @if ($subscription)
                            <x-status-badge :status="$subscription->status" />
                        @else
                            <span class="text-gray-400">no subscription</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-600">
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
                    <td colspan="4" class="px-4 py-6 text-center text-gray-500">No customers found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $customers->links() }}
</div>
@endsection
