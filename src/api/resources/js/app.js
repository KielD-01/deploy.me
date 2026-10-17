import './echo';
import deployLog from './deploy-log';
import { consoleShell, tmuxKeys, tmuxSession } from './console';
import { needsConfirmation, passkeyErrorMessage, registerPasskey } from './passkeys';

const confirmUrl = '/user/confirm-password';
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// Alpine ships with Livewire; register components before it starts.
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    // Run page: the live deploy log.
    Alpine.data('deployLog', deployLog);

    // The Console theme: tmux's keys, the session's panes, a shell pane's prompt.
    Alpine.data('tmuxKeys', tmuxKeys);
    Alpine.data('tmuxSession', tmuxSession);
    Alpine.data('consoleShell', consoleShell);

    // Realtime connection state for the header indicator.
    Alpine.data('liveStatus', () => ({
        state: 'connecting',
        init() {
            const connection = window.Echo?.connector?.pusher?.connection;
            if (!connection) {
                this.state = 'unavailable';
                return;
            }
            this.state = connection.state;
            connection.bind('state_change', ({ current }) => (this.state = current));
        },
        get live() {
            return this.state === 'connected';
        },
        get label() {
            return { connected: 'Live', connecting: 'Connecting' }[this.state] ?? 'Offline';
        },
    }));

    // Light/dark toggle. No stored choice means "follow the OS".
    Alpine.data('themeToggle', () => ({
        dark: false,
        init() {
            const set = document.documentElement.dataset.theme;
            this.dark = set ? set === 'deployme-dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        },
        toggle() {
            this.dark = !this.dark;
            const theme = this.dark ? 'deployme-dark' : 'deployme';
            document.documentElement.dataset.theme = theme;
            try {
                localStorage.setItem('deployme-theme', theme);
            } catch {
                // Storage can be unavailable (private mode); the toggle still works for this page.
            }
        },
    }));

    // Settings → Passkeys: register one on this device.
    Alpine.data('passkeyRegister', () => ({
        name: '',
        busy: false,
        error: '',
        async register() {
            if (!this.name.trim()) {
                this.error = 'Name this passkey, like “MacBook Pro”.';
                return;
            }
            this.busy = true;
            this.error = '';
            try {
                await registerPasskey(this.name.trim());
                window.location.assign(window.location.pathname);
            } catch (e) {
                if (needsConfirmation(e)) {
                    window.location.assign(confirmUrl);
                    return;
                }
                this.error = passkeyErrorMessage(e);
                this.busy = false;
            }
        },
    }));

    // Settings → Passkeys: remove one (the route asks for a fresh confirmation).
    Alpine.data('passkeyRemove', (url, name) => ({
        busy: false,
        async remove() {
            if (!window.confirm(`Remove “${name}”?`)) return;
            this.busy = true;
            const response = await fetch(url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });
            if (response.status === 423) {
                window.location.assign(confirmUrl);
                return;
            }
            // The last way in stays (DeletePasskeyKeepingAWayIn).
            if (response.status === 422) {
                const body = await response.json().catch(() => ({}));
                window.alert(body?.errors?.passkey?.[0] ?? body?.message ?? 'That passkey can’t be removed.');
                this.busy = false;
                return;
            }
            window.location.assign(window.location.pathname);
        },
    }));
});
