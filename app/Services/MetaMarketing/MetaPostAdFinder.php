<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;

class MetaPostAdFinder
{
    public function __construct(
        private readonly MetaMarketingApiClient $meta,
        private readonly MetaFacebookPostAdMatcher $facebookMatcher,
        private readonly MetaInstagramPostAdMatcher $instagramMatcher,
        private readonly MetaInstagramPostMediaIndex $instagramMedia,
    ) {}

    /**
     * @param  list<array{client_reference?: ?string, platform: string, post_url: string}>  $posts
     * @return array{posts: list<array{client_reference: ?string, platform: string, post_url: string, matched_ad_ids: list<string>}>, ad_ids: list<string>}
     */
    public function find(Owner $owner, array $posts): array
    {
        $ads = $this->meta->allAds($owner);
        $mediaById = collect($posts)->contains('platform', 'instagram')
            ? $this->instagramMedia->forAds($owner, $ads)
            : [];
        $postMatches = [];
        $matchedAdIds = [];

        foreach ($posts as $post) {
            $adIds = $post['platform'] === 'facebook'
                ? $this->facebookMatcher->ids($ads, $post['post_url'])
                : $this->instagramMatcher->ids($ads, $mediaById, $post['post_url']);

            foreach ($adIds as $adId) {
                $matchedAdIds[$adId] = $adId;
            }

            $postMatches[] = [
                'client_reference' => $post['client_reference'] ?? null,
                'platform' => $post['platform'],
                'post_url' => $post['post_url'],
                'matched_ad_ids' => $adIds,
            ];
        }

        return [
            'posts' => $postMatches,
            'ad_ids' => array_values($matchedAdIds),
        ];
    }
}
