<?php

namespace App\Dto;

use App\Service\DocumentReader;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fichier joint à un message, réduit à son texte.
 */
final readonly class Attachment
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\Length(max: DocumentReader::MAX_CHARS)]
        public string $content,
        /** le texte a été coupé : le document était trop long */
        public bool $truncated = false,
    ) {
    }
}
