import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import client from '../api/client';
import StatusBadge from '../components/StatusBadge';
import { ActivityIcon } from '../components/icons';

function formatPaise(paise) {
    return (paise / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const ACTIVITY_STYLE = {
    invoice: { bg: 'bg-sky-100 text-sky-600', icon: 'invoice' },
    failed_payment: { bg: 'bg-red-100 text-red-600', icon: 'x' },
    plan_change: { bg: 'bg-emerald-100 text-emerald-600', icon: 'arrow-up' },
    suspended: { bg: 'bg-slate-200 text-slate-600', icon: 'pause' },
};

export default function Dashboard() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        client.get('/dashboard').then((res) => {
            setData(res.data);
            setLoading(false);
        });
    }, []);

    if (loading) {
        return <p className="text-slate-500">Loading…</p>;
    }

    const { counts, activity, recent_customers: recentCustomers } = data;

    return (
        <>
            <h1 className="mb-6 text-2xl font-semibold text-slate-900">Dashboard</h1>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p className="text-sm text-slate-500">MRR</p>
                    <p className="mt-2 text-2xl font-semibold text-slate-900">Rs {formatPaise(data.mrr_paise)}</p>
                    <p className="mt-1 text-sm text-emerald-600">{counts.active} active subscriptions</p>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p className="text-sm text-slate-500">Active subscriptions</p>
                    <p className="mt-2 text-2xl font-semibold text-slate-900">{counts.active}</p>
                    <p className="mt-1 text-sm text-slate-500">{counts.past_due} past due &middot; {counts.suspended} suspended</p>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p className="text-sm text-slate-500">Failed payments</p>
                    <p className="mt-2 text-2xl font-semibold text-amber-600">{data.failed_payment_count}</p>
                    <p className="mt-1 text-sm text-slate-500">{counts.past_due} retrying, {counts.suspended} suspended</p>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p className="text-sm text-slate-500">Pending invoices</p>
                    <p className="mt-2 text-2xl font-semibold text-slate-900">Rs {formatPaise(data.pending_invoice_total_paise)}</p>
                    <p className="mt-1 text-sm text-slate-500">
                        across {data.pending_invoice_customer_count} customer{data.pending_invoice_customer_count === 1 ? '' : 's'}
                    </p>
                </div>
            </div>

            <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-1">
                    <h2 className="border-b border-slate-100 px-5 py-4 text-base font-semibold text-slate-900">Recent activity</h2>
                    <div className="divide-y divide-slate-100">
                        {activity.length === 0 && (
                            <div className="px-5 py-8 text-center text-sm text-slate-500">No activity yet.</div>
                        )}
                        {activity.map((item, i) => {
                            const style = ACTIVITY_STYLE[item.type] ?? ACTIVITY_STYLE.invoice;

                            return (
                                <div key={i} className="flex gap-3 px-5 py-4">
                                    <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${style.bg}`}>
                                        <ActivityIcon name={style.icon} className="h-4 w-4" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-slate-900">{item.description}</p>
                                        <p className="mt-0.5 text-xs text-slate-500">
                                            {new Date(item.at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' })}
                                            {item.detail && <> &middot; {item.detail}</>}
                                        </p>
                                    </div>
                                    {item.amount_paise != null && (
                                        <span className={`shrink-0 text-sm font-medium ${item.amount_paise < 0 ? 'text-red-600' : 'text-slate-900'}`}>
                                            {item.amount_paise < 0 ? '-' : '+'}Rs {formatPaise(Math.abs(item.amount_paise))}
                                        </span>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                    <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <h2 className="text-base font-semibold text-slate-900">Customers</h2>
                        <Link to="/customers" className="text-sm font-medium text-sky-600 hover:text-sky-700">View all &rarr;</Link>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-100 text-sm">
                            <thead>
                                <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
                                    <th className="px-5 py-3">Name</th>
                                    <th className="px-5 py-3">Plan</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3">Next billing</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {recentCustomers.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-5 py-8 text-center text-slate-500">No customers yet.</td>
                                    </tr>
                                )}
                                {recentCustomers.map((s) => (
                                    <tr key={s.id} className="hover:bg-slate-50">
                                        <td className="px-5 py-3">
                                            <Link to={`/customers/${s.customer.id}`} className="font-medium text-slate-900 hover:underline">
                                                {s.customer.name}
                                            </Link>
                                        </td>
                                        <td className="px-5 py-3 text-slate-600">{s.plan.name}</td>
                                        <td className="px-5 py-3"><StatusBadge status={s.status} /></td>
                                        <td className="px-5 py-3 text-slate-600">
                                            {new Date(s.current_period_end).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}
