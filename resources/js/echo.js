import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';

// Reverb memakai protokol Pusher; peramban terhubung ke host yang dipublikasikan kontainer.
// Channel private diotorisasi di /api/broadcasting/auth memakai cookie sesi; Echo mengirim token CSRF
// dari <meta name="csrf-token">.
export const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: scheme === 'https',
    // Hanya transport yang sesuai skema; mencoba keduanya menghasilkan error wss palsu di console.
    enabledTransports: scheme === 'https' ? ['wss'] : ['ws'],
    authEndpoint: '/api/broadcasting/auth',
});
