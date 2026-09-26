@extends('layouts.app')

@section('title', 'Change plan - '.$customer->name)

@section('content')
<div class="mx-auto max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <h1 class="text-lg font-semibold text-slate-900">Change plan - {{ $customer->name }}</h1>

    @if (! $subscription)
        <p class="mt-4 text-sm text-slate-500">This customer has no subscription to change.</p>
    @else
        <p class="mt-2 text-sm text-slate-600">
            Current plan: {{ $subscription->plan->name }} &middot; Rs {{ number_format($subscription->plan->price_paise / 100, 2) }}/{{ $subscription->plan->interval }}
        </p>

        <form method="POST" action="{{ route('plan-change.preview', $customer) }}" class="mt-4">
            @csrf
            <label for="plan_id" class="block text-sm font-medium text-slate-700">New plan</label>
            <select name="plan_id" id="plan_id" onchange="this.form.submit()"
                class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 sm:text-sm">
                <option value="">Select a plan&hellip;</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}" @selected($selectedPlan && $selectedPlan->id === $plan->id) @disabled($plan->id === $subscription->plan_id)>
                        {{ $plan->name }} - Rs {{ number_format($plan->price_paise / 100, 2) }}/{{ $plan->interval }}
                    </option>
                @endforeach
            </select>
            <noscript><button type="submit" class="mt-2 rounded-lg border border-slate-300 px-3 py-1.5 text-sm">Preview</button></noscript>
        </form>

        @if ($preview && $selectedPlan)
            <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p class="text-sm text-slate-600">
                    Today is {{ now()->format('d M Y') }}. Cycle: {{ $subscription->current_period_start->format('d M') }} - {{ $subscription->current_period_end->format('d M Y') }}.
                </p>

                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-700">Unused {{ $subscription->plan->name }}</dt>
                        <dd class="text-red-600">- Rs {{ number_format($preview->creditPaise / 100, 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-700">{{ $selectedPlan->name }} for remaining period</dt>
                        <dd class="text-slate-900">+ Rs {{ number_format($preview->chargePaise / 100, 2) }}</dd>
                    </div>
                </dl>

                <div class="mt-3 flex justify-between border-t border-slate-200 pt-3 text-sm font-semibold text-slate-900">
                    @if ($preview->netPaise > 0)
                        <dt>Charged today</dt>
                        <dd>Rs {{ number_format($preview->netPaise / 100, 2) }}</dd>
                    @else
                        <dt>Credit applied to next invoice</dt>
                        <dd>Rs {{ number_format(abs($preview->netPaise) / 100, 2) }}</dd>
                    @endif
                </div>

                @if ($preview->netPaise <= 0)
                    <p class="mt-2 text-xs text-slate-500">
                        Nothing is charged today. This credit is applied as a line on your next regular invoice --
                        no refund is issued. See docs/TECHNICAL_SPEC.md for why.
                    </p>
                @endif
            </div>

            <form method="POST" action="{{ route('plan-change.apply', $customer) }}" class="mt-4 flex justify-end gap-3">
                @csrf
                <input type="hidden" name="plan_id" value="{{ $selectedPlan->id }}">
                <a href="{{ route('customers.show', $customer) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cancel</a>
                <button type="submit" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">Confirm change</button>
            </form>
        @endif
    @endif
</div>
@endsection
