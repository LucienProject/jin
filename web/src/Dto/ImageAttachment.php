<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Image jointe à un message, déjà réduite par le navigateur, en data URL.
 */
final readonly class ImageAttachment
{
    /** environ 7 Mo d'image : bien au-delà d'une photo réduite à 1 536 px */
    public const int MAX_URL_LENGTH = 10_000_000;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\Length(max: self::MAX_URL_LENGTH)]
        #[Assert\Regex('#^data:image/(?:png|jpeg);base64,[A-Za-z0-9+/]+={0,2}$#D', message: 'Image invalide (PNG ou JPEG en base64 attendu).')]
        public string $url,
    ) {
    }
}
