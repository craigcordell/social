<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;

final class MetaInstagramMediaClient
{
    public function __construct(
        private readonly MetaGraphApiClient $graph,
        private readonly MetaMarketingConfiguration $configuration,
    ) {}

    /** @return array<string, mixed> */
    public function get(Owner $owner, string $mediaId): array
    {
        $this->configuration->assertConfiguredFor($owner);

        return $this->graph->get($mediaId, [
            'fields' => 'id,shortcode,permalink,media_type,media_product_type,timestamp,boost_ads_list',
        ]);
    }

    /**
     * @param  list<string>  $mediaIds
     * @return list<array<string, mixed>>
     */
    public function getMany(Owner $owner, array $mediaIds): array
    {
        $this->configuration->assertConfiguredFor($owner);
        $media = [];

        foreach (array_chunk(array_values(array_unique($mediaIds)), 50) as $chunk) {
            $response = $this->graph->get('', [
                'ids' => implode(',', $chunk),
                'fields' => 'id,shortcode,permalink,media_type,media_product_type,timestamp,boost_ads_list',
            ]);

            foreach ($response as $item) {
                if (is_array($item) && isset($item['id'])) {
                    /** @var array<string, mixed> $item */
                    $media[] = $item;
                }
            }
        }

        return $media;
    }
}
