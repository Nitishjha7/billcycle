import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import client from '../api/client';

function formatPaise(paise) {
    return (paise / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function longDate(value) {
    return new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

export default function PlanChange() {
    const { id } = useParams();
    const navigate = useNavigate();

    const [customer, setCustomer] = useState(null);
    const [subscription, setSubscription] = useState(null);
    const [plans, setPlans] = useState([]);
    const [selectedPlanId, setSelectedPlanId] = useState('');
    const [preview, setPreview] = useState(null);
    const [loading, setLoading] = useState(true);
    const [applying, setApplying] = useState(false);

    useEffect(() => {
        Promise.all([client.get(`/customers/${id}`), client.get('/plans')]).then(([customerRes, plansRes]) => {
            setCustomer(customerRes.data.customer);
            setSubscription(customerRes.data.subscription);
            setPlans(plansRes.data.plans);
            setLoading(false);
        });
    }, [id]);

    async function handlePlanChange(e) {
        const planId = e.target.value;
        setSelectedPlanId(planId);
        setPreview(null);

        if (!planId) return;

        const res = await client.post(`/customers/${id}/change-plan/preview`, { plan_id: planId });
        setPreview(res.data);
    }

    async function handleConfirm() {
        setApplying(true);
        await client.post(`/customers/${id}/change-plan`, { plan_id: selectedPlanId });
        navigate(`/customers/${id}`);
    }

    if (loading) {
        return <p className="text-slate-500">Loading…</p>;
    }

    const selectedPlan = plans.find((p) => p.id === selectedPlanId);

    return (
        <div className="mx-auto max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h1 className="text-lg font-semibold text-slate-900">Change plan - {customer.name}</h1>

            {!subscription ? (
                <p className="mt-4 text-sm text-slate-500">This customer has no subscription to change.</p>
            ) : (
                <>
                    <p className="mt-2 text-sm text-slate-600">
                        Current plan: {subscription.plan.name} &middot; Rs {formatPaise(subscription.plan.price_paise)}/{subscription.plan.interval}
                    </p>

                    <div className="mt-4">
                        <label htmlFor="plan_id" className="block text-sm font-medium text-slate-700">New plan</label>
                        <select
                            id="plan_id"
                            value={selectedPlanId}
                            onChange={handlePlanChange}
                            className="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 sm:text-sm"
                        >
                            <option value="">Select a plan…</option>
                            {plans.map((plan) => (
                                <option key={plan.id} value={plan.id} disabled={plan.id === subscription.id}>
                                    {plan.name} - Rs {formatPaise(plan.price_paise)}/{plan.interval}
                                </option>
                            ))}
                        </select>
                    </div>

                    {preview && selectedPlan && (
                        <>
                            <div className="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <p className="text-sm text-slate-600">
                                    Today is {longDate(new Date())}. Cycle: {longDate(preview.cycle_start)} - {longDate(preview.cycle_end)}.
                                </p>

                                <dl className="mt-3 space-y-1 text-sm">
                                    <div className="flex justify-between">
                                        <dt className="text-slate-700">Unused {preview.current_plan_name}</dt>
                                        <dd className="text-red-600">- Rs {formatPaise(preview.credit_paise)}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-700">{preview.new_plan_name} for remaining period</dt>
                                        <dd className="text-slate-900">+ Rs {formatPaise(preview.charge_paise)}</dd>
                                    </div>
                                </dl>

                                <div className="mt-3 flex justify-between border-t border-slate-200 pt-3 text-sm font-semibold text-slate-900">
                                    {preview.net_paise > 0 ? (
                                        <>
                                            <dt>Charged today</dt>
                                            <dd>Rs {formatPaise(preview.net_paise)}</dd>
                                        </>
                                    ) : (
                                        <>
                                            <dt>Credit applied to next invoice</dt>
                                            <dd>Rs {formatPaise(Math.abs(preview.net_paise))}</dd>
                                        </>
                                    )}
                                </div>

                                {preview.net_paise <= 0 && (
                                    <p className="mt-2 text-xs text-slate-500">
                                        Nothing is charged today. This credit is applied as a line on your next regular invoice --
                                        no refund is issued.
                                    </p>
                                )}
                            </div>

                            <div className="mt-4 flex justify-end gap-3">
                                <Link to={`/customers/${id}`} className="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                    Cancel
                                </Link>
                                <button
                                    onClick={handleConfirm}
                                    disabled={applying}
                                    className="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-60"
                                >
                                    {applying ? 'Confirming…' : 'Confirm change'}
                                </button>
                            </div>
                        </>
                    )}
                </>
            )}
        </div>
    );
}
