<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * See docs/UI_FLOW.md #2 (list) and #3 (detail, dunning timeline).
 */
class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $customers = Customer::query()
            ->with(['subscriptions' => fn ($query) => $query->latest('created_at')->limit(1), 'subscriptions.plan'])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    public function show(Customer $customer): View
    {
        $customer->load(['subscriptions.plan']);
        $subscription = $customer->subscriptions->sortByDesc('created_at')->first();

        $invoices = $subscription
            ? $subscription->invoices()->latest('issued_at')->get()
            : collect();

        $timelineInvoice = null;

        if ($subscription && in_array($subscription->status, ['past_due', 'suspended'], true)) {
            $timelineInvoice = $invoices->firstWhere('status', 'open');
            $timelineInvoice?->load('attempts');
        }

        return view('customers.show', [
            'customer' => $customer,
            'subscription' => $subscription,
            'invoices' => $invoices,
            'timelineInvoice' => $timelineInvoice,
        ]);
    }
}
