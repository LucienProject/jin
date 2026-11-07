<?php

namespace App\Controller;

use App\Dto\ChatRequest;
use App\Service\ChatAgent;
use App\Service\ConversationStore;
use App\Service\JinApiClient;
use App\Service\JinApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

final class ChatController extends AbstractController
{
    public function __construct(
        private readonly JinApiClient $api,
        private readonly ConversationStore $conversations,
        private readonly ChatAgent $agent,
        #[Autowire(env: 'JIN_DEFAULT_MODEL')]
        private readonly string $defaultModel,
    ) {
    }

    #[Route('/', name: 'chat', methods: ['GET'])]
    public function index(): Response
    {
        $conversations = $this->conversations->all();
        $folders = $this->conversations->folders();
        try {
            $models = $this->api->listModels();
        } catch (ExceptionInterface $e) {
            return $this->render('chat/index.html.twig', ['models' => [], 'default_model' => null, 'conversations' => $conversations, 'folders' => $folders, 'error' => $e->getMessage()]);
        }

        return $this->render('chat/index.html.twig', [
            'models' => $models,
            'default_model' => \in_array($this->defaultModel, $models, true) ? $this->defaultModel : null,
            'conversations' => $conversations,
            'folders' => $folders,
            'error' => null,
        ]);
    }

    /**
     * Répond en NDJSON, une ligne par morceau : {"type": "content"|"reasoning"|"notice"|"error", "text": "…"},
     * et pour la recherche web {"type": "tool", "name", "label", "url"?} puis {"type": "tool_done", "ok", "summary"}.
     */
    #[Route('/chat', name: 'chat_send', methods: ['POST'], format: 'json')]
    public function send(#[MapRequestPayload] ChatRequest $chat): StreamedResponse
    {
        return new StreamedResponse(function () use ($chat): void {
            try {
                foreach ($this->agent->run($chat->model, $chat->messages, $chat->webSearch) as $part) {
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
