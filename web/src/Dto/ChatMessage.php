<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class ChatMessage
{
    /**
     * @param list<Attachment>      $attachments fichiers joints (messages de l'utilisateur), réduits à leur texte
     * @param list<ImageAttachment> $images      images jointes, pour les modèles qui savent les lire
     */
    public function __construct(
        #[Assert\Choice(choices: ['system', 'user', 'assistant'])]
        public string $role,
        public string $content,
        #[Assert\Count(max: 10)]
        #[Assert\Valid]
        public array $attachments = [],
        #[Assert\Count(max: 10)]
        #[Assert\Valid]
        public array $images = [],
    ) {
    }

    /** un message peut n'être qu'un fichier ou une image, sans texte */
    #[Assert\Callback]
    public function validateContent(ExecutionContextInterface $context): void
    {
        if ('' === trim($this->content) && [] === $this->attachments && [] === $this->images) {
            $context->buildViolation('Le message est vide.')->atPath('content')->addViolation();
        }
    }

    /**
     * Message tel que le modèle le lit : les fichiers joints, puis le texte, puis les images
     * (contenu multimodal au format OpenAI).
     *
     * @param bool $withImages false pour un modèle qui ne sait pas lire les images : elles sont seulement mentionnées
     *
     * @return array{role: string, content: string|list<array<string, mixed>>}
     */
    public function toApi(bool $withImages = true): array
    {
        $parts = array_map(static fn (Attachment $a): string => \sprintf(
            "<document name=\"%s\">\n%s\n</document>%s",
            htmlspecialchars($a->name, \ENT_QUOTES | \ENT_XML1),
            $a->content,
            $a->truncated ? "\n(document trop long : seul le début est ci-dessus)" : '',
        ), $this->attachments);
        if ('' !== trim($this->content)) {
            $parts[] = $this->content;
        }
        if (!$withImages) {
            foreach ($this->images as $image) {
                $parts[] = \sprintf('[image « %s » jointe, que tu ne peux pas voir]', $image->name);
            }
        }
        $text = implode("\n\n", $parts);

        if (!$withImages || [] === $this->images) {
            return ['role' => $this->role, 'content' => $text];
        }

        return ['role' => $this->role, 'content' => [
            ...('' !== $text ? [['type' => 'text', 'text' => $text]] : []),
            ...array_map(static fn (ImageAttachment $i): array => ['type' => 'image_url', 'image_url' => ['url' => $i->url]], $this->images),
        ]];
    }

    /**
     * Message tel qu'il est enregistré (et rechargé par l'interface).
     *
     * @return array{role: string, content: string, attachments?: list<array{name: string, content: string, truncated: bool}>, images?: list<array{name: string, url: string}>}
     */
    public function toArray(): array
    {
        $message = ['role' => $this->role, 'content' => $this->content];
        if ([] !== $this->attachments) {
            $message['attachments'] = array_map(static fn (Attachment $a): array => ['name' => $a->name, 'content' => $a->content, 'truncated' => $a->truncated], $this->attachments);
        }
        if ([] !== $this->images) {
            $message['images'] = array_map(static fn (ImageAttachment $i): array => ['name' => $i->name, 'url' => $i->url], $this->images);
        }

        return $message;
    }
}
