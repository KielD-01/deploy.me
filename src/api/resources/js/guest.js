import { confirmIdentity, passkeyErrorMessage, passkeysSupported, signIn } from './passkeys';

// Sign-in and "confirm it's you" pages: one passkey button each, no Alpine needed.
function bind(selector, action) {
    const button = document.querySelector(selector);
    if (!button) return;

    const error = document.querySelector('[data-passkey-error]');
    const label = button.querySelector('[data-label]');
    const idle = label?.textContent;

    if (!passkeysSupported()) {
        if (error) error.textContent = 'This browser can’t use passkeys. Try Chrome or Safari on this Mac.';
        return;
    }

    button.addEventListener('click', async () => {
        button.disabled = true;
        if (error) error.textContent = '';
        if (label) label.textContent = 'Waiting for your passkey…';

        try {
            await action(button);
        } catch (e) {
            if (error) error.textContent = passkeyErrorMessage(e);
            if (label) label.textContent = idle;
            button.disabled = false;
        }
    });
}

bind('[data-passkey-login]', () => signIn());
bind('[data-passkey-confirm]', (button) => confirmIdentity(button.dataset.intended));
