<?php

namespace App\Controller;

use App\Dto\ImageRequest;
use App\Service\ImageGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class ImageController extends AbstractController
{
    public function __construct(private readonly ImageGenerator $generator)
    {
    }

    /**
     * Génère une image : {"prompt", "format"?: square|landscape|portrait}
     * → {"name", "url" (data URL JPEG), "model", "seconds"}.
     */
    #[Route('/images', name: 'image_generate', methods: ['POST'], format: 'json')]
    public function generate(#[MapRequestPayload] ImageRequest $request): JsonResponse
    {
        [$width, $height] = ImageRequest::FORMATS[$request->format];
        try {
            $image = $this->generator->generate($request->prompt, $width, $height);
        } catch (\RuntimeException $e) {
            return $this->json(['title' => 'Génération impossible', 'detail' => $e->getMessage()], Response::HTTP_BAD_GATEWAY, ['Content-Type' => 'application/problem+json']);
        }

        return $this->json([
            'name' => \sprintf('image-%d.jpg', $image['seed']),
            'url' => $image['url'],
            'model' => ImageGenerator::MODEL,
            'seconds' => $image['seconds'],
        ]);
    }
}
