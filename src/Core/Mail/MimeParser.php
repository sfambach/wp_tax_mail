<?php declare(strict_types=1);

namespace TaxMail\Core\Mail;

use DateTimeImmutable;
use Exception;

/**
 * Turns a raw mail (RFC 5322 + MIME) into a MailMessage: decoded headers, the first text and the first HTML body,
 * both in UTF-8. Attachments are skipped — the parsers read bodies only.
 *
 * ```mermaid
 * flowchart LR
 *   R["raw mail"] --> H["headers"]
 *   R --> P{"multipart?"}
 *   P -->|yes| S["split at boundary, recurse"]
 *   P -->|no| D["transfer decoding → charset → UTF-8"]
 * ```
 */
final class MimeParser
{
    private const FORWARDED = '/Forwarded Message -+\s*Subject:\s*(.*?)\s*Date:\s*(.*?)\s*From:\s*(.*?)\s*To:/s';

    public function parse(int $uid, string $raw): MailMessage
    {
        [$headers, $body] = self::split($raw);
        $plain = '';
        $html = '';
        $this->collect($headers, $body, $plain, $html);

        // The shop parsers read the HTML as lines, as the Python importer did; plain text only when there is no HTML.
        $text = $html !== '' ? self::htmlToText($html) : str_replace("\r", '', $plain);
        $subject = self::decodeHeader($headers['subject'] ?? '');
        $date = $headers['date'] ?? '';
        $from = $headers['from'] ?? '';

        // A forwarded shop mail (JLCPCB reaches the mailbox that way) counts as the original: its sender, subject, date.
        if (preg_match(self::FORWARDED, mb_substr($text, 0, 3000), $forwarded, PREG_OFFSET_CAPTURE) === 1) {
            [$subject, $date, $from] = [trim($forwarded[1][0]), trim($forwarded[2][0]), trim($forwarded[3][0])];
            $text = substr($text, $forwarded[0][1] + strlen($forwarded[0][0]));
        } else {
            $subject = preg_replace('/^(Fwd|WG|AW|Fw):\s*/i', '', $subject) ?? $subject;
        }

        return new MailMessage(
            $uid,
            self::address($from),
            $subject,
            self::date($date),
            $text,
            $html,
            trim($headers['message-id'] ?? ''),
        );
    }

    /** Readable text from shop HTML, line for line as the Python importer made it: every cell and block on its own line. */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</(p|div|tr|td|li|h\d|table)>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(preg_replace('/<[^>]+>/', ' ', $html) ?? $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{034F}\x{200C}\x{200B}\x{00AD}\x{2007}]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\n\s*\n+/', "\n", $text) ?? $text);
    }

    /** @param array<string, string> $headers */
    private function collect(array $headers, string $body, string &$text, string &$html): void
    {
        [$type, $parameters] = self::contentType($headers['content-type'] ?? 'text/plain');

        if (str_starts_with($type, 'multipart/') && isset($parameters['boundary'])) {
            foreach (self::parts($body, $parameters['boundary']) as $part) {
                [$partHeaders, $partBody] = self::split($part);
                $this->collect($partHeaders, $partBody, $text, $html);
            }

            return;
        }

        if (str_starts_with(strtolower($headers['content-disposition'] ?? ''), 'attachment')) {
            return;
        }

        if ($type === 'message/rfc822') {
            [$innerHeaders, $innerBody] = self::split($body);
            $this->collect($innerHeaders, $innerBody, $text, $html);

            return;
        }

        if ($type !== 'text/plain' && $type !== 'text/html') {
            return;
        }

        $decoded = self::toUtf8(self::transferDecode($body, $headers['content-transfer-encoding'] ?? ''), $parameters['charset'] ?? 'us-ascii');

        if ($type === 'text/plain' && $text === '') {
            $text = $decoded;
        } elseif ($type === 'text/html' && $html === '') {
            $html = $decoded;
        }
    }

    /** @return array{0: array<string, string>, 1: string} lower-case header names, unfolded; and the body */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $end = strpos($raw, "\n\n");
        $head = $end === false ? $raw : substr($raw, 0, $end);
        $body = $end === false ? '' : substr($raw, $end + 2);
        $head = preg_replace("/\n[ \t]+/", ' ', $head) ?? $head;
        $headers = [];

        foreach (explode("\n", $head) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            // The first occurrence wins — later ones are usually relays' Received lines or forged duplicates.
            $headers[$name] ??= trim(substr($line, $colon + 1));
        }

        return [$headers, $body];
    }

    /** @return list<string> */
    private static function parts(string $body, string $boundary): array
    {
        $body = str_replace("\r\n", "\n", $body);
        $chunks = explode("\n--" . $boundary, "\n" . $body);
        array_shift($chunks);
        $parts = [];

        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break;
            }

            // The chunk starts with the rest of the boundary line (padding, then a line break).
            $lineEnd = strpos($chunk, "\n");
            $parts[] = $lineEnd === false ? '' : substr($chunk, $lineEnd + 1);
        }

        return $parts;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function contentType(string $header): array
    {
        $pieces = explode(';', $header);
        $type = strtolower(trim(array_shift($pieces)));
        $parameters = [];

        foreach ($pieces as $piece) {
            if (preg_match('/^\s*([\w*-]+)\s*=\s*"?([^"]*)"?\s*$/', $piece, $match) === 1) {
                $parameters[strtolower($match[1])] = $match[2];
            }
        }

        return [$type, $parameters];
    }

    private static function transferDecode(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64'           => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset));

        if ($charset === 'utf-8' || $charset === 'utf8' || $charset === 'us-ascii' || $charset === '') {
            return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);

        return $converted === false ? mb_convert_encoding($text, 'UTF-8', 'Windows-1252') : $converted;
    }

    /**
     * RFC 2047 words decoded by hand: a sender may split one UTF-8 character across two encoded words, and iconv then
     * gives up on the whole header. Adjacent words of one charset are joined as bytes first, then converted.
     */
    private static function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $parts = preg_split('/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
        $out = '';
        $pendingBytes = '';
        $pendingCharset = null;

        foreach ($parts as $index => $part) {
            if (preg_match('/^=\?([^?*]+)(?:\*[^?]*)?\?([BbQq])\?([^?]*)\?=$/', $part, $word) === 1) {
                $charset = strtoupper($word[1]);
                $bytes = strtoupper($word[2]) === 'B'
                    ? (string) base64_decode($word[3])
                    : quoted_printable_decode(str_replace('_', ' ', $word[3]));

                if ($pendingCharset !== null && $pendingCharset !== $charset) {
                    $out .= self::toUtf8($pendingBytes, $pendingCharset);
                    $pendingBytes = '';
                }

                $pendingCharset = $charset;
                $pendingBytes .= $bytes;
                continue;
            }

            // Whitespace between two encoded words belongs to the encoding, not to the text.
            if ($pendingCharset !== null && trim($part) === '' && isset($parts[$index + 1])) {
                continue;
            }

            if ($pendingCharset !== null) {
                $out .= self::toUtf8($pendingBytes, $pendingCharset);
                $pendingBytes = '';
                $pendingCharset = null;
            }

            $out .= $part;
        }

        if ($pendingCharset !== null) {
            $out .= self::toUtf8($pendingBytes, $pendingCharset);
        }

        return $out;
    }

    private static function address(string $from): string
    {
        $from = self::decodeHeader($from);

        if (preg_match('/<([^>]+)>/', $from, $match) === 1) {
            return strtolower(trim($match[1]));
        }

        return strtolower(trim($from));
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(preg_replace('/\s*\([^)]*\)\s*$/', '', $value) ?? $value);
        } catch (Exception) {
            return null;
        }
    }
}
