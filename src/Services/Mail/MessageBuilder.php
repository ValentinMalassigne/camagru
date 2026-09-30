<?php
// MessageBuilder: shared header and body construction for every mail driver.
// Security role: it strips CR/LF from every header value before it is placed
// into the message, which is the protection against email header injection
// (an attacker-controlled "\r\n" inside a subject, name or address must never
// be able to inject extra headers such as Bcc). It also encodes the subject
// per RFC 2047 so UTF-8 text survives every relay.

declare(strict_types=1);

namespace App\Services\Mail;

class MessageBuilder
{
    /**
     * Remove every CR, LF and NUL from a header value. This is the
     * header-injection protection required by spec section 9; it must be
     * applied to anything that ends up in a header (addresses, display names,
     * subject).
     */
    public static function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * Encode a subject line for a mail header (RFC 2047). Pure-ASCII subjects
     * are used as-is; anything else becomes a base64 UTF-8 encoded-word. The
     * app's subjects are short ASCII strings, so a single encoded-word is
     * always within the 75-character limit per word.
     */
    public static function encodeSubject(string $subject): string
    {
        $subject = self::sanitizeHeaderValue($subject);
        if (preg_match('/[\x80-\xFF]/', $subject) !== 1) {
            return $subject;
        }
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    /**
     * Format an address header value, optionally with a display name:
     * "Name <address>" or "<address>". Both parts are sanitized.
     */
    public static function formatAddress(string $address, ?string $name = null): string
    {
        $address = self::sanitizeHeaderValue($address);
        if ($name === null || $name === '') {
            return $address;
        }
        return self::sanitizeHeaderValue($name) . ' <' . $address . '>';
    }

    /**
     * Build the header lines shared by every outgoing message. $contentType is
     * "text/html; charset=UTF-8" for a single-part message, or
     * "multipart/alternative; boundary=..." when a text body is also given.
     * The To and Subject headers are not included: mail() adds To itself and
     * the subject is a separate parameter on every driver API.
     *
     * @return array<int, string> "Name: value" lines, values sanitized.
     */
    public static function headers(string $fromAddress, ?string $fromName, string $contentType): array
    {
        return [
            'From: ' . self::formatAddress($fromAddress, $fromName),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            // Random local part: a unique Message-ID per message. The domain
            // is taken from the sender address (RFC 5322 expects them to
            // match; mismatched IDs hurt deliverability). Fallback label if
            // the sender has no domain part.
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . self::messageIdDomain($fromAddress) . '>',
            'Content-Type: ' . self::sanitizeHeaderValue($contentType),
        ];
    }

    /**
     * Domain part of a sender address, used for the Message-ID. Falls back
     * to a fixed label when the address has no domain part.
     */
    private static function messageIdDomain(string $fromAddress): string
    {
        $domain = strrchr($fromAddress, '@');
        if ($domain === false) {
            return 'camagru.local';
        }
        return self::sanitizeHeaderValue(substr($domain, 1));
    }

    /**
     * Base64-encode a body and wrap it at 76 characters per line (RFC 2045),
     * with CRLF line endings. Drivers add "Content-Transfer-Encoding: base64".
     */
    public static function encodeBody(string $body): string
    {
        return chunk_split(base64_encode($body), 76, "\r\n");
    }

    /**
     * Build the MIME body of a message. With no text alternative the body is
     * the base64 HTML alone; with one it is a multipart/alternative body
     * (text part first, HTML part last, HTML is the preferred part per
     * RFC 2046) using the given boundary.
     */
    public static function body(string $htmlBody, ?string $textBody, string $boundary): string
    {
        if ($textBody === null) {
            return self::encodeBody($htmlBody);
        }

        $textPart = '--' . $boundary . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
            . self::encodeBody($textBody);
        $htmlPart = '--' . $boundary . "\r\n"
            . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
            . self::encodeBody($htmlBody);

        return $textPart . $htmlPart . '--' . $boundary . "--\r\n";
    }
}
