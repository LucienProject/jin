<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Outils web proposés aux modèles (appel de fonctions de l'API OpenAI) : lecture seule.
 */
final readonly class WebTools
{
    public function __construct(
        private WebSearch $search,
        private WebPageReader $reader,
    ) {
    }

    /**
     * @return list<array<string, mixed>> définitions au format OpenAI « tools »
     */
    public function definitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search',
                    'description' => 'Recherche sur le web. Renvoie titres, adresses et extraits des meilleurs résultats.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Requête, comme dans un moteur de recherche.'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'fetch_url',
                    'description' => "Lit le texte d'une page web (par exemple un résultat de web_search) pour en connaître le détail.",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'Adresse http(s) complète de la page.'],
                        ],
                        'required' => ['url'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Description de l'appel pour l'interface, avant son exécution.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{name: string, label: string, url?: string}
     */
    public function describe(string $name, array $arguments): array
    {
        return match ($name) {
            'web_search' => ['name' => $name, 'label' => \sprintf('Recherche « %s »', $arguments['query'] ?? '')],
            'fetch_url' => ['name' => $name, 'label' => 'Lecture de '.(parse_url((string) ($arguments['url'] ?? ''), \PHP_URL_HOST) ?: 'la page'), 'url' => (string) ($arguments['url'] ?? '')],
            default => ['name' => $name, 'label' => \sprintf('Outil inconnu « %s »', $name)],
        };
    }

    /**
     * Exécute l'outil. Les erreurs sont renvoyées au modèle plutôt que levées, pour qu'il puisse s'adapter.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{ok: bool, summary: string, result: string} summary pour l'interface, result pour le modèle
     */
    public function run(string $name, array $arguments): array
    {
        try {
            return match ($name) {
                'web_search' => $this->search((string) ($arguments['query'] ?? '')),
                'fetch_url' => $this->fetch((string) ($arguments['url'] ?? '')),
                default => throw new \RuntimeException(\sprintf('Outil inconnu « %s ». Outils disponibles : web_search, fetch_url.', $name)),
            };
        } catch (\RuntimeException|ExceptionInterface $e) {
            return ['ok' => false, 'summary' => $e->getMessage(), 'result' => 'Erreur : '.$e->getMessage()];
        }
    }

    private function search(string $query): array
    {
        if ('' === trim($query)) {
            throw new \RuntimeException('Requête vide.');
        }
        $results = $this->search->search($query);
        if ([] === $results) {
            return ['ok' => true, 'summary' => 'aucun résultat', 'result' => 'Aucun résultat.'];
        }

        $lines = [];
        foreach ($results as $i => $r) {
            $lines[] = \sprintf("%d. %s\n   %s\n   %s", $i + 1, $r['title'], $r['url'], $r['snippet']);
        }

        return [
            'ok' => true,
            'summary' => \sprintf('%d résultat%s', \count($results), \count($results) > 1 ? 's' : ''),
            'result' => implode("\n", $lines),
        ];
    }

    private function fetch(string $url): array
    {
        $page = $this->reader->read($url);

        return [
            'ok' => true,
            'summary' => '' !== $page['title'] ? $page['title'] : 'lue',
            'result' => \sprintf("Titre : %s\nAdresse : %s\n\n%s%s", $page['title'], $url, $page['text'], $page['truncated'] ? "\n\n[… texte tronqué]" : ''),
        ];
    }
}
