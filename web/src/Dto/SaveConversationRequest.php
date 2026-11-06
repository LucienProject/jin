<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveConversationRequest
{
    /**
     * @param list<ChatMessage> $messages
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $title,
        #[Assert\NotBlank]
        public string $model,
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        public array $messages,
    ) {
    }
}
