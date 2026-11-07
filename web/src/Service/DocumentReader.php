<?php

namespace App\Service;

use Smalot\PdfParser\Parser;

/**
 * Extrait le texte d'un fichier envoyé, pour le donner à lire au modèle :
 * PDF, Word (.docx), OpenDocument (.odt) et tout fichier texte (txt, md, csv, json, code…).
 */
final class DocumentReader
{
    /** au-delà, le texte est coupé : environ 30 000 tokens, la moitié du contexte des modèles */
    public const int MAX_CHARS = 100_000;

    /**
     * @return array{text: string, truncated: bool}
     *
     * @throws \RuntimeException fichier illisible ou format non pris en charge
     */
    public function read(string $path, string $name): array
    {
        $head = (string) file_get_contents($path, length: 8);
        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        $text = match (true) {
            str_starts_with($head, '%PDF') => $this->pdf($path),
            str_starts_with($head, "PK\x03\x04") && 'docx' === $extension => $this->zipXml($path, 'word/document.xml', '/<\/w:p>|<w:br\/>/', '/<w:tab\/>/'),
            str_starts_with($head, "PK\x03\x04") && 'odt' === $extension => $this->zipXml($path, 'content.xml', '/<\/text:(?:p|h)>|<text:line-break\/>/', '/<text:tab\/>/'),
            default => $this->plainText($path, $extension),
        };

        $text = trim(preg_replace(["/\r\n?/", "/[ \t\u{a0}]+\n/u", "/\n{3,}/"], ["\n", "\n", "\n\n"], $text) ?? $text);
        if ('' === $text) {
            throw new \RuntimeException('Aucun texte trouvé dans ce fichier (document scanné ?).');
        }
        $truncated = mb_strlen($text) > self::MAX_CHARS;

        return ['text' => $truncated ? mb_substr($text, 0, self::MAX_CHARS) : $text, 'truncated' => $truncated];
    }

    private function pdf(string $path): string
    {
        try {
            return (new Parser())->parseFile($path)->getText();
        } catch (\Throwable $e) {
            throw new \RuntimeException('PDF illisible : '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Texte d'un document bureautique (archive zip contenant du XML).
     */
    private function zipXml(string $path, string $entry, string $lineBreaks, string $tabs): string
    {
        // PharData ne reconnaît l'archive qu'avec une extension .zip
        $zip = tempnam(sys_get_temp_dir(), 'doc').'.zip';
        try {
            copy($path, $zip);
            $xml = @file_get_contents('phar://'.$zip.'/'.$entry);
        } finally {
            @unlink($zip);
            @unlink(substr($zip, 0, -4));
        }
        if (false === $xml) {
            throw new \RuntimeException('Document illisible.');
        }
        $xml = preg_replace([$lineBreaks, $tabs], ["\n", "\t"], $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), \ENT_QUOTES | \ENT_XML1, 'UTF-8');
    }

    private function plainText(string $path, string $extension): string
    {
        $content = (string) file_get_contents($path);
        // un octet nul : fichier binaire (image, archive, exécutable…)
        if (str_contains(substr($content, 0, 8000), "\0")) {
            throw new \RuntimeException(\sprintf('Format non pris en charge%s : seuls les PDF, documents Word/OpenDocument et fichiers texte sont lisibles.', '' !== $extension ? ' (.'.$extension.')' : ''));
        }
        // sans BOM UTF-8 ni UTF-8 valide : probablement un fichier Windows
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
}
