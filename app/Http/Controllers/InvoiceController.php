<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * See docs/UI_FLOW.md #5. The negative line is the visible proof that
 * credits are line items rather than a separate concept
 * (docs/TECHNICAL_SPEC.md #2).
 */
class InvoiceController extends Controller
{
    public function show(Invoice $invoice): View
    {
        $invoice->load(['lines', 'subscription.customer', 'payments']);

        return view('invoices.show', [
            'invoice' => $invoice,
        ]);
    }

    public function pdf(Invoice $invoice): Response
    {
        $invoice->load(['lines', 'subscription.customer', 'payments']);

        return Pdf::loadView('invoices.pdf', ['invoice' => $invoice])
            ->download("{$invoice->number}.pdf");
    }
}
