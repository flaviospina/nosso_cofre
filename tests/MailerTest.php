<?php
// tests/MailerTest.php
declare(strict_types=1);

use App\Core\Mailer;

function test_mailer_html_to_text(): void
{
    $text = Mailer::htmlToText('<p>Olá, <strong>Flávio</strong>!</p><p>Clique em <a href="https://x.test/a">Confirmar</a>.</p><ul><li>um</li><li>dois</li></ul>');
    assert_contains("Olá, Flávio!", $text);
    assert_contains('Confirmar (https://x.test/a)', $text);
    assert_contains("- um\n- dois", $text);
    assert_false(str_contains($text, '<'));
}
