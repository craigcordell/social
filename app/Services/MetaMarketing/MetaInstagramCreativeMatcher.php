<?php

namespace App\Services\MetaMarketing;

final class MetaInstagramCreativeMatcher
{
    /**
     * @param  array<string, mixed>  $ad
     * @param  array<string, mixed>  $media
     * @param  array<string, ?string>  $reference
     */
    public function matches(array $ad, array $media, array $reference): bool
    {
        $creative = is_array($ad['creative'] ?? null) ? $ad['creative'] : [];
        $mediaIds = array_filter(
            [
                $media['id'] ?? null,
                $reference['instagram_media_id'],
            ],
            static fn (mixed $value): bool => is_scalar($value),
        );

        foreach ($mediaIds as $mediaId) {
            if (
                (string) $mediaId === (string) ($creative['source_instagram_media_id'] ?? '')
                || (string) $mediaId === (string) ($creative['effective_instagram_media_id'] ?? '')
            ) {
                return true;
            }
        }

        $permalink = (string) ($creative['instagram_permalink_url'] ?? '');
        $shortcode = $reference['instagram_shortcode'] ?? $media['shortcode'] ?? null;

        return is_string($shortcode) && $shortcode !== '' && str_contains($permalink, '/'.$shortcode.'/');
    }
}
