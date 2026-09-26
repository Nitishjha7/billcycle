<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\InvoiceResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * See docs/UI_FLOW.md #2 (list) and #3 (detail, dunning timeline).
 */
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $customers = Customer::query()
            ->with(['subscriptions' => fn ($query) => $query->latest('created_at')->limit(1), 'subscriptions.plan'])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return response()->json([
            'data' => CustomerResource::collection($customers),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    public function show(Customer $customer): JsonResponse
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

        return response()->json([
            'customer' => ['id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email],
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start->toDateString(),
                'current_period_end' => $subscription->current_period_end->toDateString(),
                'trial_ends_at' => $subscription->trial_ends_at?->toDateString(),
                'updated_at' => $subscription->updated_at->toIso8601String(),
                'plan' => [
                    'id' => $subscription->plan->id,
                    'name' => $subscription->plan->name,
                    'price_paise' => $subscription->plan->price_paise,
                    'interval' => $subscription->plan->interval,
                ],
            ] : null,
            'invoices' => InvoiceResource::collection($invoices),
            'timeline_invoice' => $timelineInvoice ? new InvoiceResource($timelineInvoice) : null,
        ]);
    }
}
