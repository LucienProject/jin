<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MoveConversationRequest
{
    public function __construct(
        /** null ou vide : hors dossier */
        #[Assert\Length(max: 50)]
        public ?string $folder = null,
    ) {
    }

    public function folder(): ?string
    {
        $folder = trim((string) $this->folder);

        return '' === $folder ? null : $folder;
    }
}
