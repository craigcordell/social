<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthDebugAttempt extends Model
{
    protected $table = 'oauth_debug_attempts';

    protected $fillable = [
        'provider',
        'owner_id',
        'status',
        'callback_query',
        'token_summary',
        'permissions_response',
        'pages_response',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'callback_query' => 'array',
            'token_summary' => 'array',
            'permissions_response' => 'array',
            'pages_response' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }
}
