<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialPost extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_PARTIAL_FAILED = 'partial_failed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETE_QUEUED = 'delete_queued';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'owner_id',
        'external_id',
        'idempotency_key',
        'caption',
        'image_url',
        'link_url',
        'scheduled_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    public function refreshAggregateStatus(): void
    {
        $targets = $this->targets()->get();

        if ($targets->isEmpty()) {
            return;
        }

        $this->status = match (true) {
            $targets->every(fn (SocialPostTarget $target): bool => $target->delete_status === SocialPostTarget::DELETE_STATUS_DELETED) => self::STATUS_DELETED,
            $targets->contains(fn (SocialPostTarget $target): bool => $target->delete_status === SocialPostTarget::DELETE_STATUS_QUEUED || $target->delete_status === SocialPostTarget::DELETE_STATUS_DELETING) => self::STATUS_DELETE_QUEUED,
            $targets->every(fn (SocialPostTarget $target): bool => $target->publish_status === SocialPostTarget::PUBLISH_STATUS_PUBLISHED) => self::STATUS_PUBLISHED,
            $targets->every(fn (SocialPostTarget $target): bool => $target->publish_status === SocialPostTarget::PUBLISH_STATUS_FAILED) => self::STATUS_FAILED,
            $targets->contains(fn (SocialPostTarget $target): bool => $target->publish_status === SocialPostTarget::PUBLISH_STATUS_FAILED) => self::STATUS_PARTIAL_FAILED,
            $targets->contains(fn (SocialPostTarget $target): bool => $target->publish_status === SocialPostTarget::PUBLISH_STATUS_PUBLISHING) => self::STATUS_PUBLISHING,
            default => $this->scheduled_at && $this->scheduled_at->isFuture() ? self::STATUS_SCHEDULED : self::STATUS_QUEUED,
        };

        $this->save();
    }
}
