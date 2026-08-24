<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * .claude/SECURITY.md #5: "Google OAuth tokens: encrypt at rest (Laravel
 * encrypted casts)." oauth_token/refresh_token are `encrypted` casts — every
 * read decrypts using APP_KEY, every write encrypts before hitting the DB.
 * The column type is `text`, not `string`: encrypted ciphertext (base64 of
 * IV + MAC + payload) runs several times longer than the original token and
 * would silently truncate in a varchar(255).
 */
class GbpConnection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'oauth_token',
        'refresh_token',
        'token_expires_at',
        'location_id',
        'review_link',
        'status',
        'last_synced_at',
        'revoked_alert_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'oauth_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'revoked_alert_sent_at' => 'datetime',
        ];
    }
}
