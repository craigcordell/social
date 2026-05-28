<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPostTarget extends Model
{
    use HasFactory;

    public const PUBLISH_STATUS_QUEUED = 'queued';

    public const PUBLISH_STATUS_PUBLISHING = 'publishing';

    public const PUBLISH_STATUS_PUBLISHED = 'published';

    public const PUBLISH_STATUS_FAILED = 'failed';

    public const DELETE_STATUS_QUEUED = 'queued';

    public const DELETE_STATUS_DELETING = 'deleting';

    public const DELETE_STATUS_DELETED = 'deleted';

    public const DELETE_STATUS_FAILED = 'failed';

    public const DELETE_STATUS_MANUAL_REQUIRED = 'manual_delete_required';

    protected $fillable = [
        'social_post_id',
        'connected_account_id',
        'provider',
        'publish_status',
        'delete_status',
        'provider_post_id',
        'provider_media_id',
        'provider_response',
        'publish_attempts',
        'delete_attempts',
        'last_error',
        'published_at',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'published_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }
}
