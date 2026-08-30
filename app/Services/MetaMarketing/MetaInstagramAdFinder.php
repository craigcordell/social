<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;
use Illuminate\Validation\ValidationException;

final class MetaInstagramAdFinder
{
    public function __construct(
        private readonly MetaMarketingApiClient $meta,
        private readonly MetaInstagramMediaResolver $mediaResolver,
        private readonly MetaInstagramBoostAdIds $boostAdIds,
        private readonly MetaInstagramMatchingAds $matchingAds,
    ) {}

    /**
     * @param  array<string, ?string>  $reference
     * @return array{ad: array<string, mixed>, media: ?array<string, mixed>}
     */
    public function find(Owner $owner, array $reference): array
    {
        $media = is_string($reference['instagram_media_id'])
            ? $this->mediaResolver->get($owner, $reference['instagram_media_id'])
            : null;
        $adIds = $this->boostAdIds->fromMedia($media);
        $ads = [];

        if ($adIds === []) {
            $ads = $this->meta->allAds($owner);
            $adIds = $this->matchingAds->ids($ads, $media ?? [], $reference);
        }

        if ($adIds === [] && is_string($reference['instagram_shortcode'])) {
            $media = $this->mediaResolver->find($owner, $ads, $reference['instagram_shortcode']);
            $adIds = $this->boostAdIds->fromMedia($media);

            if ($adIds === [] && $media !== null) {
                $adIds = $this->matchingAds->ids($ads, $media, $reference);
            }
        }

        if ($adIds === []) {
            throw ValidationException::withMessages([
                'reference' => 'No Meta ad was found for the supplied Instagram reference.',
            ]);
        }

        if (count($adIds) > 1) {
            throw ValidationException::withMessages([
                'reference' => 'The Instagram media is associated with multiple Meta ads. Use the exact Marketing API ad ID.',
            ]);
        }

        $ad = $this->meta->ad($owner, $adIds[0]);

        return [
            'ad' => $ad,
            'media' => $media ?? $this->mediaResolver->fromAd($ad, $reference),
        ];
    }
}
