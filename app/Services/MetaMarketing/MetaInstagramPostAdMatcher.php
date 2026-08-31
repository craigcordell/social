<?php

namespace App\Services\MetaMarketing;

class MetaInstagramPostAdMatcher
{
    public function __construct(
        private readonly MetaInstagramCreativeReference $creativeReference,
        private readonly MetaInstagramCreativeMatcher $creativeMatcher,
        private readonly MetaInstagramPostMediaIndex $mediaIndex,
        private readonly InstagramShortcodeCodec $shortcodeCodec,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $ads
     * @param  array<string, array<string, mixed>>  $mediaById
     * @return list<string>
     */
    public function ids(array $ads, array $mediaById, string $postUrl): array
    {
        $shortcode = (string) $this->shortcodeCodec->fromPermalink($postUrl);
        $availableAdIds = $this->availableAdIds($ads);
        $adIds = [];

        foreach ($ads as $ad) {
            $adId = $ad['id'] ?? null;

            if (! is_string($adId) || ! ctype_digit($adId)) {
                continue;
            }

            if ($this->creativeMatcher->matches(
                $ad,
                $this->mediaForAd($ad, $mediaById),
                [
                    'instagram_media_id' => null,
                    'instagram_shortcode' => $shortcode,
                ],
            )) {
                $adIds[$adId] = $adId;
            }
        }

        foreach ($this->mediaIndex->boostAdIds($mediaById, $shortcode) as $adId) {
            if (! array_key_exists($adId, $availableAdIds)) {
                continue;
            }

            $adIds[$adId] = $adId;
        }

        return array_values($adIds);
    }

    /**
     * @param  list<array<string, mixed>>  $ads
     * @return array<string, string>
     */
    protected function availableAdIds(array $ads): array
    {
        $adIds = [];

        foreach ($ads as $ad) {
            $adId = $ad['id'] ?? null;

            if (is_string($adId) && ctype_digit($adId)) {
                $adIds[$adId] = $adId;
            }
        }

        return $adIds;
    }

    /**
     * @param  array<string, mixed>  $ad
     * @param  array<string, array<string, mixed>>  $mediaById
     * @return array<string, mixed>
     */
    protected function mediaForAd(array $ad, array $mediaById): array
    {
        foreach ($this->creativeReference->mediaIds($ad) as $mediaId) {
            if (array_key_exists($mediaId, $mediaById)) {
                return $mediaById[$mediaId];
            }
        }

        return [];
    }
}
