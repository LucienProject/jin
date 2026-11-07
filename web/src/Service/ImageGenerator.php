<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client du service imagegen (Z-Image-Turbo, voir ../imagegen).
 */
final readonly class ImageGenerator
{
    public const string MODEL = 'Z-Image-Turbo';

    public function __construct(
        private HttpClientInterface $imagegenClient,
    ) {
    }

    /**
     * @return array{url: string, seed: int, seconds: float} image JPEG en data URL
     *
     * @throws \RuntimeException service arrêté, modèle en chargement, mémoire GPU insuffisante…
     */
    public function generate(string $prompt, int $width = 1024, int $height = 1024): array
    {
        try {
            $response = $this->imagegenClient->request('POST', '/generate', [
                'json' => ['prompt' => $prompt, 'width' => $width, 'height' => $height],
            ]);
            $body = $response->toArray(false);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('Service de génération d\'images injoignable (docker compose --profile images up -d imagegen) : '.$e->getMessage(), 0, $e);
        }

        if (200 !== $status) {
            $detail = $body['detail'] ?? \sprintf('HTTP %d', $status);
            throw new \RuntimeException(\is_string($detail) ? $detail : json_encode($detail));
        }

        return ['url' => 'data:image/jpeg;base64,'.$body['image'], 'seed' => (int) $body['seed'], 'seconds' => (float) $body['seconds']];
    }
}
