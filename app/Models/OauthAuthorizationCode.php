<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OauthAuthorizationCode extends Model
{
    protected $table = 'oauth_authorization_codes';

    protected $fillable = [
        'code_hash',
        'client_internal_id',
        'user_id',
        'redirect_uri',
        'scope',
        'audience',
        'code_challenge',
        'code_challenge_method',
        'expires_at',
        'redeemed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(OauthClient::class, 'client_internal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    public static function hashCode(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
