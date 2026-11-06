<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RenameConversationRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 200)]
        public string $title,
    ) {
    }
}
