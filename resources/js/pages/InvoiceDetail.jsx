import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import client from '../api/client';
import StatusBadge from '../components/StatusBadge';

function formatPaise(paise) {
    return (Math.abs(paise) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function longDate(value) {
    return new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

export default function InvoiceDetail() {
    const { id } = useParams();
    const [invoice, setInvoice] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        client.get(`/invoices/${id}`).then((res) => {
            setInvoice(res.data.invoice);
            setLoading(false);
        });
    }, [id]);

    if (loading) {
        return <p className="text-slate-500">Loading…</p>;
    }

    return (
        <div className="mx-auto max-w-2xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <div className="flex items-start justify-between border-b border-slate-100 pb-4">
                <div>
                    <h1 className="text-lg font-semibold text-slate-900">{invoice.number}</h1>
                    <p className="text-sm text-slate-500">{invoice.customer?.name} &middot; {longDate(invoice.issued_at)}</p>
                </div>
                <div className="flex items-center gap-4">
                    <a href={`/api/invoices/${id}/pdf`} className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                        Download PDF
                    </a>
                    <Link to={`/customers/${invoice.customer?.id}`} className="text-sm text-slate-600 hover:underline">
                        &larr; Back to customer
                    </Link>
                </div>
            </div>

            <table className="mt-4 w-full text-sm">
                <thead>
                    <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                        <th className="pb-2">Description</th>
                        <th className="pb-2 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {invoice.lines.map((line) => (
                        <tr key={line.id}>
                            <td className="py-2 text-slate-700">{line.description}</td>
                            <td className={`py-2 text-right font-medium ${line.amount_paise < 0 ? 'text-red-600' : 'text-slate-900'}`}>
                                {line.amount_paise < 0 ? '- ' : line.type !== 'subscription' ? '+ ' : ''}
                                Rs {formatPaise(line.amount_paise)}
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="border-t border-slate-200 font-semibold text-slate-900">
                        <td className="pt-2">Total</td>
                        <td className="pt-2 text-right">Rs {formatPaise(invoice.total_paise)}</td>
                    </tr>
                </tfoot>
            </table>

            <div className="mt-6 flex items-center justify-between border-t border-slate-100 pt-4 text-sm">
                <div className="flex items-center gap-2">
                    <span className="text-slate-500">Status:</span>
                    <StatusBadge status={invoice.status} />
                    {invoice.status === 'paid' && invoice.payments?.length > 0 && (
                        <span className="text-slate-500">- {longDate(invoice.payments[invoice.payments.length - 1].created_at)}</span>
                    )}
                </div>
            </div>
        </div>
    );
}
