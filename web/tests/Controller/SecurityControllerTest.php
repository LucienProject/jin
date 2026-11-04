<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le mot de passe de test est "test" (hash dans .env.test).
 */
final class SecurityControllerTest extends WebTestCase
{
    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAnonymousCannotChat(): void
    {
        $client = static::createClient();

        $client->request('POST', '/chat', server: ['CONTENT_TYPE' => 'application/json'], content: '{"model":"x","messages":[{"role":"user","content":"Salut"}]}');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testLoginWithRightPassword(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $client->submitForm('Se connecter', ['_username' => 'lucien', '_password' => 'test']);

        self::assertResponseRedirects('http://localhost/');
        $this->assertNotNull(static::getContainer()->get('security.token_storage')->getToken()?->getUser());
    }

    public function testLoginWithWrongPassword(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $client->submitForm('Se connecter', ['_username' => 'lucien', '_password' => 'mauvais']);
        $client->followRedirect();

        self::assertSelectorExists('.notice.error');
        self::assertSelectorExists('input[name="_username"][value="lucien"]');
    }

    public function testLoginBehindNgrokRedirectsToHttps(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'jin.ngrok-free.app',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        self::assertResponseRedirects('https://jin.ngrok-free.app/login');
    }
}
