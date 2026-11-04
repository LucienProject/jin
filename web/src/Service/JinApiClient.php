<?php

namespace App\Service;

use App\Dto\ChatMessage;
use Symfony\Component\HttpClient\Chunk\ServerSentEvent;
use Symfony\Component\HttpClient\EventSourceHttpClient;
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
     * Envoie la conversation et renvoie la réponse au fil de l'eau.
     *
     * @param list<ChatMessage> $messages
     *
     * @return \Generator<array{type: 'content'|'reasoning', text: string}>
     *
     * @throws JinApiException
     */
    public function streamChat(string $model, array $messages): \Generator
    {
        $client = new EventSourceHttpClient($this->jinClient);
        $response = $client->request('POST', '/v1/chat/completions', [
            'json' => [
                'model' => $model,
                'messages' => array_map(static fn (ChatMessage $m): array => ['role' => $m->role, 'content' => $m->content], $messages),
                'stream' => true,
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            $body = json_decode($response->getContent(false), true);
            $error = $body['error']['message'] ?? $body['error'] ?? \sprintf('HTTP %d', $response->getStatusCode());

            throw new JinApiException(\is_string($error) ? $error : json_encode($error));
        }

        foreach ($client->stream($response) as $chunk) {
            if (!$chunk instanceof ServerSentEvent) {
                continue;
            }
            if ('[DONE]' === $chunk->getData()) {
                return;
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
        }
    }
}
