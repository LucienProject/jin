<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class ConversationControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.share_dir').'/conversations');
        $users = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $this->client->loginUser($users->loadUserByIdentifier('lucien'));
    }

    public function testAttachmentsAreSavedWithTheConversation(): void
    {
        $attachment = ['name' => 'notes.md', 'content' => '# Notes', 'truncated' => false];
        $image = ['name' => 'photo.jpg', 'url' => 'data:image/jpeg;base64,AAAA'];
        $created = $this->json('POST', '/conversations', ['title' => 'notes.md', 'model' => 'gemma4:12b', 'messages' => [
            ['role' => 'user', 'content' => '', 'attachments' => [$attachment], 'images' => [$image]],
            ['role' => 'assistant', 'content' => 'Lu.'],
        ]]);

        self::assertSame([
            ['role' => 'user', 'content' => '', 'attachments' => [$attachment], 'images' => [$image]],
            ['role' => 'assistant', 'content' => 'Lu.'],
        ], $this->json('GET', '/conversations/'.$created['id'])['messages']);
    }

    public function testSaveLoadUpdateAndDelete(): void
    {
        $created = $this->json('POST', '/conversations', $this->conversation('Salut', ['Salut', 'Bonjour !']));
        self::assertResponseStatusCodeSame(201);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $created['id']);
        self::assertSame('Salut', $created['title']);
        self::assertNull($created['folder']);
        self::assertArrayNotHasKey('messages', $created);

        $url = '/conversations/'.$created['id'];
        $loaded = $this->json('GET', $url);
        self::assertSame('gemma4:12b', $loaded['model']);
        self::assertSame([
            ['role' => 'user', 'content' => 'Salut'],
            ['role' => 'assistant', 'content' => 'Bonjour !'],
        ], $loaded['messages']);

        $this->json('PUT', $url, $this->conversation('Salut', ['Salut', 'Bonjour !', 'Ça va ?', 'Oui.']));
        self::assertResponseIsSuccessful();
        self::assertCount(4, $this->json('GET', $url)['messages']);

        $this->client->request('DELETE', $url);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testMoveToFolderKeepsDateAndSurvivesUpdate(): void
    {
        $created = $this->json('POST', '/conversations', $this->conversation('Salut', ['Salut', 'Bonjour !']));
        $url = '/conversations/'.$created['id'];

        $moved = $this->json('PATCH', $url, ['folder' => '  Boulot ']);
        self::assertResponseIsSuccessful();
        self::assertSame('Boulot', $moved['folder']);
        self::assertSame($created['updatedAt'], $moved['updatedAt']);

        // la mise à jour après un nouvel échange ne sort pas la conversation de son dossier
        $updated = $this->json('PUT', $url, $this->conversation('Salut', ['Salut', 'Bonjour !', 'Ça va ?', 'Oui.']));
        self::assertSame('Boulot', $updated['folder']);

        self::assertNull($this->json('PATCH', $url, ['folder' => null])['folder']);
        self::assertNull($this->json('PATCH', $url, ['folder' => ''])['folder']);

        $this->json('PATCH', $url, ['folder' => str_repeat('x', 51)]);
        self::assertResponseStatusCodeSame(422);

        $this->json('PATCH', '/conversations/0123456789abcdef', ['folder' => 'Boulot']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRenameMergeAndDeleteFolder(): void
    {
        $ids = [];
        foreach (['Boulot', 'Boulot', 'Code', null] as $i => $folder) {
            $ids[$i] = $this->json('POST', '/conversations', $this->conversation("C$i", ["C$i"]))['id'];
            $this->json('PATCH', '/conversations/'.$ids[$i], ['folder' => $folder]);
        }
        $folders = fn (array $list): array => array_column($list, 'folder', 'title');

        $result = $this->json('PATCH', '/conversations/folders', ['from' => 'Boulot', 'to' => 'Travail']);
        self::assertResponseIsSuccessful();
        self::assertSame(['C0' => 'Travail', 'C1' => 'Travail', 'C2' => 'Code', 'C3' => null], $this->sorted($folders($result['conversations'])));
        self::assertSame(['Code', 'Travail'], $result['folders']);

        // renommer vers un dossier existant fusionne les deux
        $result = $this->json('PATCH', '/conversations/folders', ['from' => 'Code', 'to' => 'Travail']);
        self::assertSame(['C0' => 'Travail', 'C1' => 'Travail', 'C2' => 'Travail', 'C3' => null], $this->sorted($folders($result['conversations'])));
        self::assertSame(['Travail'], $result['folders']);

        // supprimer le dossier garde les conversations, hors dossier
        $result = $this->json('PATCH', '/conversations/folders', ['from' => 'Travail', 'to' => null]);
        self::assertSame(['C0' => null, 'C1' => null, 'C2' => null, 'C3' => null], $this->sorted($folders($result['conversations'])));
        self::assertSame([], $result['folders']);
        self::assertCount(1, $this->json('GET', '/conversations/'.$ids[0])['messages']);

        $this->json('PATCH', '/conversations/folders', ['from' => 'Inconnu', 'to' => 'X']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testEmptyFolderPersistsUntilDeleted(): void
    {
        self::assertSame(['Recettes'], $this->json('POST', '/conversations/folders', ['name' => ' Recettes ']));
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['Code', 'Recettes'], $this->json('POST', '/conversations/folders', ['name' => 'Code']));
        // déjà existant : rien ne change
        self::assertSame(['Code', 'Recettes'], $this->json('POST', '/conversations/folders', ['name' => 'Code']));

        // un dossier vidé par un déplacement reste là
        $id = $this->json('POST', '/conversations', $this->conversation('Salut', ['Salut']))['id'];
        $this->json('PATCH', "/conversations/$id", ['folder' => 'Code']);
        $this->json('PATCH', "/conversations/$id", ['folder' => null]);

        $result = $this->json('PATCH', '/conversations/folders', ['from' => 'Recettes', 'to' => 'Cuisine']);
        self::assertSame(['Code', 'Cuisine'], $result['folders']);

        $result = $this->json('PATCH', '/conversations/folders', ['from' => 'Cuisine', 'to' => null]);
        self::assertSame(['Code'], $result['folders']);

        static::getContainer()->set('jin.client', new MockHttpClient(new JsonMockResponse(['data' => [['id' => 'gemma4:12b']]]), 'http://localhost:8080'));
        $crawler = $this->client->request('GET', '/');
        self::assertSame(['Code'], json_decode($crawler->filter('#sidebar')->attr('data-folders'), true));

        $this->json('POST', '/conversations/folders', ['name' => '  ']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRenameKeepsDateAndSurvivesUpdate(): void
    {
        $created = $this->json('POST', '/conversations', $this->conversation('Salut', ['Salut', 'Bonjour !']));
        $url = '/conversations/'.$created['id'];

        $renamed = $this->json('PATCH', "$url/title", ['title' => '  Mon titre ']);
        self::assertResponseIsSuccessful();
        self::assertSame('Mon titre', $renamed['title']);
        self::assertSame($created['updatedAt'], $renamed['updatedAt']);

        // la mise à jour après un nouvel échange envoie le titre calculé : il est ignoré
        $updated = $this->json('PUT', $url, $this->conversation('Salut', ['Salut', 'Bonjour !', 'Ça va ?', 'Oui.']));
        self::assertSame('Mon titre', $updated['title']);
        self::assertSame('Mon titre', $this->json('GET', $url)['title']);

        $this->json('PATCH', "$url/title", ['title' => ' ']);
        self::assertResponseStatusCodeSame(422);

        $this->json('PATCH', '/conversations/0123456789abcdef/title', ['title' => 'X']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateDoesNotRecreateDeletedConversation(): void
    {
        $this->json('PUT', '/conversations/0123456789abcdef', $this->conversation('Salut', ['Salut']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsInvalidIdAndPayload(): void
    {
        $this->client->request('GET', '/conversations/..%2F..%2Fetc');
        self::assertResponseStatusCodeSame(404);

        $this->json('POST', '/conversations', ['title' => '', 'model' => 'gemma4:12b', 'messages' => []]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testChatPageListsSavedConversationsNewestFirst(): void
    {
        $this->json('POST', '/conversations', $this->conversation('Première', ['Première']));
        usleep(1_100_000); // updatedAt est à la seconde
        $this->json('POST', '/conversations', $this->conversation('Seconde', ['Seconde']));

        static::getContainer()->set('jin.client', new MockHttpClient(new JsonMockResponse(['data' => [['id' => 'gemma4:12b']]]), 'http://localhost:8080'));
        $crawler = $this->client->request('GET', '/');

        $list = json_decode($crawler->filter('#sidebar')->attr('data-conversations'), true);
        self::assertSame(['Seconde', 'Première'], array_column($list, 'title'));
    }

    private function sorted(array $array): array
    {
        ksort($array);

        return $array;
    }

    /**
     * @param list<string> $contents alternativement utilisateur / assistant
     */
    private function conversation(string $title, array $contents): array
    {
        $messages = [];
        foreach ($contents as $i => $content) {
            $messages[] = ['role' => 0 === $i % 2 ? 'user' : 'assistant', 'content' => $content];
        }

        return ['title' => $title, 'model' => 'gemma4:12b', 'messages' => $messages];
    }

    private function json(string $method, string $url, ?array $payload = null): mixed
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: null === $payload ? null : json_encode($payload));

        return json_decode($this->client->getInternalResponse()->getContent(), true);
    }
}
