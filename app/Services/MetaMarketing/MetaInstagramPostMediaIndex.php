<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;

class MetaInstagramPostMediaIndex
{
    public function __construct(
        private readonly MetaInstagramMediaClient $mediaClient,
        private readonly MetaInstagramCreativeReference $creativeReference,
        private readonly MetaInstagramBoostAdIds $boostAdIds,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $ads
     * @return array<string, array<string, mixed>>
     */
    public function forAds(Owner $owner, array $ads): array
    {
        $mediaIds = [];

        foreach ($ads as $ad) {
            foreach ($this->creativeReference->mediaIds($ad) as $mediaId) {
                $mediaIds[$mediaId] = $mediaId;
            }
        }

        /** @var array<string, array<string, mixed>> $mediaById */
        $mediaById = collect($this->mediaClient->getMany($owner, array_values($mediaIds)))
            ->keyBy(static fn (array $media): string => (string) $media['id'])->all();

        return $mediaById;
    }

    /**
     * @param  array<string, array<string, mixed>>  $mediaById
     * @return list<string>
     */
    public function boostAdIds(array $mediaById, string $shortcode): array
    {
        $adIds = [];

        foreach ($mediaById as $media) {
            if (($media['shortcode'] ?? null) !== $shortcode) {
                continue;
            }

            foreach ($this->boostAdIds->fromMedia($media) as $adId) {
                $adIds[$adId] = $adId;
            }
        }

        return array_values($adIds);
    }
}
