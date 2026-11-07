<?php

namespace App\Tests\Service;

use App\Service\WebPageReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebPageReaderTest extends TestCase
{
    public function testExtractsMainTextWithoutMenusAndScripts(): void
    {
        $html = <<<'HTML'
            <html><head><title>Ma page</title><style>p{}</style></head><body>
            <nav>Accueil | Contact</nav>
            <main><h1>Titre</h1><p>Premier   paragraphe.</p><script>alert(1)</script><p>Deuxième <b>paragraphe</b>, accentué.</p></main>
            <footer>© 2026</footer>
            </body></html>
            HTML;
        $reader = new WebPageReader(new MockHttpClient(new MockResponse($html, ['response_headers' => ['content-type' => 'text/html; charset=utf-8']])));

        $page = $reader->read('https://example.com/page');

        self::assertSame('Ma page', $page['title']);
        self::assertSame("Titre\nPremier paragraphe.\nDeuxième paragraphe, accentué.", $page['text']);
        self::assertFalse($page['truncated']);
    }

    public function testTruncatesLongPages(): void
    {
        $reader = new WebPageReader(new MockHttpClient(new MockResponse(str_repeat('é', 100), ['response_headers' => ['content-type' => 'text/plain']])));

        $page = $reader->read('https://example.com/long.txt', maxChars: 10);

        self::assertSame(str_repeat('é', 10), $page['text']);
        self::assertTrue($page['truncated']);
    }

    public function testRejectsOtherSchemesAndBinaryFiles(): void
    {
        $reader = new WebPageReader(new MockHttpClient(new MockResponse('%PDF', ['response_headers' => ['content-type' => 'application/pdf']])));

        try {
            $reader->read('file:///etc/passwd');
            self::fail('file:// accepté');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('http(s)', $e->getMessage());
        }

        $this->expectExceptionMessage('Format non pris en charge (application/pdf)');
        $reader->read('https://example.com/doc.pdf');
    }

    public function testRefusesLocalNetwork(): void
    {
        $reader = new WebPageReader(new NoPrivateNetworkHttpClient(new MockHttpClient(new MockResponse('secret'))));

        foreach (['http://127.0.0.1:11434/api/tags', 'http://localhost:8080/', 'http://192.168.1.1/', 'http://[::1]/'] as $url) {
            try {
                $reader->read($url);
                self::fail("$url accepté");
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString('secret', $e->getMessage());
            }
        }
    }
}
