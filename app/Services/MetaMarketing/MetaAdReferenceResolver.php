<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;
use Illuminate\Support\Arr;

final class MetaAdReferenceResolver
{
    public function __construct(
        private readonly MetaMarketingApiClient $meta,
        private readonly MetaAdReferenceNormalizer $normalizer,
        private readonly MetaInstagramAdFinder $instagramAdFinder,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolve(Owner $owner, array $input): array
    {
        $reference = $this->normalizer->normalize($input);

        if (is_string($reference['ad_id'])) {
            return [
                'ad' => $this->meta->ad($owner, $reference['ad_id']),
                'instagram_media' => null,
                'reference' => $reference,
            ];
        }

        $result = $this->instagramAdFinder->find($owner, $reference);

        return [
            'ad' => $result['ad'],
            'instagram_media' => $result['media'] === null
                ? null
                : Arr::only($result['media'], [
                    'id',
                    'shortcode',
                    'permalink',
                    'media_type',
                    'media_product_type',
                    'timestamp',
                    'boost_ads_list',
                ]),
            'reference' => $reference,
        ];
    }
}
