<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImageControllerTest extends WebTestCase
{
    public function testGeneratesImage(): void
    {
        $sent = null;
        $client = $this->createClientWithImagegen(function (string $method, string $url, array $options) use (&$sent): JsonMockResponse {
            $sent = json_decode($options['body'], true);

            return new JsonMockResponse(['image' => 'AAAA', 'seed' => 42, 'seconds' => 12.3]);
        });

        $this->post($client, ['prompt' => 'a red fox', 'format' => 'landscape']);

        self::assertResponseIsSuccessful();
        self::assertSame(['prompt' => 'a red fox', 'width' => 1344, 'height' => 768], $sent);
        self::assertSame(['name' => 'image-42.jpg', 'url' => 'data:image/jpeg;base64,AAAA', 'model' => 'Z-Image-Turbo', 'seconds' => 12.3], json_decode($client->getResponse()->getContent(), true));
    }

    public function testReportsServiceError(): void
    {
        $client = $this->createClientWithImagegen(new JsonMockResponse(['detail' => 'Le modèle d\'images est en cours de chargement'], ['http_code' => 503]));

        $this->post($client, ['prompt' => 'a red fox']);

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('en cours de chargement', json_decode($client->getResponse()->getContent(), true)['detail']);
    }

    public function testReportsServiceDown(): void
    {
        $client = $this->createClientWithImagegen(new MockResponse('', ['error' => 'Connection refused']));

        $this->post($client, ['prompt' => 'a red fox']);

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('injoignable', json_decode($client->getResponse()->getContent(), true)['detail']);
    }

    public function testRejectsUnknownFormat(): void
    {
        $client = $this->createClientWithImagegen();

        $this->post($client, ['prompt' => 'a red fox', 'format' => 'banner']);

        self::assertResponseStatusCodeSame(422);
    }

    private function createClientWithImagegen(mixed $responses = null): KernelBrowser
    {
        $client = static::createClient();
        $users = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $client->loginUser($users->loadUserByIdentifier('lucien'));
        static::getContainer()->set('imagegen.client', new MockHttpClient($responses, 'http://127.0.0.1:8890'));

        return $client;
    }

    private function post(KernelBrowser $client, array $payload): void
    {
        $client->request('POST', '/images', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
    }
}
