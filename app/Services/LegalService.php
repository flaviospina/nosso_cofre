<?php
// app/Services/LegalService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Markdown;

/**
 * Documentos legais versionados em legal/*.md (cabeçalho "Versão:" e "Vigência:").
 * Placeholders {{controller_name}}, {{controller_document}}, {{controller_email}}, {{dpo_name}}, {{dpo_email}}
 * e {{app_url}} são preenchidos com o .env.
 */
final class LegalService
{
    private const FILES = ['terms' => 'termos-de-uso.md', 'privacy' => 'politica-de-privacidade.md'];

    /** @return array{version:string,effective:string,html:string,title:string} */
    public static function document(string $kind): array
    {
        $file = (string) Config::get('paths.legal') . '/' . (self::FILES[$kind] ?? '');
        $markdown = is_file($file) ? (string) file_get_contents($file) : "# Documento indisponível\n\nO arquivo legal/" . (self::FILES[$kind] ?? '') . " não foi encontrado.";
        $markdown = self::fill($markdown);
        $parsed = Markdown::parseLegalDocument($markdown);
        return [
            'version'   => $parsed['version'],
            'effective' => $parsed['effective'],
            'html'      => Markdown::render($parsed['body']),
            'title'     => $kind === 'terms' ? 'Termos de Uso' : 'Política de Privacidade',
        ];
    }

    public static function version(string $kind): string
    {
        return self::document($kind)['version'];
    }

    private static function fill(string $text): string
    {
        $doc = (string) Config::get('legal.controller_document', '');
        $masked = $doc === '' ? '(não informado)' : self::maskDocument($doc);
        return strtr($text, [
            '{{controller_name}}'     => (string) Config::get('legal.controller_name', '(não informado)'),
            '{{controller_document}}' => $masked,
            '{{controller_email}}'    => (string) Config::get('legal.controller_email', ''),
            '{{dpo_name}}'            => (string) Config::get('legal.dpo_name', ''),
            '{{dpo_email}}'           => (string) Config::get('legal.dpo_email', ''),
            '{{app_url}}'             => (string) Config::get('app.url', ''),
            '{{app_name}}'            => (string) Config::get('app.name', 'Nosso Cofre'),
        ]);
    }

    /** CPF 123.456.789-09 → ***.456.789-**; CNPJ mantém os 8 primeiros dígitos. */
    public static function maskDocument(string $doc): string
    {
        $digits = preg_replace('/\D/', '', $doc) ?? '';
        if (strlen($digits) === 11) {
            return '***.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-**';
        }
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3) . '/****-**';
        }
        return $doc;
    }
}
