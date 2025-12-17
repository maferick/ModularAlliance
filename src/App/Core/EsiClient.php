<?php
declare(strict_types=1);

namespace App\Core;

final class EsiClient
{
    public function __construct(private readonly HttpClient $http, private readonly string $baseUrl = 'https://esi.evetech.net') {}

    public function get(string $path, ?string $accessToken = null, array $query = []): array
    {
        [$status, $data] = $this->getWithStatus($path, $accessToken, $query);

        if ($status < 200 || $status >= 300) {
            $preview = is_string($data) ? substr($data, 0, 300) : json_encode($data);
            throw new \RuntimeException("ESI HTTP {$status}: " . (string)$preview);
        }
        if (!is_array($data)) {
            throw new \RuntimeException("ESI invalid JSON for {$path}");
        }
        return $data;
    }

    /** @return array{0:int,1:array|string|null} */
    public function getWithStatus(string $path, ?string $accessToken = null, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

        $headers = ['Accept: application/json'];
        if ($accessToken) $headers[] = 'Authorization: Bearer ' . $accessToken;

        return $this->httpGetJsonWithStatus($url, $headers);
    }

    /** @return array{0:int,1:array|string|null} */
    private function httpGetJsonWithStatus(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("ESI cURL error: {$err}");
        }
        curl_close($ch);

        $decoded = json_decode((string)$resp, true);
        if (is_array($decoded)) return [$status, $decoded];
        return [$status, (string)$resp];
    }
}
