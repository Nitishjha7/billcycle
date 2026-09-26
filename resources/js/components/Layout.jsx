import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { NavIcon } from './icons';

const NAV_ITEMS = [
    { to: '/', label: 'Dashboard', icon: 'home', match: (path) => path === '/' },
    { to: '/customers', label: 'Customers', icon: 'users', match: (path) => path.startsWith('/customers') },
];

export default function Layout({ children }) {
    const { logout } = useAuth();
    const location = useLocation();

    return (
        <div className="flex min-h-screen">
            <aside className="fixed inset-y-0 left-0 hidden w-64 flex-col bg-slate-900 text-slate-300 lg:flex">
                <div className="flex items-center gap-2 px-6 py-5">
                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-500 text-sm font-bold text-white">B</span>
                    <span className="text-lg font-semibold text-white">BillCycle</span>
                </div>

                <nav className="mt-4 flex-1 space-y-1 px-3">
                    {NAV_ITEMS.map((item) => {
                        const active = item.match(location.pathname);

                        return (
                            <Link
                                key={item.to}
                                to={item.to}
                                className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                                    active ? 'bg-sky-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                                }`}
                            >
                                <NavIcon name={item.icon} className="h-5 w-5 shrink-0" />
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>

                <div className="border-t border-slate-800 p-4">
                    <button
                        onClick={logout}
                        className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white"
                    >
                        <NavIcon name="logout" className="h-5 w-5 shrink-0" />
                        Log out
                    </button>
                </div>
            </aside>

            <div className="flex w-full flex-1 flex-col lg:pl-64">
                <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
                    <span className="text-lg font-semibold">BillCycle</span>
                    <nav className="flex items-center gap-4 text-sm">
                        <Link to="/" className="text-slate-600 hover:text-slate-900">Dashboard</Link>
                        <Link to="/customers" className="text-slate-600 hover:text-slate-900">Customers</Link>
                        <button onClick={logout} className="text-slate-600 hover:text-slate-900">Log out</button>
                    </nav>
                </header>

                <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 lg:px-10">
                    {children}
                </main>
            </div>
        </div>
    );
}
