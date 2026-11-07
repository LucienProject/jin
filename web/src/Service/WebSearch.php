<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Recherche sur le web via l'API JSON de SearXNG.
 */
final readonly class WebSearch
{
    public function __construct(
        private HttpClientInterface $searxngClient,
    ) {
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    public function search(string $query, int $limit = 8): array
    {
        $data = $this->searxngClient->request('GET', '/search', [
            'query' => ['q' => $query, 'format' => 'json'],
        ])->toArray();

        $results = [];
        foreach ($data['results'] ?? [] as $result) {
            if (!isset($result['url']) || !\is_string($result['url'])) {
                continue;
            }
            $results[] = [
                'title' => trim((string) ($result['title'] ?? '')),
                'url' => $result['url'],
                'snippet' => trim((string) ($result['content'] ?? '')),
            ];
            if (\count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
