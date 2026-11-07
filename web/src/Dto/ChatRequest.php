<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChatRequest
{
    /**
     * @param list<ChatMessage> $messages
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $model,
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        public array $messages,
        /** laisse le modèle chercher sur le web (outils web_search et fetch_url) */
        public bool $webSearch = false,
    ) {
    }
}
