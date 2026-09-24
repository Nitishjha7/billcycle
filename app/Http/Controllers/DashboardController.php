<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PlanChange;
use App\Models\Subscription;
use Illuminate\View\View;

/**
 * See docs/UI_FLOW.md #1. Scale and mess -- this exists to look like a
 * running system, not a fixture.
 */
class DashboardController extends Controller
{
    public function index(): View
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

        $activity = $this->recentActivity();

        return view('dashboard', [
            'mrrPaise' => (int) $mrrPaise,
            'counts' => $counts,
            'overdueInvoices' => $overdueInvoices,
            'inDunning' => $inDunning,
            'activity' => $activity,
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
                'at' => $invoice->issued_at,
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
                'at' => $attempt->attempted_at,
                'description' => 'Payment failed - '.$attempt->invoice->subscription->customer->name,
                'detail' => "attempt {$attempt->attempt_number}",
                'amount_paise' => null,
            ]);

        $planChanges = PlanChange::with('subscription.customer')
            ->latest('changed_at')
            ->take($limit)
            ->get()
            ->map(fn (PlanChange $change) => [
                'at' => $change->changed_at,
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
                'at' => $subscription->updated_at,
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
