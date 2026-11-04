<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ChatControllerTest extends WebTestCase
{
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

    private function createClientWithApi(mixed $responses = null): KernelBrowser
    {
        $client = static::createClient();
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
