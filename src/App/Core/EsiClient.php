<?php
declare(strict_types=1);

namespace App\Core;

final class EsiClient
{
    public function __construct(private readonly HttpClient $http, private readonly string $baseUrl = 'https://esi.evetech.net') {}

    public function get(string $path, ?string $accessToken = null, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

        $headers = [];
        if ($accessToken) {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }
        $headers[] = 'Accept: application/json';

        return $this->httpGetJson($url, $headers);
    }

    private function httpGetJson(string $url, array $headers): array
    {
        // reuse your HttpClient style, but allow headers
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("ESI cURL error: {$err}");
        }
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("ESI HTTP {$status}: " . substr((string)$resp, 0, 300));
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new \RuntimeException("ESI invalid JSON from {$url}");
        }
        return $data;
    }
}

