import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import client from '../api/client';
import StatusBadge from '../components/StatusBadge';
import { SearchIcon } from '../components/icons';

export default function CustomerList() {
    const [searchParams, setSearchParams] = useSearchParams();
    const [customers, setCustomers] = useState([]);
    const [loading, setLoading] = useState(true);
    const search = searchParams.get('search') ?? '';

    useEffect(() => {
        setLoading(true);
        client.get('/customers', { params: { search } }).then((res) => {
            setCustomers(res.data.data);
            setLoading(false);
        });
    }, [search]);

    function handleSubmit(e) {
        e.preventDefault();
        const value = new FormData(e.target).get('search');
        setSearchParams(value ? { search: value } : {});
    }

    return (
        <>
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h1 className="text-2xl font-semibold text-slate-900">Customers</h1>
                <form onSubmit={handleSubmit} className="flex gap-2">
                    <div className="relative">
                        <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                            <SearchIcon />
                        </span>
                        <input
                            type="search"
                            name="search"
                            defaultValue={search}
                            placeholder="Search customers..."
                            className="w-56 rounded-lg border-slate-300 pl-9 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                        />
                    </div>
                    <button type="submit" className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                        Search
                    </button>
                </form>
            </div>

            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-slate-100 text-sm">
                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-5 py-3">Name</th>
                            <th className="px-5 py-3">Plan</th>
                            <th className="px-5 py-3">Status</th>
                            <th className="px-5 py-3">Next billing</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {loading && (
                            <tr><td colSpan={4} className="px-5 py-10 text-center text-slate-500">Loading…</td></tr>
                        )}
                        {!loading && customers.length === 0 && (
                            <tr><td colSpan={4} className="px-5 py-10 text-center text-slate-500">No customers found.</td></tr>
                        )}
                        {!loading && customers.map((customer) => {
                            const sub = customer.subscription;

                            return (
                                <tr key={customer.id} className="hover:bg-slate-50">
                                    <td className="px-5 py-3.5">
                                        <Link to={`/customers/${customer.id}`} className="font-medium text-slate-900 hover:text-sky-600 hover:underline">
                                            {customer.name}
                                        </Link>
                                    </td>
                                    <td className="px-5 py-3.5 text-slate-600">{sub?.plan?.name ?? '-'}</td>
                                    <td className="px-5 py-3.5">
                                        {sub ? <StatusBadge status={sub.status} /> : <span className="text-slate-400">no subscription</span>}
                                    </td>
                                    <td className="px-5 py-3.5 text-slate-600">
                                        {sub?.status === 'trialing' && sub.trial_ends_at
                                            ? `trial ends ${new Date(sub.trial_ends_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' })}`
                                            : sub?.current_period_end
                                                ? new Date(sub.current_period_end).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
                                                : '-'}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </>
    );
}
