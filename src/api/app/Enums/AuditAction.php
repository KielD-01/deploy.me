<?php

namespace App\Enums;

/**
 * What an audit entry records. Trusting a host key, breaking a lock, a
 * credential, something removed, and every command run on a server.
 */
enum AuditAction: string
{
    case HostKeyTrusted = 'host_key.trusted';
    case LockBroken = 'lock.broken';
    case CredentialSaved = 'credential.saved';
    case CredentialRemoved = 'credential.removed';
    case ServerRemoved = 'server.removed';
    case ProjectRemoved = 'project.removed';
    case TargetRemoved = 'target.removed';
    case RemoteCommand = 'ssh.command';

    public function label(): string
    {
        return match ($this) {
            self::HostKeyTrusted => 'Trusted a host key',
            self::LockBroken => 'Broke a deploy lock',
            self::CredentialSaved => 'Saved a GitHub token',
            self::CredentialRemoved => 'Removed a GitHub token',
            self::ServerRemoved => 'Removed a server',
            self::ProjectRemoved => 'Removed a project',
            self::TargetRemoved => 'Removed a target',
            self::RemoteCommand => 'Ran a command on a server',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::HostKeyTrusted => 'shield',
            self::LockBroken => 'lock',
            self::CredentialSaved, self::CredentialRemoved => 'key',
            self::ServerRemoved, self::ProjectRemoved, self::TargetRemoved => 'trash',
            self::RemoteCommand => 'terminal',
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
