<?php

namespace App\Controller;

use App\Service\SpeechTranscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SpeechController extends AbstractController
{
    public function __construct(private readonly SpeechTranscriber $transcriber)
    {
    }

    /**
     * Transcrit un enregistrement du micro (champs multipart « audio » et « language » : fr|en)
     * → {"text", "language", "seconds"}.
     * Rien n'est gardé sur le serveur.
     */
    #[Route('/transcriptions', name: 'speech_transcribe', methods: ['POST'], format: 'json')]
    public function transcribe(Request $request): JsonResponse
    {
        $file = $request->files->get('audio');
        if (!$file instanceof UploadedFile) {
            return $this->problem('Aucun enregistrement reçu (trop long ?).', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$file->isValid()) {
            return $this->problem($file->getErrorMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $language = $request->request->getString('language', 'fr');
        if (!\in_array($language, SpeechTranscriber::LANGUAGES, true)) {
            return $this->problem('Langue non prise en charge (fr ou en).', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $audio = fopen($file->getPathname(), 'r');
        try {
            $result = $this->transcriber->transcribe($audio, $file->getClientMimeType() ?: 'application/octet-stream', $language);
        } catch (\RuntimeException $e) {
            return $this->problem($e->getMessage(), Response::HTTP_BAD_GATEWAY);
        } finally {
            fclose($audio);
        }

        return $this->json($result);
    }

    private function problem(string $detail, int $status): JsonResponse
    {
        return $this->json(['title' => 'Transcription impossible', 'detail' => $detail], $status, ['Content-Type' => 'application/problem+json']);
    }
}
