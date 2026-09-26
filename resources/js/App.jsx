import { Navigate, Route, Routes } from 'react-router-dom';
import { useAuth } from './context/AuthContext';
import Layout from './components/Layout';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import CustomerList from './pages/CustomerList';
import CustomerDetail from './pages/CustomerDetail';
import PlanChange from './pages/PlanChange';
import InvoiceDetail from './pages/InvoiceDetail';

function RequireAuth({ children }) {
    const { user, loading } = useAuth();

    if (loading) {
        return <div className="flex min-h-screen items-center justify-center text-slate-500">Loading…</div>;
    }

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    return <Layout>{children}</Layout>;
}

export default function App() {
    return (
        <Routes>
            <Route path="/login" element={<Login />} />
            <Route path="/" element={<RequireAuth><Dashboard /></RequireAuth>} />
            <Route path="/customers" element={<RequireAuth><CustomerList /></RequireAuth>} />
            <Route path="/customers/:id" element={<RequireAuth><CustomerDetail /></RequireAuth>} />
            <Route path="/customers/:id/change-plan" element={<RequireAuth><PlanChange /></RequireAuth>} />
            <Route path="/invoices/:id" element={<RequireAuth><InvoiceDetail /></RequireAuth>} />
        </Routes>
    );
}
