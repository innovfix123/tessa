<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OauthClient extends Model
{
    protected $table = 'oauth_clients';

    protected $fillable = [
        'client_id',
        'secret_hash',
        'client_name',
        'redirect_uris',
        'token_endpoint_auth_method',
        'grant_types',
        'response_types',
        'scope',
        'software_id',
        'software_version',
        'contacts',
        'created_via',
        'revoked_at',
    ];

    protected $hidden = ['secret_hash'];

    protected $casts = [
        'redirect_uris' => 'array',
        'revoked_at' => 'datetime',
    ];

    public function accessTokens(): HasMany
    {
        return $this->hasMany(OauthAccessToken::class, 'client_internal_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(OauthRefreshToken::class, 'client_internal_id');
    }

    public function authorizationCodes(): HasMany
    {
        return $this->hasMany(OauthAuthorizationCode::class, 'client_internal_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isPublic(): bool
    {
        return $this->token_endpoint_auth_method === 'none';
    }

    public function isValidRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirect_uris ?? [], true);
    }

    public function hasGrantType(string $grant): bool
    {
        return in_array($grant, preg_split('/\s+/', (string) $this->grant_types), true);
    }

    public static function hashSecret(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function checkSecret(string $plain): bool
    {
        return $this->secret_hash !== null
            && hash_equals($this->secret_hash, self::hashSecret($plain));
    }
}
