<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AttachmentControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $users = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $this->client->loginUser($users->loadUserByIdentifier('lucien'));
    }

    public function testReturnsTextOfUploadedFile(): void
    {
        $this->upload('notes.md', "# Courses\n- pain\n");

        self::assertResponseIsSuccessful();
        self::assertSame(['name' => 'notes.md', 'content' => "# Courses\n- pain", 'truncated' => false], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testRejectsUnreadableFile(): void
    {
        $this->upload('photo.jpg', "\xFF\xD8\xFF\xE0\0\x10JFIF\0");

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Format non pris en charge', json_decode($this->client->getResponse()->getContent(), true)['detail']);
    }

    public function testRequiresAFile(): void
    {
        $this->client->request('POST', '/attachments', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
    }

    private function upload(string $name, string $content): void
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);
        $this->client->request('POST', '/attachments', files: ['file' => new UploadedFile($path, $name, test: true)], server: ['HTTP_ACCEPT' => 'application/json']);
    }
}
