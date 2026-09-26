<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PlanChange;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;

/**
 * See docs/UI_FLOW.md #1. Scale and mess -- this exists to look like a
 * running system, not a fixture.
 */
class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $mrrPaise = Subscription::query()
            ->where('status', 'active')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->sum('plans.price_paise');

        $counts = [
            'active' => Subscription::where('status', 'active')->count(),
            'past_due' => Subscription::where('status', 'past_due')->count(),
            'suspended' => Subscription::where('status', 'suspended')->count(),
        ];

        $overdueInvoices = Invoice::where('status', 'open')
            ->where('period_end', '<', now())
            ->count();

        $inDunning = Subscription::where('status', 'past_due')->count();

        $pendingInvoices = Invoice::where('invoices.status', 'open');
        $pendingInvoiceTotalPaise = (int) (clone $pendingInvoices)->sum('total_paise');
        $pendingInvoiceCustomerCount = (clone $pendingInvoices)
            ->join('subscriptions', 'subscriptions.id', '=', 'invoices.subscription_id')
            ->distinct('subscriptions.customer_id')
            ->count('subscriptions.customer_id');

        $failedPaymentCount = Subscription::whereIn('status', ['past_due', 'suspended'])->count();

        $recentCustomers = Subscription::with(['customer', 'plan'])
            ->latest('updated_at')
            ->take(5)
            ->get()
            ->map(fn (Subscription $subscription) => [
                'id' => $subscription->id,
                'customer' => ['id' => $subscription->customer->id, 'name' => $subscription->customer->name],
                'plan' => ['name' => $subscription->plan->name],
                'status' => $subscription->status,
                'current_period_end' => $subscription->current_period_end->toDateString(),
            ]);

        return response()->json([
            'mrr_paise' => (int) $mrrPaise,
            'counts' => $counts,
            'overdue_invoices' => $overdueInvoices,
            'in_dunning' => $inDunning,
            'pending_invoice_total_paise' => $pendingInvoiceTotalPaise,
            'pending_invoice_customer_count' => $pendingInvoiceCustomerCount,
            'failed_payment_count' => $failedPaymentCount,
            'activity' => $this->recentActivity(),
            'recent_customers' => $recentCustomers,
        ]);
    }

    /**
     * A merged, most-recent-first feed of invoices issued, failed
     * payments, plan changes, and suspensions -- there is no dedicated
     * activity-log table (the schema is deliberately fixed at eight
     * tables, see docs/TECHNICAL_SPEC.md #2), so this is assembled from
     * the tables that already carry the information.
     */
    private function recentActivity(int $limit = 12): array
    {
        $invoicesIssued = Invoice::with('subscription.customer')
            ->latest('issued_at')
            ->take($limit)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'at' => $invoice->issued_at->toIso8601String(),
                'type' => 'invoice',
                'description' => "Invoice {$invoice->number} issued",
                'detail' => $invoice->subscription->customer->name,
                'amount_paise' => $invoice->total_paise,
            ]);

        $failedAttempts = PaymentAttempt::with('invoice.subscription.customer')
            ->whereNotNull('failure_code')
            ->latest('attempted_at')
            ->take($limit)
            ->get()
            ->map(fn (PaymentAttempt $attempt) => [
                'at' => $attempt->attempted_at->toIso8601String(),
                'type' => 'failed_payment',
                'description' => 'Payment failed - '.$attempt->invoice->subscription->customer->name,
                'detail' => "attempt {$attempt->attempt_number}",
                'amount_paise' => null,
            ]);

        $planChanges = PlanChange::with('subscription.customer')
            ->latest('changed_at')
            ->take($limit)
            ->get()
            ->map(fn (PlanChange $change) => [
                'at' => $change->changed_at->toIso8601String(),
                'type' => 'plan_change',
                'description' => 'Plan changed - '.$change->subscription->customer->name,
                'detail' => null,
                'amount_paise' => $change->net_paise,
            ]);

        $suspensions = Subscription::with('customer')
            ->where('status', 'suspended')
            ->latest('updated_at')
            ->take($limit)
            ->get()
            ->map(fn (Subscription $subscription) => [
                'at' => $subscription->updated_at->toIso8601String(),
                'type' => 'suspended',
                'description' => 'Subscription suspended - '.$subscription->customer->name,
                'detail' => $subscription->invoices()->withCount('attempts')->latest('issued_at')->first()?->attempts_count.' attempts',
                'amount_paise' => null,
            ]);

        return $invoicesIssued
            ->concat($failedAttempts)
            ->concat($planChanges)
            ->concat($suspensions)
            ->sortByDesc('at')
            ->take($limit)
            ->values()
            ->all();
    }
}
