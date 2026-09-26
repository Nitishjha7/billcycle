import axios from 'axios';

/**
 * Sanctum SPA mode: a normal session cookie is the auth, not a bearer
 * token. withCredentials sends that cookie on every request; the CSRF
 * cookie route below is what makes the *first* stateful request (login)
 * work at all -- Sanctum checks the X-XSRF-TOKEN header against it.
 *
 * axios's automatic XSRF-cookie-to-header behaviour is unreliable with a
 * relative baseURL, so the header is read from document.cookie and set
 * explicitly instead of relying on withXSRFToken.
 */
function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
}

const client = axios.create({
    baseURL: '/api',
    withCredentials: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    },
});

client.interceptors.request.use((config) => {
    const token = readCookie('XSRF-TOKEN');
    if (token) {
        config.headers['X-XSRF-TOKEN'] = token;
    }
    return config;
});

export function ensureCsrfCookie() {
    return axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export default client;
