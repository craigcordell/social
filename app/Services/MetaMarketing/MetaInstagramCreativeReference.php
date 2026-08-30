<?php

namespace App\Services\MetaMarketing;

final class MetaInstagramCreativeReference
{
    /**
     * @param  array<string, mixed>  $ad
     * @return list<string>
     */
    public function mediaIds(array $ad): array
    {
        $creative = is_array($ad['creative'] ?? null) ? $ad['creative'] : [];
        $mediaId = $creative['source_instagram_media_id'] ?? null;

        return is_string($mediaId) && ctype_digit($mediaId) ? [$mediaId] : [];
    }

    /**
     * @param  array<string, mixed>  $ad
     * @param  array<string, ?string>  $reference
     * @return null|array<string, string>
     */
    public function fromAd(array $ad, array $reference): ?array
    {
        $creative = is_array($ad['creative'] ?? null) ? $ad['creative'] : [];
        $media = array_filter(
            [
                'id' => $this->nullableString(
                    $creative['source_instagram_media_id'] ?? $creative['effective_instagram_media_id'] ?? null,
                ),
                'shortcode' => $reference['instagram_shortcode'],
                'permalink' => $this->nullableString(
                    $creative['instagram_permalink_url'] ?? $reference['instagram_permalink'],
                ),
            ],
            static fn (?string $value): bool => $value !== null,
        );

        return $media === [] ? null : $media;
    }

    protected function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
