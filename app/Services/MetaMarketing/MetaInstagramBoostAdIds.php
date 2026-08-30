<?php

namespace App\Services\MetaMarketing;

final class MetaInstagramBoostAdIds
{
    /**
     * @param  null|array<string, mixed>  $media
     * @return list<string>
     */
    public function fromMedia(?array $media): array
    {
        $rows = data_get($media, 'boost_ads_list.data', []);
        $adIds = [];

        if (! is_array($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            $adId = is_array($row) ? $row['ad_id'] ?? null : null;

            if (is_string($adId) && ctype_digit($adId)) {
                $adIds[$adId] = $adId;
            }
        }

        return array_values($adIds);
    }
}
