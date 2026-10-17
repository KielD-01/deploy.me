<?php

namespace App\Livewire\Settings;

use App\Actions\Credentials\SaveGitHubToken;
use App\Enums\AuditAction;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Models\Credential;
use App\Models\SshHostKey;
use App\Models\User;
use App\Platform\GitHub\GitHubException;
use App\Platform\Ssh\SshConfigCompiler;
use App\Platform\Ssh\SshConfigReader;
use App\Support\Alert;
use App\Support\Audit;
use App\Support\Settings;
use App\Support\Ui;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Settings')]
class Index extends Component
{
    use ConfirmsIdentity;

    public const TABS = ['github' => 'GitHub', 'ssh' => 'SSH', 'schedules' => 'Schedules', 'alerts' => 'Alerts', 'appearance' => 'Appearance', 'account' => 'Account'];

    #[Url]
    public string $tab = 'github';

    // Set by the one-time enrollment link: prompt for the first passkey or password.
    #[Url]
    public bool $enroll = false;

    // Back from confirming with a passkey: open the token form.
    #[Url]
    public bool $adding = false;

    public string $token = '';

    /** @var array<string, int|string> setting => value, as the selects hold them */
    public array $schedules = [];

    // Left empty on purpose: a stored bot token is never rendered back.
    public string $telegramToken = '';

    public string $telegramChatId = '';

    public bool $telegramSet = false;

    // Account → Password: never kept after a save, whatever happens.
    public string $password = '';

    public string $passwordConfirmation = '';

    // Account → Authenticator: the code that proves the app is set up, and the
    // recovery codes, shown once right after they're made.
    public string $twoFactorCode = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(): void
    {
        if ($this->enroll) {
            $this->tab = 'account';
        }

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'github';
        }

        // The form opens only for a fresh confirmation, whatever the URL says.
        $this->adding = $this->adding && $this->identityConfirmed();

        $this->schedules = collect(Settings::DEFAULTS)->map(fn ($default, string $key) => Settings::int($key))->all();
        $this->telegramChatId = Settings::string('telegram_chat_id');
        $this->telegramSet = Settings::string('telegram_bot_token') !== '';
    }

    public function saveSchedules(): void
    {
        $this->reset('notice', 'problem');

        foreach (Settings::CHOICES as $key => $choices) {
            $value = (int) ($this->schedules[$key] ?? Settings::DEFAULTS[$key]);

            if (! array_key_exists($value, $choices)) {
                $this->problem = 'Pick one of the listed choices.';

                return;
            }

            Settings::set($key, $value);
        }

        $this->notice = 'Schedules saved.';
    }

    /**
     * The bot token and the chat to send to. An empty token field keeps the
     * one already stored, so saving the chat id doesn't wipe it.
     */
    public function saveAlerts(): void
    {
        $this->reset('notice', 'problem');

        // The chat gets every failure the runs report: changing where they go
        // is as sensitive as a GitHub token.
        if (! $this->confirmIdentity(route('settings', ['tab' => 'alerts']))) {
            return;
        }

        $this->validate([
            'telegramToken' => ['nullable', 'string', 'max:200', 'regex:/^\d+:[A-Za-z0-9_-]+$/'],
            'telegramChatId' => ['nullable', 'string', 'max:100', 'regex:/^(-?\d+|@[A-Za-z0-9_]+)$/'],
        ], [
            'telegramToken.regex' => 'A bot token looks like 123456789:AA… — BotFather sends it when you create the bot.',
            'telegramChatId.regex' => 'A chat id is a number, or @name for a public channel.',
        ]);

        if ($this->telegramToken !== '') {
            Settings::setString('telegram_bot_token', $this->telegramToken);
        }

        Settings::setString('telegram_chat_id', $this->telegramChatId);

        $this->telegramToken = '';
        $this->telegramSet = Settings::string('telegram_bot_token') !== '';
        $this->notice = 'Alerts saved.';
    }

    public function sendTestAlert(): void
    {
        $this->reset('notice', 'problem');

        if (! Alert::configured()) {
            $this->problem = 'Set a bot token and a chat id first.';

            return;
        }

        if (Alert::send('✓ deploy.me can reach you here. This is the message a failed run would send.')) {
            $this->notice = 'Sent — check Telegram.';

            return;
        }

        $this->problem = 'Telegram didn’t take it. Check the token and the chat id, and that you have written to the bot at least once.';
    }

    public function forgetAlerts(): void
    {
        $this->reset('notice', 'problem');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'alerts']))) {
            return;
        }

        Settings::setString('telegram_bot_token', '');
        Settings::setString('telegram_chat_id', '');
        $this->telegramToken = '';
        $this->telegramChatId = '';
        $this->telegramSet = false;
        $this->notice = 'Alerts switched off.';
    }

    public function startAddingToken(): void
    {
        $this->reset('notice', 'problem');

        if ($this->confirmIdentity(route('settings', ['tab' => 'github', 'adding' => 1]))) {
            $this->adding = true;
        }
    }

    public function addToken(SaveGitHubToken $save): void
    {
        $token = trim($this->token);
        // Never keep a token in the page's state, whatever happens next.
        $this->reset('token', 'notice', 'problem');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'github', 'adding' => 1]))) {
            return;
        }

        if (! SaveGitHubToken::looksLikeToken($token)) {
            $this->problem = 'That doesn’t look like a GitHub token. Classic tokens start with ghp_.';

            return;
        }

        try {
            $credential = $save($token);
        } catch (GitHubException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $reach = count($credential->owners ?? []);
        $this->adding = false;
        $this->notice = "Saved {$credential->login}’s token. It reaches {$reach} ".Str::plural('owner', $reach).'.';
    }

    public function checkToken(int $id, SaveGitHubToken $save): void
    {
        $this->reset('notice', 'problem');
        $credential = Credential::query()->findOrFail($id);

        try {
            $save($credential->token);
            $this->notice = "{$credential->login}’s token works.";
        } catch (GitHubException $e) {
            $credential->update(['last_error' => $e->getMessage()]);
            $this->problem = "{$credential->login}: {$e->getMessage()}";
        }
    }

    public function removeToken(int $id): void
    {
        $this->reset('notice', 'problem');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'github']))) {
            return;
        }

        $credential = Credential::query()->findOrFail($id);
        $credential->delete();
        Audit::record(AuditAction::CredentialRemoved, ['subject' => "GitHub token for {$credential->login}", 'detail' => $credential->token_hint]);
        $this->notice = "Removed {$credential->login}’s token.";
    }

    /**
     * Sets or changes the sign-in password: a second way in, for a device that
     * can't use a passkey. The enrollment link counts as the confirmation.
     */
    public function savePassword(): void
    {
        $password = $this->password;
        $confirmation = $this->passwordConfirmation;
        $this->reset('password', 'passwordConfirmation', 'notice', 'problem');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'account']))) {
            return;
        }

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'min:12', 'max:200', 'confirmed']],
            ['password.min' => 'Use at least 12 characters.', 'password.confirmed' => 'The two passwords don’t match.'],
        );

        if ($validator->fails()) {
            $this->problem = $validator->errors()->first();

            return;
        }

        $user = auth()->user();
        $had = $user->hasPassword();
        $user->forceFill(['password' => $password, 'password_set_at' => now()])->save();
        Audit::record(AuditAction::PasswordSet, ['subject' => $user->email, 'detail' => $had ? 'changed' : 'first set']);
        $this->notice = $had ? 'Password changed.' : 'Password set. You can sign in with it on any device.';
    }

    /**
     * Back to passkeys only. Authenticator codes go with the password they guarded.
     */
    public function removePassword(DisableTwoFactorAuthentication $disable): void
    {
        $this->reset('notice', 'problem');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'account']))) {
            return;
        }

        $user = auth()->user();

        // Under the user's row lock, which removing a passkey takes too: the
        // passkey this counts can't be going at the same moment.
        $removed = DB::transaction(function () use ($user, $disable): bool {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $user->passkeys()->exists()) {
                return false;
            }

            $disable($user);
            // A random value nobody knows, as before a password was chosen.
            $user->forceFill(['password' => Str::random(64), 'password_set_at' => null])->save();

            return true;
        });

        if (! $removed) {
            $this->problem = 'Your password is your only way in. Add a passkey first.';

            return;
        }

        Audit::record(AuditAction::PasswordRemoved, ['subject' => $user->email]);
        $this->notice = 'Password removed. You sign in with a passkey only.';
    }

    public function enableTwoFactor(EnableTwoFactorAuthentication $enable): void
    {
        $this->reset('notice', 'problem', 'twoFactorCode', 'recoveryCodes');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'account']))) {
            return;
        }

        if (! auth()->user()->hasPassword()) {
            $this->problem = 'Authenticator codes guard the password: set one first.';

            return;
        }

        $enable(auth()->user());
    }

    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirm): void
    {
        $code = preg_replace('/\s+/', '', $this->twoFactorCode);
        $this->reset('notice', 'problem', 'twoFactorCode');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'account']))) {
            return;
        }

        $user = auth()->user();

        try {
            $confirm($user, $code);
        } catch (ValidationException) {
            $this->problem = 'That code didn’t match. Check the app’s clock, and use the newest code.';

            return;
        }

        $this->recoveryCodes = $user->fresh()->recoveryCodes();
        Audit::record(AuditAction::TwoFactorEnabled, ['subject' => $user->email]);
        $this->notice = 'Authenticator codes are on. Keep the recovery codes below somewhere safe.';
    }

    public function newRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $this->reset('notice', 'problem');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'account']))) {
            return;
        }

        $generate(auth()->user());
        $this->recoveryCodes = auth()->user()->fresh()->recoveryCodes();
        $this->notice = 'New recovery codes. The old ones no longer work.';
    }

    public function disableTwoFactor(DisableTwoFactorAuthentication $disable): void
    {
        $this->reset('notice', 'problem', 'twoFactorCode', 'recoveryCodes');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'account']))) {
            return;
        }

        $user = auth()->user();
        $wasOn = $user->hasTwoFactor();
        $disable($user);

        if ($wasOn) {
            Audit::record(AuditAction::TwoFactorDisabled, ['subject' => $user->email]);
        }

        $this->notice = $wasOn ? 'Authenticator codes are off.' : null;
    }

    /**
     * Theme, mode or density. The page loads again, since a theme brings its own layout.
     */
    public function appearance(string $key, string $value): void
    {
        $this->reset('notice', 'problem');

        if (! in_array($key, ['theme', 'mode', 'density'], true)) {
            return;
        }

        try {
            Ui::save(auth()->user(), [$key => $value]);
        } catch (\InvalidArgumentException) {
            $this->problem = 'That isn’t one of the choices.';

            return;
        }

        $this->redirectRoute('settings', ['tab' => 'appearance']);
    }

    public function render(SshConfigReader $sshConfig, SshConfigCompiler $compiler): View
    {
        $user = auth()->user();
        $aliases = collect($sshConfig->aliases());
        $compiled = $compiler->path();

        return view('livewire.settings.index', [
            'user' => $user,
            'tabs' => self::TABS,
            'choices' => Settings::CHOICES,
            'passkeys' => $user->passkeys()->latest()->get(),
            'appearance' => Ui::preferences($user),
            'credentials' => Credential::query()->withCount('projects')->orderBy('login')->get(),
            'ssh' => [
                'servers' => $aliases->reject->looksLikeGitHost()->count(),
                'git' => $aliases->filter->looksLikeGitHost()->count(),
                'trusted' => SshHostKey::query()->whereNull('revoked_at')->distinct()->count('alias'),
                'agent' => (bool) config('deployme.ssh.agent_socket'),
                'path' => $compiled,
                'compiledAt' => is_file($compiled) ? filemtime($compiled) : null,
            ],
        ]);
    }
}
