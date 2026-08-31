<?php

namespace App\Services\MetaMarketing;

class MetaFacebookPostAdMatcher
{
    /**
     * @param  list<array<string, mixed>>  $ads
     * @return list<string>
     */
    public function ids(array $ads, string $postUrl): array
    {
        $objectStoryId = $this->objectStoryId($postUrl);
        $adIds = [];

        foreach ($ads as $ad) {
            $adId = $ad['id'] ?? null;
            $creative = is_array($ad['creative'] ?? null) ? $ad['creative'] : [];

            if (
                is_string($adId)
                && ctype_digit($adId)
                && in_array(
                    $objectStoryId,
                    [
                        $creative['object_story_id'] ?? null,
                        $creative['effective_object_story_id'] ?? null,
                    ],
                    true,
                )
            ) {
                $adIds[$adId] = $adId;
            }
        }

        return array_values($adIds);
    }

    protected function objectStoryId(string $postUrl): string
    {
        $path = trim((string) parse_url($postUrl, PHP_URL_PATH), '/');
        $matches = [];

        preg_match('~(?:^|/)([0-9]+_[0-9]+)$~', $path, $matches);

        return $matches[1] ?? '';
    }
}
