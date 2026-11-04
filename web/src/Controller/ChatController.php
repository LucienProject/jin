<?php

namespace App\Controller;

use App\Dto\ChatRequest;
use App\Service\JinApiClient;
use App\Service\JinApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

final class ChatController extends AbstractController
{
    public function __construct(
        private readonly JinApiClient $api,
    ) {
    }

    #[Route('/', name: 'chat', methods: ['GET'])]
    public function index(): Response
    {
        try {
            $models = $this->api->listModels();
        } catch (ExceptionInterface $e) {
            return $this->render('chat/index.html.twig', ['models' => [], 'error' => $e->getMessage()]);
        }

        return $this->render('chat/index.html.twig', ['models' => $models, 'error' => null]);
    }

    /**
     * Répond en NDJSON : une ligne {"type": "content"|"reasoning"|"error", "text": "…"} par morceau reçu.
     */
    #[Route('/chat', name: 'chat_send', methods: ['POST'], format: 'json')]
    public function send(#[MapRequestPayload] ChatRequest $chat): StreamedResponse
    {
        return new StreamedResponse(function () use ($chat): void {
            try {
                foreach ($this->api->streamChat($chat->model, $chat->messages) as $part) {
                    echo json_encode($part), "\n";
                    flush();
                }
            } catch (ExceptionInterface|JinApiException $e) {
                echo json_encode(['type' => 'error', 'text' => $e->getMessage()]), "\n";
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
