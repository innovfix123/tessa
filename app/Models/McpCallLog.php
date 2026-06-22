<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpCallLog extends Model
{
    protected $table = 'mcp_call_log';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'client_internal_id',
        'access_token_id',
        'jsonrpc_method',
        'tool_name',
        'args_fingerprint',
        'status_code',
        'duration_ms',
        'ip_address',
        'user_agent',
        'error_message',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OauthClient::class, 'client_internal_id');
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(OauthAccessToken::class, 'access_token_id');
    }
}
