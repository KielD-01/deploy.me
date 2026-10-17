<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['alias', 'host_pattern', 'key_type', 'known_hosts_line', 'fingerprint', 'trusted_by', 'trusted_at', 'revoked_at'])]
class SshHostKey extends Model
{
    protected function casts(): array
    {
        return [
            'trusted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function trustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trusted_by');
    }
}
