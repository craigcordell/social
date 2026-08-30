<?php

namespace App\Services\MetaMarketing;

final class MetaInstagramMatchingAds
{
    public function __construct(
        private readonly MetaInstagramCreativeMatcher $matcher,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $ads
     * @param  array<string, mixed>  $media
     * @param  array<string, ?string>  $reference
     * @return list<string>
     */
    public function ids(array $ads, array $media, array $reference): array
    {
        $adIds = [];

        foreach ($ads as $ad) {
            $adId = $ad['id'] ?? null;

            if ($this->matcher->matches($ad, $media, $reference) && is_string($adId) && ctype_digit($adId)) {
                $adIds[$adId] = $adId;
            }
        }

        return array_values($adIds);
    }
}
