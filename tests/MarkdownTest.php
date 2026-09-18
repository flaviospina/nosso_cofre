<?php
// tests/MarkdownTest.php
declare(strict_types=1);

use App\Core\Markdown;

function test_markdown_basic_blocks(): void
{
    $html = Markdown::render("# Título\n\nParágrafo com **negrito** e *itálico* e [link](/privacidade).\n\n- item um\n- item dois\n\n1. primeiro\n2. segundo\n\n> citação\n\n---\n");
    assert_contains('<h1 id="titulo">Título</h1>', $html);
    assert_contains('<p>Parágrafo com <strong>negrito</strong> e <em>itálico</em> e <a href="/privacidade">link</a>.</p>', $html);
    assert_contains("<ul>\n<li>item um</li>\n<li>item dois</li>\n</ul>", $html);
    assert_contains("<ol>\n<li>primeiro</li>\n<li>segundo</li>\n</ol>", $html);
    assert_contains('<blockquote>citação</blockquote>', $html);
    assert_contains('<hr>', $html);
}

function test_markdown_escapes_html_and_rejects_bad_links(): void
{
    $html = Markdown::render("Texto <script>alert(1)</script> e [x](javascript:alert(1))");
    assert_contains('&lt;script&gt;', $html);
    assert_false(str_contains($html, '<script>'));
    assert_false(str_contains($html, 'javascript:'));
}

function test_markdown_table_and_legal_header(): void
{
    $doc = Markdown::parseLegalDocument("Versão: 1.0\nVigência: 18/09/2026\n\n# Doc\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\nFim.");
    assert_same('1.0', $doc['version']);
    assert_same('18/09/2026', $doc['effective']);
    $html = Markdown::render($doc['body']);
    assert_contains('<thead><tr><th>A</th><th>B</th></tr></thead>', $html);
    assert_contains('<tr><td>1</td><td>2</td></tr>', $html);
    assert_contains('</tbody></table></div>', $html);
    assert_contains('<p>Fim.</p>', $html);
}
