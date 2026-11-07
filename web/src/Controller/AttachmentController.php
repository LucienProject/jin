<?php

namespace App\Controller;

use App\Service\DocumentReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AttachmentController extends AbstractController
{
    public function __construct(private readonly DocumentReader $reader)
    {
    }

    /**
     * Lit un fichier envoyé (champ multipart « file ») et renvoie son texte : {"name", "content", "truncated"}.
     * Rien n'est gardé sur le serveur : le texte voyage ensuite avec le message.
     */
    #[Route('/attachments', name: 'attachment_read', methods: ['POST'], format: 'json')]
    public function read(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            // au-delà de post_max_size, PHP ignore toute la requête
            return $this->problem('Aucun fichier reçu (trop volumineux ?).');
        }
        if (!$file->isValid()) {
            return $this->problem($file->getErrorMessage());
        }

        $name = mb_substr($file->getClientOriginalName(), 0, 255);
        try {
            $document = $this->reader->read($file->getPathname(), $name);
        } catch (\RuntimeException $e) {
            return $this->problem($e->getMessage());
        }

        return $this->json(['name' => $name, 'content' => $document['text'], 'truncated' => $document['truncated']]);
    }

    private function problem(string $detail): JsonResponse
    {
        return $this->json(['title' => 'Fichier illisible', 'detail' => $detail], Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'application/problem+json']);
    }
}
