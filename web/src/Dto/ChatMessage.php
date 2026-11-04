<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChatMessage
{
    public function __construct(
        #[Assert\Choice(choices: ['system', 'user', 'assistant'])]
        public string $role,
        #[Assert\NotBlank]
        public string $content,
    ) {
    }
}
