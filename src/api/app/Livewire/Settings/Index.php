<?php

namespace App\Livewire\Settings;

use App\Actions\Credentials\SaveGitHubToken;
use App\Enums\AuditAction;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Models\Credential;
use App\Models\SshHostKey;
use App\Platform\GitHub\GitHubException;
use App\Platform\Ssh\SshConfigCompiler;
use App\Platform\Ssh\SshConfigReader;
use App\Support\Alert;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Settings')]
class Index extends Component
{
    use ConfirmsIdentity;

    public const TABS = ['github' => 'GitHub', 'ssh' => 'SSH', 'schedules' => 'Schedules', 'alerts' => 'Alerts', 'account' => 'Account'];

    #[Url]
    public string $tab = 'github';

    // Set by the one-time enrollment link: prompt for the first passkey.
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
