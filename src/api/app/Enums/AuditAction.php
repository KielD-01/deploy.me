<?php

namespace App\Enums;

/**
 * What an audit entry records. Trusting a host key, breaking a lock, a
 * credential, how you sign in, something removed, and every command run on a
 * server.
 */
enum AuditAction: string
{
    case HostKeyTrusted = 'host_key.trusted';
    case LockBroken = 'lock.broken';
    case CredentialSaved = 'credential.saved';
    case CredentialRemoved = 'credential.removed';
    case AccountCreated = 'account.created';
    case PasswordSet = 'password.set';
    case PasswordRemoved = 'password.removed';
    case TwoFactorEnabled = 'two_factor.enabled';
    case TwoFactorDisabled = 'two_factor.disabled';
    case ServerRemoved = 'server.removed';
    case ProjectRemoved = 'project.removed';
    case TargetRemoved = 'target.removed';
    case RouteChanged = 'server.route_changed';
    case TargetFolderChanged = 'target.folder_changed';
    case ActionCreated = 'action.created';
    case ActionUpdated = 'action.updated';
    case ActionRemoved = 'action.removed';
    case ActionStarted = 'action.started';
    case ProjectsExported = 'projects.exported';
    case ProjectsImported = 'projects.imported';
    case RemoteCommand = 'ssh.command';

    public function label(): string
    {
        return match ($this) {
            self::HostKeyTrusted => 'Trusted a host key',
            self::LockBroken => 'Broke a deploy lock',
            self::CredentialSaved => 'Saved a GitHub token',
            self::CredentialRemoved => 'Removed a GitHub token',
            self::AccountCreated => 'Created the account',
            self::PasswordSet => 'Set the sign-in password',
            self::PasswordRemoved => 'Removed the sign-in password',
            self::TwoFactorEnabled => 'Turned on authenticator codes',
            self::TwoFactorDisabled => 'Turned off authenticator codes',
            self::ServerRemoved => 'Removed a server',
            self::ProjectRemoved => 'Removed a project',
            self::TargetRemoved => 'Removed a target',
            self::RouteChanged => 'Changed how a server is reached',
            self::TargetFolderChanged => 'Changed a target’s folder',
            self::ActionCreated => 'Made an action',
            self::ActionUpdated => 'Changed an action',
            self::ActionRemoved => 'Removed an action',
            self::ActionStarted => 'Started an action',
            self::ProjectsExported => 'Exported projects',
            self::ProjectsImported => 'Imported projects',
            self::RemoteCommand => 'Ran a command on a server',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::HostKeyTrusted => 'shield',
            self::LockBroken => 'lock',
            self::CredentialSaved, self::CredentialRemoved, self::AccountCreated, self::PasswordSet, self::PasswordRemoved, self::TwoFactorEnabled, self::TwoFactorDisabled => 'key',
            self::ServerRemoved, self::ProjectRemoved, self::TargetRemoved => 'trash',
            self::RemoteCommand => 'terminal',
            self::RouteChanged => 'globe',
            self::TargetFolderChanged => 'folder',
            self::ActionCreated, self::ActionUpdated => 'pencil',
            self::ActionRemoved => 'trash',
            self::ActionStarted => 'play',
            self::ProjectsExported, self::ProjectsImported => 'copy',
        };
    }

    /**
     * Deliberate acts, as against the commands deploys and probes run.
     */
    public function byHand(): bool
    {
        return $this !== self::RemoteCommand;
    }
}
