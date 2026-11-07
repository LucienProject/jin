<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SpeechControllerTest extends WebTestCase
{
    public function testTranscribesRecording(): void
    {
        $sent = null;
        $client = $this->createClientWithSpeech(function (string $method, string $url, array $options) use (&$sent): JsonMockResponse {
            $body = $options['body'];
            $sent = ['url' => $url, 'type' => $this->header($options, 'content-type'), 'body' => \is_callable($body) ? $this->drain($body) : (\is_resource($body) ? stream_get_contents($body) : $body)];

            return new JsonMockResponse(['text' => 'Bonjour Jin', 'language' => 'fr', 'seconds' => 1.2]);
        });

        $this->upload($client, 'fake-opus-data', 'audio/webm', 'en');

        self::assertResponseIsSuccessful();
        self::assertSame(['url' => 'http://127.0.0.1:8891/transcribe?language=en', 'type' => 'audio/webm', 'body' => 'fake-opus-data'], $sent);
        self::assertSame(['text' => 'Bonjour Jin', 'language' => 'fr', 'seconds' => 1.2], json_decode($client->getResponse()->getContent(), true));
    }

    public function testDefaultsToFrench(): void
    {
        $url = null;
        $client = $this->createClientWithSpeech(function (string $method, string $sentUrl) use (&$url): JsonMockResponse {
            $url = $sentUrl;

            return new JsonMockResponse(['text' => 'Bonjour', 'language' => 'fr', 'seconds' => 1.0]);
        });

        $this->upload($client, 'fake-opus-data', 'audio/webm');

        self::assertResponseIsSuccessful();
        self::assertSame('http://127.0.0.1:8891/transcribe?language=fr', $url);
    }

    public function testRejectsOtherLanguages(): void
    {
        $client = $this->createClientWithSpeech(static fn () => throw new \LogicException('service appelé'));

        $this->upload($client, 'fake-opus-data', 'audio/webm', 'de');

        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresRecording(): void
    {
        $client = $this->createClientWithSpeech();

        $client->request('POST', '/transcriptions');

        self::assertResponseStatusCodeSame(422);
    }

    public function testReportsServiceError(): void
    {
        $client = $this->createClientWithSpeech(new JsonMockResponse(['detail' => 'Audio illisible.'], ['http_code' => 422]));

        $this->upload($client, 'garbage', 'audio/webm');

        self::assertResponseStatusCodeSame(502);
        self::assertSame('Audio illisible.', json_decode($client->getResponse()->getContent(), true)['detail']);
    }

    public function testReportsServiceDown(): void
    {
        $client = $this->createClientWithSpeech(new MockResponse('', ['error' => 'Connection refused']));

        $this->upload($client, 'fake-opus-data', 'audio/webm');

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('injoignable', json_decode($client->getResponse()->getContent(), true)['detail']);
    }

    private function createClientWithSpeech(mixed $responses = null): KernelBrowser
    {
        $client = static::createClient();
        $users = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $client->loginUser($users->loadUserByIdentifier('lucien'));
        static::getContainer()->set('speech.client', new MockHttpClient($responses, 'http://127.0.0.1:8891'));

        return $client;
    }

    private function upload(KernelBrowser $client, string $content, string $type, ?string $language = null): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rec');
        file_put_contents($path, $content);
        $client->request('POST', '/transcriptions', null === $language ? [] : ['language' => $language], ['audio' => new UploadedFile($path, 'dictee.webm', $type, null, true)]);
    }

    private function header(array $options, string $name): ?string
    {
        foreach ($options['headers'] as $line) {
            [$key, $value] = explode(': ', $line, 2);
            if (strtolower($key) === $name) {
                return $value;
            }
        }

        return null;
    }

    private function drain(callable $body): string
    {
        $data = '';
        while ('' !== $chunk = $body(16384)) {
            $data .= $chunk;
        }

        return $data;
    }
}
