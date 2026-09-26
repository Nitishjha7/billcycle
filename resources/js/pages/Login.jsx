import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function Login() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(e) {
        e.preventDefault();
        setSubmitting(true);
        setError(null);

        try {
            await login(email, password);
            navigate('/');
        } catch (err) {
            setError(err.response?.data?.errors?.email?.[0] ?? 'Invalid credentials.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto mt-16 max-w-sm">
            <div className="mb-6 flex items-center justify-center gap-2">
                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-sky-500 text-sm font-bold text-white">B</span>
                <h1 className="text-xl font-semibold text-slate-900">BillCycle</h1>
            </div>

            {error && (
                <div className="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{error}</div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div>
                    <label htmlFor="email" className="block text-sm font-medium text-slate-700">Email</label>
                    <input
                        id="email"
                        type="email"
                        required
                        autoFocus
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        className="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 sm:text-sm"
                    />
                </div>
                <div>
                    <label htmlFor="password" className="block text-sm font-medium text-slate-700">Password</label>
                    <input
                        id="password"
                        type="password"
                        required
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        className="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-sky-500 focus:ring-sky-500 sm:text-sm"
                    />
                </div>
                <button
                    type="submit"
                    disabled={submitting}
                    className="w-full rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-60"
                >
                    {submitting ? 'Logging in…' : 'Log in'}
                </button>
            </form>

            <p className="mt-4 text-center text-xs text-slate-500">Demo login: admin@billcycle.demo / password</p>
        </div>
    );
}
