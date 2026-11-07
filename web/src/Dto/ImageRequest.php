<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ImageRequest
{
    /** largeur × hauteur de chaque format proposé */
    public const array FORMATS = [
        'square' => [1024, 1024],
        'landscape' => [1344, 768],
        'portrait' => [768, 1344],
    ];

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 4000)]
        public string $prompt,
        #[Assert\Choice(choices: ['square', 'landscape', 'portrait'])]
        public string $format = 'square',
    ) {
    }
}
