<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RenameFolderRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        public string $from,
        /** null ou vide : supprime le dossier (ses conversations passent hors dossier) */
        #[Assert\Length(max: 50)]
        public ?string $to = null,
    ) {
    }

    public function to(): ?string
    {
        $to = trim((string) $this->to);

        return '' === $to ? null : $to;
    }
}
