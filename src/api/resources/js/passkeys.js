import { InvalidDomainError, NotSupportedError, PasskeyExistsError, Passkeys, UserCancelledError } from '@laravel/passkeys';

// Laravel's web routes need the CSRF token; send it explicitly with every call.
const token = document.querySelector('meta[name="csrf-token"]')?.content;

Passkeys.configure({
    fetch: {
        headers: {
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
    },
});

export const passkeysSupported = () => Passkeys.isSupported();

// A confirmation older than auth.password_timeout makes management routes answer 423.
export const needsConfirmation = (error) => (error?.status ?? error?.response?.status) === 423 || /confirm/i.test(error?.message ?? '');

export function passkeyErrorMessage(error) {
    if (error instanceof UserCancelledError) return 'Cancelled. Try again when you’re ready.';
    if (error instanceof NotSupportedError) return 'This browser can’t use passkeys.';
    if (error instanceof InvalidDomainError) return 'Passkeys only work at https://deployme.localhost.';
    if (error instanceof PasskeyExistsError) return 'This device already has a passkey for deploy.me.';

    return error?.message || 'The passkey didn’t work. Try again.';
}

export async function signIn() {
    const response = await Passkeys.verify({ remember: true });
    window.location.assign(response?.redirect || '/');
}

// "Confirm it's you": the same ceremony, checked against the signed-in user.
export async function confirmIdentity(intended) {
    await Passkeys.verify({ routes: { options: '/passkeys/confirm/options', submit: '/passkeys/confirm' } });
    window.location.assign(intended || '/');
}

export const registerPasskey = (name) => Passkeys.register({ name });
