<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge une page web et en extrait le texte lisible, pour l'outil fetch_url.
 */
final readonly class WebPageReader
{
    private const int MAX_BYTES = 3_000_000;

    public function __construct(
        private HttpClientInterface $client,
    ) {
    }

    /**
     * @return array{title: string, text: string, truncated: bool}
     *
     * @throws \RuntimeException page illisible (adresse refusée, erreur HTTP, format non pris en charge…)
     */
    public function read(string $url, int $maxChars = 8000): array
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Seules les adresses http(s) sont acceptées.');
        }

        $response = $this->client->request('GET', $url, [
            'timeout' => 15,
            'max_redirects' => 5,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) JinChat/1.0',
                'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.5',
                'Accept-Language' => 'fr,en;q=0.8',
            ],
            // coupe les téléchargements démesurés
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new \RuntimeException('Page trop volumineuse.');
                }
            },
        ]);

        try {
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new \RuntimeException(\sprintf('La page a répondu HTTP %d.', $status));
            }
            $type = strtolower($response->getHeaders(false)['content-type'][0] ?? 'text/html');
            $body = $response->getContent(false);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if (str_contains($type, 'html') || str_contains($type, 'xml')) {
            [$title, $text] = $this->extract($body, $type);
        } elseif (str_starts_with($type, 'text/') || str_contains($type, 'json')) {
            [$title, $text] = ['', $body];
        } else {
            throw new \RuntimeException(\sprintf('Format non pris en charge (%s).', strtok($type, ';')));
        }

        $text = trim(preg_replace(["/[ \t\u{a0}]+/u", "/\\s*\n\\s*/u"], [' ', "\n"], $text) ?? $text);
        $truncated = mb_strlen($text) > $maxChars;

        return ['title' => $title, 'text' => $truncated ? mb_substr($text, 0, $maxChars) : $text, 'truncated' => $truncated];
    }

    /**
     * @return array{string, string} titre et texte, sans menus, scripts ni pieds de page
     */
    private function extract(string $html, string $type): array
    {
        if (preg_match('/charset=([\w-]+)/', $type, $m) && 'utf-8' !== strtolower($m[1])) {
            $html = mb_convert_encoding($html, 'UTF-8', $m[1]);
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $title = trim($xpath->evaluate('string(//title)'));

        foreach (iterator_to_array($xpath->query('//script|//style|//noscript|//svg|//nav|//header|//footer|//aside|//form|//iframe|//template|//*[@aria-hidden="true"]|//*[@role="navigation"]')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        // passages à la ligne entre les blocs, sinon les mots des paragraphes se collent
        foreach (iterator_to_array($xpath->query('//p|//div|//li|//br|//tr|//h1|//h2|//h3|//h4|//h5|//h6|//section|//article|//pre|//blockquote')) as $node) {
            $node->appendChild($dom->createTextNode("\n"));
        }

        $main = $xpath->query('//main|//article|//*[@role="main"]')->item(0) ?? $dom->getElementsByTagName('body')->item(0) ?? $dom;

        return [$title, $main->textContent];
    }
}
