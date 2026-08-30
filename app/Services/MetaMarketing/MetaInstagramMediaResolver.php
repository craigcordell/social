<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;
use Illuminate\Validation\ValidationException;

final class MetaInstagramMediaResolver
{
    public function __construct(
        private readonly MetaInstagramMediaClient $mediaClient,
        private readonly MetaInstagramCreativeReference $creativeReference,
    ) {}

    /** @return array<string, mixed> */
    public function get(Owner $owner, string $mediaId): array
    {
        return $this->mediaClient->get($owner, $mediaId);
    }

    /**
     * @param  array<string, mixed>  $ad
     * @param  array<string, ?string>  $reference
     * @return null|array<string, string>
     */
    public function fromAd(array $ad, array $reference): ?array
    {
        return $this->creativeReference->fromAd($ad, $reference);
    }

    /**
     * @param  list<array<string, mixed>>  $ads
     * @return null|array<string, mixed>
     */
    public function find(Owner $owner, array $ads, string $shortcode): ?array
    {
        $mediaIds = [];

        foreach ($ads as $ad) {
            foreach ($this->creativeReference->mediaIds($ad) as $mediaId) {
                $mediaIds[$mediaId] = $mediaId;
            }
        }

        $matches = [];

        foreach ($this->mediaClient->getMany($owner, array_values($mediaIds)) as $media) {
            if (($media['shortcode'] ?? null) === $shortcode) {
                $matches[] = $media;
            }
        }

        if (count($matches) > 1) {
            throw ValidationException::withMessages([
                'reference' => 'Multiple Instagram media records matched the supplied reference.',
            ]);
        }

        return $matches[0] ?? null;
    }
}
