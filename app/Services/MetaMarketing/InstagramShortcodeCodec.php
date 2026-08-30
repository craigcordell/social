<?php

namespace App\Services\MetaMarketing;

use Illuminate\Validation\ValidationException;

final class InstagramShortcodeCodec
{
    protected const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    public function fromInternalMediaId(string $internalMediaId): string
    {
        $number = filter_var($internalMediaId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (! is_int($number)) {
            throw ValidationException::withMessages([
                'instagram_internal_media_id' => 'The Instagram internal media ID is outside the supported integer range.',
            ]);
        }

        $shortcode = '';

        while ($number > 0) {
            $shortcode = self::ALPHABET[$number % 64].$shortcode;
            $number = intdiv($number, 64);
        }

        return $shortcode;
    }

    public function fromPermalink(string $permalink): ?string
    {
        if (preg_match('~instagram\.com/(?:p|reel|tv)/([A-Za-z0-9_-]+)~i', $permalink, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
