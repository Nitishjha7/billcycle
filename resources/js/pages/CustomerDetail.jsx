import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import client from '../api/client';
import StatusBadge from '../components/StatusBadge';

function formatPaise(paise) {
    return (paise / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function shortDate(value) {
    return new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

function longDate(value) {
    return new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function daysBetween(a, b) {
    return Math.round((new Date(b) - new Date(a)) / (1000 * 60 * 60 * 24));
}

export default function CustomerDetail() {
    const { id } = useParams();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        setLoading(true);
        client.get(`/customers/${id}`).then((res) => {
            setData(res.data);
            setLoading(false);
        });
    }, [id]);

    if (loading) {
        return <p className="text-slate-500">Loading…</p>;
    }

    const { customer, subscription, invoices, timeline_invoice: timelineInvoice } = data;

    return (
        <>
            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold text-slate-900">{customer.name}</h1>
                        <p className="text-sm text-slate-500">{customer.email}</p>
                    </div>
                    {subscription && <StatusBadge status={subscription.status} />}
                </div>

                {subscription ? (
                    <div className="mt-4 flex items-center justify-between text-sm text-slate-600">
                        <p>
                            {subscription.plan.name} - Rs {formatPaise(subscription.plan.price_paise)}/{subscription.plan.interval}
                            &middot; Period: {shortDate(subscription.current_period_start)} - {longDate(subscription.current_period_end)}
                        </p>
                        {subscription.status !== 'cancelled' && (
                            <Link
                                to={`/customers/${customer.id}/change-plan`}
                                className="rounded-lg border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50"
                            >
                                Change plan
                            </Link>
                        )}
                    </div>
                ) : (
                    <p className="mt-4 text-sm text-slate-500">No subscription.</p>
                )}
            </div>

            {timelineInvoice && (
                <div className="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Payment timeline - {timelineInvoice.number}
                    </h2>

                    <ol className="space-y-4 border-l-2 border-slate-200 pl-4">
                        <li>
                            <p className="text-sm font-medium text-slate-900">{shortDate(timelineInvoice.issued_at)} &middot; Invoice issued</p>
                            <p className="text-sm text-slate-500">Rs {formatPaise(timelineInvoice.total_paise)}</p>
                        </li>
                        {timelineInvoice.attempts.map((attempt) => (
                            <li key={attempt.id}>
                                <p className="text-sm font-medium text-slate-900">
                                    {shortDate(attempt.attempted_at)} &middot; Attempt {attempt.attempt_number} -{' '}
                                    <span className="text-red-600">FAILED</span>{' '}
                                    <span className="font-normal text-slate-500">{attempt.failure_code}</span>
                                </p>
                                {attempt.next_retry_at && (
                                    <p className="text-sm text-slate-500">
                                        next retry: +{daysBetween(attempt.attempted_at, attempt.next_retry_at)} day(s)
                                    </p>
                                )}
                            </li>
                        ))}
                        {subscription.status === 'suspended' && (
                            <li>
                                <p className="text-sm font-semibold text-red-600">SUBSCRIPTION SUSPENDED</p>
                            </li>
                        )}
                    </ol>
                </div>
            )}

            <div className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 className="border-b border-slate-100 px-5 py-3.5 text-sm font-semibold uppercase tracking-wide text-slate-500">Invoices</h2>
                {invoices.length === 0 ? (
                    <p className="px-5 py-8 text-center text-sm text-slate-500">
                        No invoices yet - first invoice will be issued on{' '}
                        {subscription ? longDate(subscription.current_period_end) : 'the next billing date'}.
                    </p>
                ) : (
                    invoices.map((invoice) => (
                        <Link
                            key={invoice.id}
                            to={`/invoices/${invoice.id}`}
                            className="flex items-center justify-between border-b border-slate-100 px-5 py-3.5 text-sm last:border-b-0 hover:bg-slate-50"
                        >
                            <div>
                                <span className="font-medium text-slate-900">{invoice.number}</span>
                                <span className="ml-2 text-slate-500">{shortDate(invoice.period_start)} - {longDate(invoice.period_end)}</span>
                            </div>
                            <div className="flex items-center gap-3">
                                <span className="font-medium text-slate-900">Rs {formatPaise(invoice.total_paise)}</span>
                                <StatusBadge status={invoice.status} />
                            </div>
                        </Link>
                    ))
                )}
            </div>
        </>
    );
}
