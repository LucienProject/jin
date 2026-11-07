<?php

namespace App\Service;

use App\Dto\ChatMessage;
use Symfony\Component\HttpClient\Chunk\ServerSentEvent;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API compatible OpenAI exposée par Ollama derrière Caddy.
 */
final readonly class JinApiClient
{
    public function __construct(
        private HttpClientInterface $jinClient,
    ) {
    }

    /**
     * @return list<string> modèles de chat disponibles, triés par nom
     */
    public function listModels(): array
    {
        $ids = array_column($this->jinClient->request('GET', '/v1/models')->toArray()['data'] ?? [], 'id');
        // les modèles d'embedding ne savent pas discuter
        $ids = array_values(array_filter($ids, static fn (string $id): bool => !str_contains($id, 'embed')));
        sort($ids);

        return $ids;
    }

    /**
     * Modèles qui savent lire les images, d'après Ollama (requêtes en parallèle).
     *
     * @param list<string> $models
     *
     * @return list<string>
     */
    public function visionModels(array $models): array
    {
        $vision = [];
        try {
            $responses = [];
            foreach ($models as $model) {
                $responses[$model] = $this->jinClient->request('POST', '/api/show', ['json' => ['model' => $model], 'timeout' => 10]);
            }
            foreach ($responses as $model => $response) {
                if (\in_array('vision', $response->toArray(false)['capabilities'] ?? [], true)) {
                    $vision[] = $model;
                }
            }
        } catch (ExceptionInterface) {
            // simple indication pour l'interface : on s'en passe
        }

        return $vision;
    }

    /**
     * Envoie la conversation et renvoie la réponse au fil de l'eau.
     *
     * Avec des outils, le modèle peut demander à en appeler : ces appels sont la valeur de retour
     * du générateur (getReturn()), vide s'il a répondu directement.
     *
     * @param list<array<string, mixed>>      $messages au format OpenAI (role, content, tool_calls, tool_call_id)
     * @param list<array<string, mixed>>|null $tools    définitions au format OpenAI
     *
     * @return \Generator<int, array{type: 'content'|'reasoning', text: string}, mixed, list<array{id: string, name: string, arguments: string}>>
     *
     * @throws JinApiException
     */
    public function streamChat(string $model, array $messages, ?array $tools = null): \Generator
    {
        $client = new EventSourceHttpClient($this->jinClient);
        $response = $client->request('POST', '/v1/chat/completions', [
            'json' => ['model' => $model, 'messages' => $messages, 'stream' => true] + ($tools ? ['tools' => $tools] : []),
        ]);

        if (200 !== $response->getStatusCode()) {
            $body = json_decode($response->getContent(false), true);
            $error = $body['error']['message'] ?? $body['error'] ?? \sprintf('HTTP %d', $response->getStatusCode());

            throw new JinApiException(\is_string($error) ? $error : json_encode($error));
        }

        $calls = [];
        foreach ($client->stream($response) as $chunk) {
            if (!$chunk instanceof ServerSentEvent) {
                continue;
            }
            if ('[DONE]' === $chunk->getData()) {
                break;
            }

            $delta = json_decode($chunk->getData(), true)['choices'][0]['delta'] ?? [];
            // les modèles qui « réfléchissent » (deepseek-r1, qwen3…) envoient leur raisonnement à part
            $reasoning = $delta['reasoning'] ?? $delta['reasoning_content'] ?? '';
            if ('' !== $reasoning) {
                yield ['type' => 'reasoning', 'text' => $reasoning];
            }
            if ('' !== ($delta['content'] ?? '')) {
                yield ['type' => 'content', 'text' => $delta['content']];
            }
            // un appel d'outil peut arriver en plusieurs morceaux : on les recolle par index
            foreach ($delta['tool_calls'] ?? [] as $position => $call) {
                $index = $call['index'] ?? $position;
                $calls[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                $calls[$index]['id'] .= $call['id'] ?? '';
                $calls[$index]['name'] .= $call['function']['name'] ?? '';
                $arguments = $call['function']['arguments'] ?? '';
                $calls[$index]['arguments'] .= \is_string($arguments) ? $arguments : json_encode($arguments);
            }
        }

        ksort($calls);

        return array_values($calls);
    }
}
