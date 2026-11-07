<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class ChatMessage
{
    /**
     * @param list<Attachment> $attachments fichiers joints (messages de l'utilisateur)
     */
    public function __construct(
        #[Assert\Choice(choices: ['system', 'user', 'assistant'])]
        public string $role,
        public string $content,
        #[Assert\Count(max: 10)]
        #[Assert\Valid]
        public array $attachments = [],
    ) {
    }

    /** un message peut n'être qu'un fichier joint, sans texte */
    #[Assert\Callback]
    public function validateContent(ExecutionContextInterface $context): void
    {
        if ('' === trim($this->content) && [] === $this->attachments) {
            $context->buildViolation('Le message est vide.')->atPath('content')->addViolation();
        }
    }

    /**
     * Message tel que le modèle le lit : les fichiers joints, puis le texte.
     *
     * @return array{role: string, content: string}
     */
    public function toApi(): array
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

        return ['role' => $this->role, 'content' => implode("\n\n", $parts)];
    }

    /**
     * Message tel qu'il est enregistré (et rechargé par l'interface).
     *
     * @return array{role: string, content: string, attachments?: list<array{name: string, content: string, truncated: bool}>}
     */
    public function toArray(): array
    {
        $message = ['role' => $this->role, 'content' => $this->content];
        if ([] !== $this->attachments) {
            $message['attachments'] = array_map(static fn (Attachment $a): array => ['name' => $a->name, 'content' => $a->content, 'truncated' => $a->truncated], $this->attachments);
        }

        return $message;
    }
}
