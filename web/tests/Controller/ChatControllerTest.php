<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ChatControllerTest extends WebTestCase
{
    private const string IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

    public function testIndexListsChatModelsOnly(): void
    {
        $client = $this->createClientWithApi(new JsonMockResponse(['data' => [
            ['id' => 'phi4:latest'],
            ['id' => 'nomic-embed-text:latest'],
            ['id' => 'gemma4:12b'],
        ]]));

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '#model option');
        self::assertSelectorTextSame('#model option:first-child', 'gemma4:12b');
        self::assertSelectorNotExists('#model option[selected]');
    }

    public function testIndexPreselectsDefaultModel(): void
    {
        $client = $this->createClientWithApi(new JsonMockResponse(['data' => [
            ['id' => 'phi4:latest'],
            ['id' => 'qwen3.6:35b-a3b'],
        ]]));

        $client->request('GET', '/');

        self::assertSelectorTextSame('#model option[selected]', 'qwen3.6:35b-a3b');
    }

    public function testIndexShowsErrorWhenApiIsDown(): void
    {
        $client = $this->createClientWithApi(new MockResponse('', ['error' => 'Connection refused']));

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notice.error', 'Connection refused');
        self::assertSelectorExists('#model[disabled]');
    }

    public function testChatStreamsAnswerAndReasoning(): void
    {
        $sent = null;
        $client = $this->createClientWithApi(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = json_decode($options['body'], true);

            return new MockResponse(
                $this->sse(['reasoning' => 'Hmm.']).$this->sse(['content' => 'Bon']).$this->sse(['content' => 'jour !'])."data: [DONE]\n\n",
                ['response_headers' => ['content-type' => 'text/event-stream']],
            );
        });

        $this->postChat($client, ['model' => 'gemma4:12b', 'messages' => [['role' => 'user', 'content' => 'Salut']]]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/x-ndjson');
        self::assertSame([
            ['type' => 'reasoning', 'text' => 'Hmm.'],
            ['type' => 'content', 'text' => 'Bon'],
            ['type' => 'content', 'text' => 'jour !'],
        ], $this->ndjson($client));
        self::assertSame('gemma4:12b', $sent['model']);
        self::assertTrue($sent['stream']);
        self::assertSame([['role' => 'user', 'content' => 'Salut']], $sent['messages']);
    }

    public function testChatReportsApiError(): void
    {
        $client = $this->createClientWithApi(new JsonMockResponse(
            ['error' => ['message' => 'model "inconnu" not found']],
            ['http_code' => 404],
        ));

        $this->postChat($client, ['model' => 'inconnu', 'messages' => [['role' => 'user', 'content' => 'Salut']]]);

        self::assertResponseIsSuccessful();
        self::assertSame([['type' => 'error', 'text' => 'model "inconnu" not found']], $this->ndjson($client));
    }

    public function testChatRejectsInvalidPayload(): void
    {
        $client = $this->createClientWithApi();

        $this->postChat($client, ['model' => 'gemma4:12b', 'messages' => [['role' => 'pirate', 'content' => '']]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testChatSearchesTheWebThenAnswers(): void
    {
        $sent = [];
        $client = $this->createClientWithApi(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = json_decode($options['body'], true);

            // 1er tour : le modèle demande une recherche ; 2e tour : il répond
            return new MockResponse(1 === \count($sent)
                ? $this->sse(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'web_search', 'arguments' => '{"query":"météo Paris"}']]]])."data: [DONE]\n\n"
                : $this->sse(['content' => 'Il fait beau.'])."data: [DONE]\n\n",
                ['response_headers' => ['content-type' => 'text/event-stream']],
            );
        });
        $searched = null;
        static::getContainer()->set('searxng.client', new MockHttpClient(function (string $method, string $url) use (&$searched): JsonMockResponse {
            $searched = $url;

            return new JsonMockResponse(['results' => [
                ['title' => 'Météo Paris', 'url' => 'https://meteo.example/paris', 'content' => 'Soleil, 20 °C'],
            ]]);
        }, 'http://127.0.0.1:8888'));

        $this->postChat($client, ['model' => 'gemma4:12b', 'messages' => [['role' => 'user', 'content' => 'Quel temps à Paris ?']], 'webSearch' => true]);

        self::assertSame([
            ['type' => 'tool', 'name' => 'web_search', 'label' => 'Recherche « météo Paris »'],
            ['type' => 'tool_done', 'ok' => true, 'summary' => '1 résultat'],
            ['type' => 'content', 'text' => 'Il fait beau.'],
        ], $this->ndjson($client));
        self::assertStringContainsString('format=json', $searched);
        self::assertStringContainsString('q=m%C3%A9t%C3%A9o%20Paris', $searched);

        self::assertCount(2, $sent);
        self::assertSame(['web_search', 'fetch_url'], array_column(array_column($sent[0]['tools'], 'function'), 'name'));
        self::assertSame('system', $sent[0]['messages'][0]['role']);
        // 2e tour : l'appel d'outil et son résultat sont renvoyés au modèle
        [, , $call, $result] = $sent[1]['messages'];
        self::assertSame('web_search', $call['tool_calls'][0]['function']['name']);
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'web_search'], array_diff_key($result, ['content' => 1]));
        self::assertStringContainsString('https://meteo.example/paris', $result['content']);
    }

    public function testChatWithoutWebSearchSendsNoTools(): void
    {
        $sent = null;
        $client = $this->createClientWithApi(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = json_decode($options['body'], true);

            return new MockResponse($this->sse(['content' => 'Salut'])."data: [DONE]\n\n", ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $this->postChat($client, ['model' => 'gemma4:12b', 'messages' => [['role' => 'user', 'content' => 'Salut']]]);

        self::assertArrayNotHasKey('tools', $sent);
        self::assertSame([['role' => 'user', 'content' => 'Salut']], $sent['messages']);
    }

    public function testAttachedFilesAreGivenToTheModel(): void
    {
        $sent = null;
        $client = $this->createClientWithApi(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = json_decode($options['body'], true);

            return new MockResponse($this->sse(['content' => 'Résumé'])."data: [DONE]\n\n", ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $this->postChat($client, ['model' => 'gemma4:12b', 'messages' => [
            ['role' => 'user', 'content' => 'Résume', 'attachments' => [['name' => 'a "b".txt', 'content' => 'Texte A'], ['name' => 'long.pdf', 'content' => 'Début', 'truncated' => true]]],
            ['role' => 'assistant', 'content' => 'Fait.'],
            ['role' => 'user', 'content' => '', 'attachments' => [['name' => 'c.md', 'content' => 'Texte C']]],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['role' => 'user', 'content' => "<document name=\"a &quot;b&quot;.txt\">\nTexte A\n</document>\n\n<document name=\"long.pdf\">\nDébut\n</document>\n(document trop long : seul le début est ci-dessus)\n\nRésume"],
            ['role' => 'assistant', 'content' => 'Fait.'],
            ['role' => 'user', 'content' => "<document name=\"c.md\">\nTexte C\n</document>"],
        ], $sent['messages']);
    }

    public function testIndexMarksModelsThatSeeImages(): void
    {
        $client = $this->createClientWithApi(static fn (string $method, string $url, array $options): JsonMockResponse => str_ends_with($url, '/v1/models')
            ? new JsonMockResponse(['data' => [['id' => 'gemma4:12b'], ['id' => 'phi4:latest']]])
            : new JsonMockResponse(['capabilities' => 'gemma4:12b' === json_decode($options['body'], true)['model'] ? ['completion', 'vision'] : ['completion']]));

        $client->request('GET', '/');

        self::assertSelectorExists('#model option[value="gemma4:12b"][data-vision]');
        self::assertSelectorNotExists('#model option[value="phi4:latest"][data-vision]');
    }

    public function testImagesAreSentAsMultimodalContent(): void
    {
        $sent = null;
        $client = $this->createClientWithApi(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = json_decode($options['body'], true);

            return new MockResponse($this->sse(['content' => 'Rouge'])."data: [DONE]\n\n", ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $this->postChat($client, ['model' => 'gemma4:12b', 'messages' => [
            ['role' => 'user', 'content' => 'Couleur ?', 'images' => [['name' => 'a.png', 'url' => self::IMAGE]]],
            ['role' => 'assistant', 'content' => 'Rouge.'],
            ['role' => 'user', 'content' => '', 'images' => [['name' => 'b.jpg', 'url' => 'data:image/jpeg;base64,AAAA']]],
            ['role' => 'assistant', 'content' => '', 'images' => [['name' => 'image-7.jpg', 'url' => 'data:image/jpeg;base64,BBBB']]],
            ['role' => 'user', 'content' => 'Merci'],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Couleur ?'], ['type' => 'image_url', 'image_url' => ['url' => self::IMAGE]]]],
            ['role' => 'assistant', 'content' => 'Rouge.'],
            ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,AAAA']]]],
            ['role' => 'assistant', 'content' => '[image « image-7.jpg » générée à partir de la demande précédente]'],
            ['role' => 'user', 'content' => 'Merci'],
        ], $sent['messages']);
    }

    public function testModelWithoutVisionAnswersWithoutImages(): void
    {
        $sent = [];
        $client = $this->createClientWithApi(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = json_decode($options['body'], true);
            if (1 === \count($sent)) {
                return new JsonMockResponse(['error' => ['message' => '{"error":{"code":400,"message":"Multimodal data provided, but model does not support multimodal requests."}}']], ['http_code' => 400]);
            }

            return new MockResponse($this->sse(['content' => 'Je ne vois pas l\'image.'])."data: [DONE]\n\n", ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $this->postChat($client, ['model' => 'phi4:latest', 'messages' => [['role' => 'user', 'content' => 'Couleur ?', 'images' => [['name' => 'a.png', 'url' => self::IMAGE]]]]]);

        $parts = $this->ndjson($client);
        self::assertSame(['type' => 'notice', 'text' => 'Ce modèle ne sait pas lire les images : elles sont ignorées.'], $parts[0]);
        self::assertSame('content', $parts[1]['type']);
        self::assertSame([['role' => 'user', 'content' => "Couleur ?\n\n[image « a.png » jointe, que tu ne peux pas voir]"]], $sent[1]['messages']);
    }

    public function testInvalidImageIsRejected(): void
    {
        $client = $this->createClientWithApi();

        $this->postChat($client, ['model' => 'gemma4:12b', 'messages' => [['role' => 'user', 'content' => 'Hop', 'images' => [['name' => 'x.svg', 'url' => 'data:image/svg+xml;base64,PHN2Zz4=']]]]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testEmptyMessageWithoutAttachmentIsRejected(): void
    {
        $client = $this->createClientWithApi();

        $this->postChat($client, ['model' => 'gemma4:12b', 'messages' => [['role' => 'user', 'content' => ' ']]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testModelWithoutToolsAnswersWithoutSearching(): void
    {
        $sent = [];
        $client = $this->createClientWithApi(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = json_decode($options['body'], true);
            if (1 === \count($sent)) {
                return new JsonMockResponse(['error' => ['message' => 'registry.ollama.ai/library/phi4:latest does not support tools']], ['http_code' => 400]);
            }

            return new MockResponse($this->sse(['content' => 'Bonjour'])."data: [DONE]\n\n", ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $this->postChat($client, ['model' => 'phi4:latest', 'messages' => [['role' => 'user', 'content' => 'Salut']], 'webSearch' => true]);

        $parts = $this->ndjson($client);
        self::assertSame('notice', $parts[0]['type']);
        self::assertSame(['type' => 'content', 'text' => 'Bonjour'], $parts[1]);
        self::assertArrayNotHasKey('tools', $sent[1]);
        self::assertSame([['role' => 'user', 'content' => 'Salut']], $sent[1]['messages']);
    }

    public function testModelThatStopsAfterThinkingIsAskedToAnswer(): void
    {
        $sent = [];
        $client = $this->createClientWithApi(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = json_decode($options['body'], true);

            return new MockResponse(
                (1 === \count($sent) ? $this->sse(['reasoning' => 'Je vais répondre.']) : $this->sse(['content' => 'Voilà.']))."data: [DONE]\n\n",
                ['response_headers' => ['content-type' => 'text/event-stream']],
            );
        });

        $this->postChat($client, ['model' => 'qwen3.6:35b-a3b', 'messages' => [['role' => 'user', 'content' => 'Salut']], 'webSearch' => true]);

        self::assertSame(['type' => 'content', 'text' => 'Voilà.'], $this->ndjson($client)[1]);
        self::assertCount(2, $sent);
        self::assertArrayNotHasKey('tools', $sent[1]);
        self::assertStringContainsString('réponse finale', end($sent[1]['messages'])['content']);
    }

    private function createClientWithApi(mixed $responses = null): KernelBrowser
    {
        $client = static::createClient();
        $users = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $client->loginUser($users->loadUserByIdentifier('lucien'));
        static::getContainer()->set('jin.client', new MockHttpClient($responses, 'http://localhost:8080'));

        return $client;
    }

    private function postChat(KernelBrowser $client, array $payload): void
    {
        $client->request('POST', '/chat', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
    }

    private function sse(array $delta): string
    {
        return 'data: '.json_encode(['choices' => [['delta' => $delta]]])."\n\n";
    }

    private function ndjson(KernelBrowser $client): array
    {
        $lines = array_filter(explode("\n", $client->getInternalResponse()->getContent()));

        return array_map(static fn (string $line): array => json_decode($line, true), array_values($lines));
    }
}
