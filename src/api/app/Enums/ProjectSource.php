<?php

namespace App\Enums;

enum ProjectSource: string
{
    case Github = 'github';
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Github => 'GitHub',
            self::Local => 'Local folder',
        };
    }
}
