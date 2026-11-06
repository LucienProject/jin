<?php

namespace App\Service;

use App\Dto\ChatMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Conversations enregistrées, une par fichier JSON (var/share/<env>/conversations/<id>.json),
 * et dossiers dans folders.json : un dossier existe jusqu'à sa suppression, même vide.
 */
final class ConversationStore
{
    public const string ID_PATTERN = '[0-9a-f]{16}';

    public function __construct(
        #[Autowire('%kernel.share_dir%/conversations')]
        private readonly string $dir,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, model: string, folder: ?string, updatedAt: string}> les plus récentes d'abord
     */
    public function all(): array
    {
        $summaries = [];
        foreach (glob($this->dir.'/'.str_repeat('[0-9a-f]', 16).'.json') ?: [] as $file) {
            $conversation = $this->read($file);
            if (null !== $conversation) {
                unset($conversation['messages']);
                $summaries[] = $conversation;
            }
        }
        usort($summaries, static fn (array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt']);

        return $summaries;
    }

    /**
     * @return array{id: string, title: string, model: string, folder: ?string, updatedAt: string, messages: list<array{role: string, content: string}>}|null
     */
    public function find(string $id): ?array
    {
        return $this->isValidId($id) ? $this->read($this->path($id)) : null;
    }

    /**
     * Crée la conversation si $id est null, sinon la remplace (en gardant son dossier).
     *
     * @param list<ChatMessage> $messages
     *
     * @return array{id: string, title: string, model: string, folder: ?string, updatedAt: string}
     */
    public function save(?string $id, string $title, string $model, array $messages): array
    {
        $id ??= bin2hex(random_bytes(8));
        if (!$this->isValidId($id)) {
            throw new \InvalidArgumentException('Identifiant de conversation invalide.');
        }

        return $this->write([
            'id' => $id,
            'title' => $title,
            'model' => $model,
            'folder' => $this->find($id)['folder'] ?? null,
            'updatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'messages' => array_map(static fn (ChatMessage $m): array => ['role' => $m->role, 'content' => $m->content], $messages),
        ]);
    }

    /**
     * Range la conversation dans un dossier (null : hors dossier), sans changer sa date.
     *
     * @return array{id: string, title: string, model: string, folder: ?string, updatedAt: string}|null null si elle n'existe pas
     */
    public function move(string $id, ?string $folder): ?array
    {
        $conversation = $this->find($id);
        if (null === $conversation) {
            return null;
        }
        if (null !== $folder) {
            $this->createFolder($folder);
        }
        $conversation['folder'] = $folder;

        return $this->write($conversation);
    }

    /**
     * Dossiers créés, plus ceux des conversations enregistrées avant folders.json.
     *
     * @return list<string> par ordre alphabétique
     */
    public function folders(): array
    {
        $folders = array_merge($this->storedFolders(), array_column($this->all(), 'folder'));
        $folders = array_values(array_unique(array_filter($folders, 'is_string')));
        natcasesort($folders);

        return array_values($folders);
    }

    public function createFolder(string $name): void
    {
        $folders = $this->storedFolders();
        if (!\in_array($name, $folders, true)) {
            $this->writeJson($this->dir.'/folders.json', [...$folders, $name]);
        }
    }

    /**
     * Renomme un dossier (vers un dossier existant : les deux fusionnent) ; null le supprime,
     * ses conversations passent alors hors dossier.
     *
     * @return bool false si le dossier n'existe pas
     */
    public function renameFolder(string $from, ?string $to): bool
    {
        if (!\in_array($from, $this->folders(), true)) {
            return false;
        }
        if (null !== $to) {
            $this->createFolder($to);
        }
        foreach ($this->all() as $summary) {
            if ($summary['folder'] === $from) {
                $this->move($summary['id'], $to);
            }
        }
        $this->writeJson($this->dir.'/folders.json', array_values(array_diff($this->storedFolders(), [$from])));

        return true;
    }

    public function delete(string $id): bool
    {
        return $this->isValidId($id) && is_file($this->path($id)) && unlink($this->path($id));
    }

    private function write(array $conversation): array
    {
        $this->writeJson($this->path($conversation['id']), $conversation);
        unset($conversation['messages']);

        return $conversation;
    }

    /** Écriture atomique : un fichier lu en même temps n'est jamais à moitié écrit. */
    private function writeJson(string $file, array $data): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(\sprintf('Impossible de créer le dossier "%s".', $this->dir));
        }
        $tmp = $file.'.tmp';
        file_put_contents($tmp, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), \LOCK_EX);
        rename($tmp, $file);
    }

    /** @return list<string> */
    private function storedFolders(): array
    {
        $file = $this->dir.'/folders.json';
        try {
            $folders = is_file($file) ? json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR) : [];
        } catch (\JsonException) {
            return [];
        }

        return \is_array($folders) ? array_values(array_filter($folders, 'is_string')) : [];
    }

    private function isValidId(string $id): bool
    {
        return 1 === preg_match('/^'.self::ID_PATTERN.'$/D', $id);
    }

    private function path(string $id): string
    {
        return $this->dir.'/'.$id.'.json';
    }

    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || !isset($data['id'], $data['title'], $data['model'], $data['updatedAt'], $data['messages'])) {
            return null;
        }

        // les conversations enregistrées avant les dossiers n'ont pas la clé
        return ['folder' => null, ...$data];
    }
}
