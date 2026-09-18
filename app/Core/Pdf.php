<?php
// app/Core/Pdf.php
declare(strict_types=1);

namespace App\Core;

/**
 * Gerador de PDF simples, sem dependências: texto (Helvetica/WinAnsi, acentos ok), títulos, parágrafos e tabelas
 * com quebra automática de página. Suficiente para os relatórios; não faz imagens nem fontes embutidas.
 */
final class Pdf
{
    public const WIDTH = 595.28;   // A4 em pontos
    public const HEIGHT = 841.89;
    public const MARGIN = 42.0;

    /** @var list<string> conteúdo de cada página */
    private array $pages = [];
    private string $content = '';
    private float $y = 0.0;
    private int $pageNumber = 0;

    public function __construct(private readonly string $title, private readonly string $subtitle = '')
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
        }
        $this->pageNumber++;
        $this->content = '';
        $this->y = self::HEIGHT - self::MARGIN;
        // Cabeçalho da página
        $this->rawText(self::MARGIN, $this->y - 14, $this->title, 15, true);
        if ($this->subtitle !== '') {
            $this->rawText(self::MARGIN, $this->y - 28, $this->subtitle, 9, false, 0.4);
        }
        $this->content .= sprintf("0.8 G 0.5 w %.2f %.2f m %.2f %.2f l S\n", self::MARGIN, $this->y - 36, self::WIDTH - self::MARGIN, $this->y - 36);
        $this->y -= 52;
        // Rodapé
        $this->rawText(self::MARGIN, 24, 'Nosso Cofre · gerado em ' . date('d/m/Y H:i'), 7, false, 0.5);
        $footer = 'Página ' . $this->pageNumber;
        $this->rawText(self::WIDTH - self::MARGIN - $this->textWidth($footer, 7), 24, $footer, 7, false, 0.5);
    }

    public function heading(string $text, float $size = 12): void
    {
        $this->ensureSpace($size + 14);
        $this->y -= $size + 4;
        $this->rawText(self::MARGIN, $this->y, $text, $size, true);
        $this->y -= 6;
    }

    /** Parágrafo com quebra de linha automática. */
    public function paragraph(string $text, float $size = 9.5, float $gray = 0.0): void
    {
        $maxWidth = self::WIDTH - 2 * self::MARGIN;
        foreach ($this->wrap($text, $size, $maxWidth) as $line) {
            $this->ensureSpace($size + 4);
            $this->y -= $size + 3;
            $this->rawText(self::MARGIN, $this->y, $line, $size, false, $gray);
        }
        $this->y -= 4;
    }

    /** Pares "rótulo: valor" em duas colunas. @param list<array{0:string,1:string}> $pairs */
    public function keyValues(array $pairs, float $size = 9.5): void
    {
        $half = (self::WIDTH - 2 * self::MARGIN) / 2;
        foreach (array_chunk($pairs, 2) as $row) {
            $this->ensureSpace($size + 4);
            $this->y -= $size + 4;
            foreach ($row as $i => [$label, $value]) {
                $x = self::MARGIN + $i * $half;
                $this->rawText($x, $this->y, $label, $size, false, 0.45);
                $this->rawText($x + $this->textWidth($label, $size) + 4, $this->y, $value, $size, true);
            }
        }
        $this->y -= 4;
    }

    /**
     * Tabela com cabeçalho repetido a cada página.
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<float> $widths frações da largura útil (somam 1)
     * @param list<string> $aligns 'L' | 'R' por coluna
     * @param list<int> $boldRows índices de linhas em negrito (totais)
     */
    public function table(array $headers, array $rows, array $widths, array $aligns = [], array $boldRows = [], float $size = 8.5): void
    {
        $usable = self::WIDTH - 2 * self::MARGIN;
        $cols = count($headers);
        $widths = $widths === [] ? array_fill(0, $cols, 1 / max(1, $cols)) : $widths;
        $rowHeight = $size + 7;
        $drawHeader = function () use ($headers, $widths, $aligns, $usable, $size, $rowHeight): void {
            $this->y -= $rowHeight;
            $this->content .= sprintf("0.93 g %.2f %.2f %.2f %.2f re f 0 g\n", self::MARGIN, $this->y - 3, $usable, $rowHeight);
            $x = self::MARGIN;
            foreach ($headers as $i => $h) {
                $w = $usable * $widths[$i];
                $this->cell($x, $this->y, $w, $h, $size, true, $aligns[$i] ?? 'L');
                $x += $w;
            }
        };
        $this->ensureSpace($rowHeight * 2);
        $drawHeader();
        foreach ($rows as $r => $row) {
            if ($this->y - $rowHeight < self::MARGIN + 30) {
                $this->addPage();
                $drawHeader();
            }
            $this->y -= $rowHeight;
            $this->content .= sprintf("0.85 G 0.3 w %.2f %.2f m %.2f %.2f l S\n", self::MARGIN, $this->y + $rowHeight - 3, self::WIDTH - self::MARGIN, $this->y + $rowHeight - 3);
            $x = self::MARGIN;
            $bold = in_array($r, $boldRows, true);
            foreach ($row as $i => $cellText) {
                $w = $usable * ($widths[$i] ?? 0.1);
                $this->cell($x, $this->y, $w, (string) $cellText, $size, $bold, $aligns[$i] ?? 'L');
                $x += $w;
            }
        }
        $this->content .= sprintf("0.85 G 0.3 w %.2f %.2f m %.2f %.2f l S\n", self::MARGIN, $this->y - 3, self::WIDTH - self::MARGIN, $this->y - 3);
        $this->y -= 8;
    }

    /** Monta o arquivo PDF. */
    public function output(): string
    {
        $pages = $this->pages;
        $pages[] = $this->content;
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($pages as $stream) {
            $pageId = $n++;
            $contentId = $n++;
            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::WIDTH, self::HEIGHT, $contentId);
            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $count = count($objects) + 1;
        $out .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $info = '<< /Title (' . $this->escape($this->title) . ') /Producer (Nosso Cofre) /CreationDate (D:' . date('YmdHis') . ') >>';
        $out .= "trailer\n<< /Size {$count} /Root 1 0 R /Info {$info} >>\nstartxref\n{$xref}\n%%EOF\n";
        return $out;
    }

    // --- internos ---

    private function ensureSpace(float $needed): void
    {
        if ($this->y - $needed < self::MARGIN + 30) {
            $this->addPage();
        }
    }

    private function cell(float $x, float $y, float $width, string $text, float $size, bool $bold, string $align): void
    {
        $text = $this->truncate($text, $size, $width - 6);
        $tx = $align === 'R' ? $x + $width - 3 - $this->textWidth($text, $size) : $x + 3;
        $this->rawText($tx, $y, $text, $size, $bold);
    }

    private function rawText(float $x, float $y, string $text, float $size, bool $bold = false, float $gray = 0.0): void
    {
        $this->content .= sprintf("BT %.2f g /F%d %.1f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET 0 g\n", $gray, $bold ? 2 : 1, $size, $x, $y, $this->escape($text));
    }

    private function escape(string $text): string
    {
        $encoded = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);
        if ($encoded === false) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
        }
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
    }

    /** Largura aproximada do texto em Helvetica (suficiente para alinhar colunas). */
    public function textWidth(string $text, float $size): float
    {
        $w = 0.0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $w += match (true) {
                $ch === ' ' || $ch === '.' || $ch === ',' || $ch === ':' || $ch === ';' || $ch === '!' || $ch === "'" => 0.28,
                $ch === 'i' || $ch === 'l' || $ch === 'j' || $ch === 'I' || $ch === 'í' || $ch === '|' => 0.24,
                $ch === 't' || $ch === 'f' || $ch === 'r' || $ch === '-' || $ch === '(' || $ch === ')' => 0.34,
                $ch === 'm' || $ch === 'w' => 0.83,
                $ch === 'M' || $ch === 'W' => 0.92,
                ctype_upper($ch) => 0.68,
                ctype_digit($ch) => 0.556,
                default => 0.53,
            };
        }
        return $w * $size;
    }

    private function truncate(string $text, float $size, float $maxWidth): string
    {
        if ($this->textWidth($text, $size) <= $maxWidth) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $this->textWidth($text . '…', $size) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '…';
    }

    /** @return list<string> */
    private function wrap(string $text, float $size, float $maxWidth): array
    {
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if ($this->textWidth($candidate, $size) > $maxWidth && $line !== '') {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }
}
