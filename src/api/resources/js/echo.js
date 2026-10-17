import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// The key comes from a meta tag rendered by the layout rather than a VITE_ env
// var, so rebuilding assets isn't needed when `deployme:setup` rotates keys.
const key = document.querySelector('meta[name="reverb-key"]')?.content;

if (key) {
    // Same origin: the shared edge routes wss://<host>/app/* to deployme-reverb.
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: window.location.hostname,
        wsPort: 80,
        wssPort: 443,
        forceTLS: window.location.protocol === 'https:',
        enabledTransports: ['ws', 'wss'],
    });
}
