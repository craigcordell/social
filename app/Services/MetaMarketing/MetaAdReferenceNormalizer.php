<?php

namespace App\Services\MetaMarketing;

use Illuminate\Support\Arr;

final class MetaAdReferenceNormalizer
{
    public function __construct(
        private readonly InstagramShortcodeCodec $shortcodeCodec,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ad_id: ?string, instagram_media_id: ?string, instagram_internal_media_id: ?string, instagram_shortcode: ?string, instagram_permalink: ?string, instagram_boosted_id: ?string}
     */
    public function normalize(array $input): array
    {
        $editParameters = [];

        if (filled(Arr::get($input, 'instagram_edit_url'))) {
            $editUrl = html_entity_decode(str_replace('\\_', '_', (string) Arr::get($input, 'instagram_edit_url')));
            parse_str((string) parse_url($editUrl, PHP_URL_QUERY), $editParameters);
        }

        $internalMediaId = $this->nullableString(
            Arr::get($input, 'instagram_internal_media_id', Arr::get($editParameters, 'media_id')),
        );
        $shortcode = $this->nullableString(Arr::get($input, 'instagram_shortcode'));
        $permalink = $this->nullableString(Arr::get($input, 'instagram_permalink'));

        if ($shortcode === null && $internalMediaId !== null) {
            $shortcode = $this->shortcodeCodec->fromInternalMediaId($internalMediaId);
        }

        if ($shortcode === null && $permalink !== null) {
            $shortcode = $this->shortcodeCodec->fromPermalink($permalink);
        }

        return [
            'ad_id' => $this->nullableString(Arr::get($input, 'ad_id')),
            'instagram_media_id' => $this->nullableString(Arr::get($input, 'instagram_media_id')),
            'instagram_internal_media_id' => $internalMediaId,
            'instagram_shortcode' => $shortcode,
            'instagram_permalink' => $permalink,
            'instagram_boosted_id' => $this->nullableString(
                Arr::get($input, 'instagram_boosted_id', Arr::get($editParameters, 'boosted_id')),
            ),
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
