<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client du service speech (Whisper, voir ../speech).
 */
final readonly class SpeechTranscriber
{
    /** langues proposées pour la dictée */
    public const array LANGUAGES = ['fr', 'en'];

    public function __construct(
        private HttpClientInterface $speechClient,
    ) {
    }

    /**
     * @param resource|string $audio    enregistrement brut (webm/opus, mp4/aac, ogg, wav…)
     * @param string          $language une des self::LANGUAGES
     *
     * @return array{text: string, language: string, seconds: float}
     *
     * @throws \RuntimeException service arrêté, modèle en chargement, audio illisible…
     */
    public function transcribe(mixed $audio, string $contentType, string $language): array
    {
        try {
            $response = $this->speechClient->request('POST', '/transcribe', [
                'query' => ['language' => $language],
                'headers' => ['Content-Type' => $contentType],
                'body' => $audio,
            ]);
            $body = $response->toArray(false);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('Service de transcription injoignable (docker compose up -d speech) : '.$e->getMessage(), 0, $e);
        }

        if (200 !== $status) {
            $detail = $body['detail'] ?? \sprintf('HTTP %d', $status);
            throw new \RuntimeException(\is_string($detail) ? $detail : json_encode($detail));
        }

        return ['text' => (string) $body['text'], 'language' => (string) $body['language'], 'seconds' => (float) $body['seconds']];
    }
}
