<?php

namespace App\Tests\Service;

use App\Service\DocumentReader;
use PHPUnit\Framework\TestCase;

final class DocumentReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), array_filter($this->files, is_file(...)));
    }

    public function testReadsPlainTextAndNormalizesLineEndings(): void
    {
        $result = (new DocumentReader())->read($this->file("\xEF\xBB\xBFLigne 1  \r\nLigne 2\r\n\r\n\r\n\r\nFin\n"), 'notes.md');

        self::assertSame(['text' => "Ligne 1\nLigne 2\n\nFin", 'truncated' => false], $result);
    }

    public function testConvertsWindowsEncodedText(): void
    {
        $result = (new DocumentReader())->read($this->file("Caf\xE9 cr\xE8me"), 'menu.csv');

        self::assertSame('Café crème', $result['text']);
    }

    public function testTruncatesLongText(): void
    {
        $result = (new DocumentReader())->read($this->file(str_repeat('é', DocumentReader::MAX_CHARS + 10)), 'long.txt');

        self::assertTrue($result['truncated']);
        self::assertSame(DocumentReader::MAX_CHARS, mb_strlen($result['text']));
    }

    public function testReadsWordDocument(): void
    {
        $path = $this->zip('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Titre &amp; sous-titre</w:t></w:r></w:p><w:p><w:r><w:t>A</w:t><w:tab/><w:t>B</w:t></w:r></w:p></w:body></w:document>');

        self::assertSame("Titre & sous-titre\nA\tB", (new DocumentReader())->read($path, 'rapport.docx')['text']);
    }

    public function testReadsOpenDocument(): void
    {
        $path = $this->zip('content.xml', '<office:document-content><office:body><text:h>Titre</text:h><text:p>Paragraphe</text:p></office:body></office:document-content>');

        self::assertSame("Titre\nParagraphe", (new DocumentReader())->read($path, 'rapport.odt')['text']);
    }

    public function testReadsPdf(): void
    {
        self::assertStringContainsString('Bonjour PDF', (new DocumentReader())->read($this->file($this->pdf('Bonjour PDF')), 'doc.pdf')['text']);
    }

    public function testRejectsBinaryFiles(): void
    {
        $this->expectExceptionMessage('Format non pris en charge (.png)');

        (new DocumentReader())->read($this->file("\x89PNG\r\n\x1a\n\0\0\0\rIHDR"), 'image.png');
    }

    public function testRejectsFilesWithoutText(): void
    {
        $this->expectExceptionMessage('Aucun texte');

        (new DocumentReader())->read($this->file("  \n "), 'vide.txt');
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($path, $content);

        return $this->files[] = $path;
    }

    private function zip(string $entry, string $xml): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('test', true).'.zip';
        $zip = new \PharData($path);
        $zip->addFromString($entry, $xml);
        unset($zip);

        return $this->files[] = $path;
    }

    /** PDF minimal d'une page contenant $text. */
    private function pdf(string $text): string
    {
        $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.\strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = \strlen($pdf);
        $pdf .= 'xref
0 '.(\count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(\count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
