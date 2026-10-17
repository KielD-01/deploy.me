<?php

namespace Database\Factories;

use App\Models\Credential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    public function definition(): array
    {
        $token = 'ghp_'.Str::random(36);

        return [
            'provider' => 'github',
            'login' => 'KielD-01',
            'owners' => ['KielD-01'],
            'token' => $token,
            'token_hint' => 'ghp_••••'.substr($token, -4),
            'token_type' => 'classic',
            'scopes' => ['repo', 'read:packages'],
            'verified_at' => now(),
        ];
    }
}
