<?php

namespace App\Enums;

enum DeploymentKind: string
{
    case Deploy = 'deploy';
    case Rollback = 'rollback';
    case Redeploy = 'redeploy';
    // Create the folders on the server and upload the env files.
    case Bootstrap = 'bootstrap';
    // Take what's already in an in-place folder as the live release.
    case Adopt = 'adopt';
    // Move an earlier in-place deployment into releases/ and shared/.
    case Convert = 'convert';
    // Put a converted folder back the way it was.
    case UndoConvert = 'undo_convert';
    // Remove the folder's deploy lock by hand, whoever holds it.
    case Unlock = 'unlock';
    // One of the project's actions, run in the live release's folder.
    case Action = 'action';

    public function label(): string
    {
        return match ($this) {
            self::Deploy => 'Deploy',
            self::Rollback => 'Rollback',
            self::Redeploy => 'Redeploy',
            self::Bootstrap => 'Prepare',
            self::Adopt => 'Adopt',
            self::Convert => 'Convert to releases',
            self::UndoConvert => 'Undo conversion',
            self::Unlock => 'Break lock',
            self::Action => 'Action',
        };
    }
}
