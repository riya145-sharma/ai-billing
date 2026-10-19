<?php
declare(strict_types=1);

namespace App\Core;

/**
 * File logger (storage/logs/app-YYYY-MM-DD.log). Redacts secrets before
 * writing: api-key / Authorization headers, bearer tokens, password fields,
 * and the literal values of configured secret env vars.
 */
final class Logger
{
    private static string $dir = '';
    private static array $secrets = [];

    public static function init(string $dir, array $secretValues = []): void
    {
        self::$dir = rtrim($dir, '/');
        // Only redact non-trivial values to avoid mangling ordinary text.
        self::$secrets = array_values(array_filter($secretValues, fn($v) => is_string($v) && strlen($v) >= 8));
    }

    public static function error(string $msg, array $ctx = []): void   { self::write('ERROR', $msg, $ctx); }
    public static function warning(string $msg, array $ctx = []): void { self::write('WARNING', $msg, $ctx); }
    public static function info(string $msg, array $ctx = []): void    { self::write('INFO', $msg, $ctx); }

    public static function redact(string $text): string
    {
        foreach (self::$secrets as $s) {
            $text = str_replace($s, '[REDACTED]', $text);
        }
        $text = preg_replace('/(api-key|authorization|ocp-apim-subscription-key)\s*[:=]\s*("?)(?:(?:Bearer|Basic)\s+)?[^\s",}]+/i', '$1: $2[REDACTED]', $text);
        $text = preg_replace('/Bearer\s+(?!\[REDACTED\])[A-Za-z0-9\-\._~\+\/]+=*/i', 'Bearer [REDACTED]', $text);
        $text = preg_replace('/("?(?:password|password_hash|api_key|secret)"?\s*[:=]\s*)"[^"]*"/i', '$1"[REDACTED]"', $text);
        return $text;
    }

    private static function write(string $level, string $msg, array $ctx): void
    {
        if (self::$dir === '') {
            error_log(self::redact("[{$level}] {$msg}"));
            return;
        }
        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        @file_put_contents(self::$dir . '/app-' . gmdate('Y-m-d') . '.log', self::redact($line), FILE_APPEND | LOCK_EX);
    }
}
