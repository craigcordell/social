<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAdOperation extends Model
{
    /** @use HasFactory<Factory<Model>> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const TYPE_BOOST = 'boost';

    public const TYPE_BUDGET_INCREASE = 'budget_increase';

    public const TYPE_STATUS_UPDATE = 'status_update';

    public const TYPE_AD_BUDGET_INCREASE = 'ad_budget_increase';

    protected $fillable = [
        'owner_id',
        'ad_account_id',
        'type',
        'idempotency_key',
        'request_hash',
        'status',
        'request_payload',
        'response_payload',
        'meta_campaign_id',
        'meta_ad_set_id',
        'meta_creative_id',
        'meta_ad_id',
        'error_message',
        'completed_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }
}
