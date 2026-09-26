import { createContext, useContext, useEffect, useState } from 'react';
import client, { ensureCsrfCookie } from '../api/client';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        client
            .get('/user')
            .then((res) => setUser(res.data.user))
            .catch(() => setUser(null))
            .finally(() => setLoading(false));
    }, []);

    async function login(email, password) {
        await ensureCsrfCookie();
        const res = await client.post('/login', { email, password });
        setUser(res.data.user);
    }

    async function logout() {
        await client.post('/logout');
        setUser(null);
    }

    return (
        <AuthContext.Provider value={{ user, loading, login, logout }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    return useContext(AuthContext);
}
