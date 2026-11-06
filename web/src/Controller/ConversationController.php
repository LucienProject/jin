<?php

namespace App\Controller;

use App\Dto\CreateFolderRequest;
use App\Dto\MoveConversationRequest;
use App\Dto\RenameFolderRequest;
use App\Dto\SaveConversationRequest;
use App\Service\ConversationStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/conversations', format: 'json', requirements: ['id' => ConversationStore::ID_PATTERN])]
final class ConversationController extends AbstractController
{
    public function __construct(private readonly ConversationStore $store)
    {
    }

    #[Route('', name: 'conversation_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] SaveConversationRequest $request): JsonResponse
    {
        return $this->json($this->store->save(null, $request->title, $request->model, $request->messages), Response::HTTP_CREATED);
    }

    /**
     * Crée un dossier, vide : {"name": "Boulot"}. Renvoie la liste des dossiers.
     */
    #[Route('/folders', name: 'conversation_folder_create', methods: ['POST'])]
    public function createFolder(#[MapRequestPayload] CreateFolderRequest $request): JsonResponse
    {
        $this->store->createFolder(trim($request->name));

        return $this->json($this->store->folders(), Response::HTTP_CREATED);
    }

    /**
     * Renomme un dossier {"from": "Boulot", "to": "Travail"}, ou le supprime avec "to": null.
     * Renvoie les conversations et les dossiers à jour.
     */
    #[Route('/folders', name: 'conversation_folder_rename', methods: ['PATCH'])]
    public function renameFolder(#[MapRequestPayload] RenameFolderRequest $request): JsonResponse
    {
        if (!$this->store->renameFolder(trim($request->from), $request->to())) {
            throw new NotFoundHttpException('Dossier introuvable.');
        }

        return $this->json(['conversations' => $this->store->all(), 'folders' => $this->store->folders()]);
    }

    #[Route('/{id}', name: 'conversation_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return $this->json($this->store->find($id) ?? throw new NotFoundHttpException('Conversation introuvable.'));
    }

    #[Route('/{id}', name: 'conversation_update', methods: ['PUT'])]
    public function update(string $id, #[MapRequestPayload] SaveConversationRequest $request): JsonResponse
    {
        // une conversation supprimée depuis un autre appareil n'est pas recréée
        if (null === $this->store->find($id)) {
            throw new NotFoundHttpException('Conversation introuvable.');
        }

        return $this->json($this->store->save($id, $request->title, $request->model, $request->messages));
    }

    /**
     * Range la conversation dans un dossier : {"folder": "Boulot"}, ou {"folder": null} pour l'en sortir.
     */
    #[Route('/{id}', name: 'conversation_move', methods: ['PATCH'])]
    public function move(string $id, #[MapRequestPayload] MoveConversationRequest $request): JsonResponse
    {
        return $this->json($this->store->move($id, $request->folder()) ?? throw new NotFoundHttpException('Conversation introuvable.'));
    }

    #[Route('/{id}', name: 'conversation_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        if (!$this->store->delete($id)) {
            throw new NotFoundHttpException('Conversation introuvable.');
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
