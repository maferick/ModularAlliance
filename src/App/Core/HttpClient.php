<?php
declare(strict_types=1);

namespace App\Core;

final class HttpClient
{
    public static function getJson(string $url, int $timeout = 10): array
    {
        [$status, $body] = self::request('GET', $url, [], null, $timeout);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("HTTP {$status} from {$url}: " . substr($body, 0, 300));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) throw new \RuntimeException("Invalid JSON from {$url}");
        return $data;
    }

    public static function postForm(string $url, array $form, array $headers = [], int $timeout = 10): array
    {
        $body = http_build_query($form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        [$status, $resp] = self::request('POST', $url, $headers, $body, $timeout);
        return [$status, $resp];
    }

    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 10): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode JSON payload.');
        }
        $headers[] = 'Content-Type: application/json';
        [$status, $resp] = self::request('POST', $url, $headers, $body, $timeout);
        return [$status, $resp];
    }

    private static function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error: {$err}");
        }
        curl_close($ch);
        return [$status, (string)$resp];
    }
}
