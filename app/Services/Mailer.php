<?php

declare(strict_types=1);

namespace Relay\Services;

use Relay\Support\Logger;
use Relay\Support\Settings;

/**
 * Minimal dependency-free SMTP client. Only used for account verification
 * mails, so it supports the common cases: implicit TLS (465), STARTTLS (587)
 * and plain SMTP (25/2525), with optional AUTH LOGIN.
 */
final class Mailer
{
    private string $lastError = '';

    public function __construct(private Settings $settings)
    {
    }

    public function isConfigured(): bool
    {
        return $this->settings->bool('mail.enabled', false)
            && trim($this->settings->string('mail.host', '')) !== ''
            && trim($this->settings->string('mail.from_address', '')) !== '';
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    public function send(string $to, string $subject, string $html): bool
    {
        $this->lastError = '';
        $to = $this->sanitize($to);
        $subject = $this->sanitize($subject);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Invalid recipient address.';
            return false;
        }
        $host = trim($this->settings->string('mail.host', ''));
        $port = (int) $this->settings->get('mail.port', 587);
        $encryption = (string) $this->settings->get('mail.encryption', 'tls');
        $from = trim($this->settings->string('mail.from_address', ''));
        $fromName = trim($this->settings->string('mail.from_name', ''));
        $username = trim($this->settings->string('mail.username', ''));
        $password = (string) $this->settings->get('mail.password', '');
        if ($username !== '' && !str_contains($username, '@') && str_contains($from, '@')) {
            // 多数邮箱（含 Postfix/Dovecot、QQ、163）要求用户名是完整地址，
            // 只填前缀时按发件地址的域名补全，避免 535 认证失败。
            $username .= '@' . substr($from, strrpos($from, '@') + 1);
        }

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($transport . $host . ':' . max(1, $port), $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            $this->lastError = 'Connection failed: ' . $errstr;
            Logger::warning('SMTP connection failed', ['host' => $host, 'port' => $port, 'error' => $errstr]);
            return false;
        }
        stream_set_timeout($fp, 20);

        try {
            if (!$this->expect($fp, [220])) {
                return false;
            }
            $ehlo = $this->command($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
            if ($encryption === 'tls') {
                if (!$this->command($fp, 'STARTTLS', [220])) {
                    return false;
                }
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $this->lastError = 'TLS negotiation failed.';
                    return false;
                }
                $ehlo = $this->command($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
            }

            if ($username !== '') {
                $authPlain = stripos($ehlo, 'AUTH') !== false && preg_match('/AUTH[^\n]*\bPLAIN\b/i', $ehlo) === 1;
                if ($authPlain) {
                    $token = base64_encode("\0" . $username . "\0" . $password);
                    if (!$this->command($fp, 'AUTH PLAIN ' . $token, [235])) {
                        return false;
                    }
                } else {
                    if (!$this->command($fp, 'AUTH LOGIN', [334])) {
                        return false;
                    }
                    if (!$this->command($fp, base64_encode($username), [334])) {
                        return false;
                    }
                    if (!$this->command($fp, base64_encode($password), [235])) {
                        return false;
                    }
                }
            }

            if (!$this->command($fp, 'MAIL FROM:<' . $from . '>', [250])) {
                return false;
            }
            if (!$this->command($fp, 'RCPT TO:<' . $to . '>', [250, 251])) {
                return false;
            }
            if (!$this->command($fp, 'DATA', [354])) {
                return false;
            }
            $message = $this->buildMessage($from, $fromName, $to, $subject, $html);
            fwrite($fp, $message . "\r\n.\r\n");
            if (!$this->expect($fp, [250])) {
                return false;
            }
            $this->command($fp, 'QUIT', [221]);
            return true;
        } finally {
            @fclose($fp);
        }
    }

    private function buildMessage(string $from, string $fromName, string $to, string $subject, string $html): string
    {
        $fromHeader = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>'
            : '<' . $from . '>';
        return 'Date: ' . date('r')
            . "\r\nFrom: " . $fromHeader
            . "\r\nTo: <" . $to . '>'
            . "\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . '?='
            . "\r\nMIME-Version: 1.0"
            . "\r\nContent-Type: text/html; charset=UTF-8"
            . "\r\nContent-Transfer-Encoding: base64"
            . "\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n");
    }

    /** @param list<int> $codes */
    private function command($fp, string $line, array $codes): string
    {
        fwrite($fp, $line . "\r\n");
        return $this->expect($fp, $codes);
    }

    /** @param list<int> $codes */
    private function expect($fp, array $codes): string
    {
        $response = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if ($response === '' || !in_array($code, $codes, true)) {
            $this->lastError = trim($response) !== '' ? trim($response) : 'SMTP read timeout.';
            Logger::warning('SMTP unexpected response', ['response' => mb_substr($this->lastError, 0, 300)]);
            return '';
        }
        return $response;
    }

    private function sanitize(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}
