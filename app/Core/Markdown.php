<?php
// app/Core/Markdown.php
declare(strict_types=1);

namespace App\Core;

/**
 * Renderizador Markdown mínimo para os documentos legais (legal/*.md).
 * Suporta: títulos (#..####), parágrafos, listas com "-" ou "1.", negrito, itálico, links, linhas horizontais
 * e blocos de citação. Todo texto é escapado antes da marcação: o Markdown é confiável (arquivos do projeto),
 * mas o escape garante que nada vire HTML acidental.
 */
final class Markdown
{
    public static function render(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html = '';
        $paragraph = [];
        $listType = null;
        $quote = [];
        $tableOpen = false;

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html .= '<p>' . self::inline(implode(' ', $paragraph)) . "</p>\n";
                $paragraph = [];
            }
        };
        $closeList = static function () use (&$listType, &$html): void {
            if ($listType !== null) {
                $html .= "</{$listType}>\n";
                $listType = null;
            }
        };
        $flushQuote = static function () use (&$quote, &$html): void {
            if ($quote !== []) {
                $html .= '<blockquote>' . self::inline(implode(' ', $quote)) . "</blockquote>\n";
                $quote = [];
            }
        };

        foreach ($lines as $line) {
            $trimmed = rtrim($line);
            if ($trimmed === '') {
                $flushParagraph();
                $closeList();
                $flushQuote();
                if ($tableOpen) {
                    $html .= "</tbody></table></div>\n";
                    $tableOpen = false;
                }
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $m)) {
                $flushParagraph();
                $closeList();
                $flushQuote();
                $level = strlen($m[1]);
                $id = self::slug($m[2]);
                $html .= "<h{$level} id=\"" . e($id) . '">' . self::inline($m[2]) . "</h{$level}>\n";
                continue;
            }
            if (preg_match('/^(-{3,}|\*{3,})$/', $trimmed)) {
                $flushParagraph();
                $closeList();
                $flushQuote();
                $html .= "<hr>\n";
                continue;
            }
            if (str_starts_with($trimmed, '|')) {
                $flushParagraph();
                $closeList();
                $flushQuote();
                if (preg_match('/^\|\s*:?-{2,}/', $trimmed)) {
                    continue; // linha separadora do cabeçalho
                }
                $cells = array_map('trim', explode('|', trim($trimmed, '|')));
                if (!$tableOpen) {
                    $html .= "<div class=\"table-responsive\"><table class=\"table\">\n<thead><tr>"
                        . implode('', array_map(static fn(string $c): string => '<th>' . self::inline($c) . '</th>', $cells))
                        . "</tr></thead>\n<tbody>\n";
                    $tableOpen = true;
                    continue;
                }
                $html .= '<tr>' . implode('', array_map(static fn(string $c): string => '<td>' . self::inline($c) . '</td>', $cells)) . "</tr>\n";
                continue;
            }
            if ($tableOpen) {
                $html .= "</tbody></table></div>\n";
                $tableOpen = false;
            }
            if (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                $closeList();
                $quote[] = $m[1];
                continue;
            }
            if (preg_match('/^\s*[-*]\s+(.+)$/', $trimmed, $m)) {
                $flushParagraph();
                $flushQuote();
                if ($listType !== 'ul') {
                    $closeList();
                    $listType = 'ul';
                    $html .= "<ul>\n";
                }
                $html .= '<li>' . self::inline($m[1]) . "</li>\n";
                continue;
            }
            if (preg_match('/^\s*\d+[.)]\s+(.+)$/', $trimmed, $m)) {
                $flushParagraph();
                $flushQuote();
                if ($listType !== 'ol') {
                    $closeList();
                    $listType = 'ol';
                    $html .= "<ol>\n";
                }
                $html .= '<li>' . self::inline($m[1]) . "</li>\n";
                continue;
            }
            if ($listType !== null && preg_match('/^\s{2,}(.+)$/', $line, $m)) {
                // continuação do item anterior
                $html = preg_replace('#</li>\n$#', ' ' . self::inline($m[1]) . "</li>\n", $html) ?? $html;
                continue;
            }
            $closeList();
            $paragraph[] = trim($trimmed);
        }
        $flushParagraph();
        $closeList();
        $flushQuote();
        if ($tableOpen) {
            $html .= "</tbody></table></div>\n";
        }
        return $html;
    }

    private static function inline(string $text): string
    {
        $text = e($text);
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $href = $m[2];
            if (!preg_match('#^(https?://|mailto:|/|\#)#i', $href)) {
                return $m[1];
            }
            $external = str_starts_with($href, 'http');
            return '<a href="' . $href . '"' . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $m[1] . '</a>';
        }, $text) ?? $text;
        return $text;
    }

    public static function slug(string $text): string
    {
        $ascii = strtr(mb_strtolower($text), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? '', '-');
        return $slug === '' ? 'secao' : $slug;
    }

    /** Lê a linha "Versão: x.y" e "Vigência: dd/mm/aaaa" do cabeçalho de um documento legal. */
    /** @return array{version:string,effective:string,body:string} */
    public static function parseLegalDocument(string $markdown): array
    {
        $version = '1.0';
        $effective = '';
        if (preg_match('/^\s*Versão:\s*([\w.\-]+)\s*$/mi', $markdown, $m)) {
            $version = $m[1];
        }
        if (preg_match('/^\s*Vigência:\s*(.+?)\s*$/mi', $markdown, $m)) {
            $effective = $m[1];
        }
        $body = preg_replace('/^\s*(Versão|Vigência):.*$/mi', '', $markdown) ?? $markdown;
        return ['version' => $version, 'effective' => $effective, 'body' => $body];
    }
}
