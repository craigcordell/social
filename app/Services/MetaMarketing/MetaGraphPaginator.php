<?php

namespace App\Services\MetaMarketing;

final class MetaGraphPaginator
{
    public function __construct(
        private readonly MetaGraphApiClient $graph,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function getAll(string $path, array $query): array
    {
        $rows = [];
        $after = null;

        for ($page = 0; $page < 20; $page++) {
            $response = $this->graph->get($path, [...$query, 'after' => $after]);
            $rows = [...$rows, ...$this->rows($response)];
            $paging = is_array($response['paging'] ?? null) ? $response['paging'] : [];
            $cursors = is_array($paging['cursors'] ?? null) ? $paging['cursors'] : [];
            $nextAfter = $cursors['after'] ?? null;

            if (
                ($paging['has_next_page'] ?? false) !== true
                || ! is_string($nextAfter)
                || $nextAfter === ''
                || $nextAfter === $after
            ) {
                return $rows;
            }

            $after = $nextAfter;
        }

        abort(503, 'Meta returned too many pages to process safely.');
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    protected function rows(array $response): array
    {
        $data = $response['data'] ?? [];
        $rows = [];

        if (! is_array($data)) {
            return [];
        }

        foreach ($data as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
