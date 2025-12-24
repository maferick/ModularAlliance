<?php
declare(strict_types=1);

namespace App\Core;

final class EveSso
{
    public function __construct(private readonly Db $db, private readonly array $cfg) {}

    public function beginLogin(): string
    {
        $meta = $this->getMetadata();

        $clientId = (string)$this->cfg['client_id'];
        $redirect = (string)$this->cfg['callback_url'];
        $scopes   = $this->cfg['scopes'] ?? ['publicData'];
        if (!is_array($scopes)) $scopes = ['publicData'];

        // PKCE
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->db->run(
            "INSERT INTO sso_login_state (state, code_verifier, created_at) VALUES (?, ?, NOW())",
            [$state, $verifier]
        );

        $this->audit('sso.begin', [
            'state' => $state,
            'redirect' => $redirect,
            'scopes' => $scopes,
        ]);

        $authUrl = $meta['authorization_endpoint'];
        $q = http_build_query([
            'response_type' => 'code',
            'redirect_uri' => $redirect,
            'client_id' => $clientId,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return $authUrl . '?' . $q;
    }

    public function handleCallback(string $code, string $state): array
    {
        $meta = $this->getMetadata();

        $row = $this->db->one("SELECT state, code_verifier FROM sso_login_state WHERE state=? LIMIT 1", [$state]);
        if (!$row) throw new \RuntimeException("Invalid/expired state");

        // one-time use
        $this->db->run("DELETE FROM sso_login_state WHERE state=?", [$state]);

        $clientId = (string)$this->cfg['client_id'];
        $secret   = (string)$this->cfg['client_secret'];
        $redirect = (string)$this->cfg['callback_url'];

        $basic = base64_encode($clientId . ':' . $secret);

        [$status, $body] = HttpClient::postForm(
            $meta['token_endpoint'],
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect,
                'code_verifier' => $row['code_verifier'],
            ],
            ['Authorization: Basic ' . $basic],
            15
        );

        $this->audit('sso.token_response', [
            'http_status' => $status,
            'body' => $body,
        ]);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Token exchange failed (HTTP {$status}): " . substr($body, 0, 300));
        }

        $token = json_decode($body, true);
        if (!is_array($token) || empty($token['access_token'])) {
            throw new \RuntimeException("Invalid token JSON");
        }

        $jwtPayload = $this->decodeJwtPayload((string)$token['access_token']);
        $characterId = $this->characterIdFromJwt($jwtPayload);
        $characterName = (string)($jwtPayload['name'] ?? 'Unknown');
        $owner = (string)($jwtPayload['owner'] ?? '');

        $existingUser = $this->db->one("SELECT id FROM eve_users WHERE character_id=? LIMIT 1", [$characterId]);
        $existingUserId = (int)($existingUser['id'] ?? 0);

        $linkFlash = null;
        $linkRedirect = null;
        $linkTargetUserId = null;
        $linkTokenRow = null;
        $linkIntent = false;
        $linkAllowed = true;

        $pendingLinkUserId = $_SESSION['charlink_link_user'] ?? null;
        if (is_numeric($pendingLinkUserId)) {
            unset($_SESSION['charlink_link_user']);
            $linkIntent = true;
            $linkTargetUserId = (int)$pendingLinkUserId;
        }

        $pendingToken = $_SESSION['charlink_token'] ?? null;
        if (is_string($pendingToken) && $pendingToken !== '') {
            unset($_SESSION['charlink_token']);
            $linkIntent = true;
            $tokenHash = hash('sha256', $pendingToken);
            $linkTokenRow = $this->db->one(
                "SELECT id, user_id, expires_at, used_at
                 FROM module_charlink_states
                 WHERE token_hash=? AND purpose='link' LIMIT 1",
                [$tokenHash]
            );

            if (!$linkTokenRow) {
                $linkAllowed = false;
                $linkFlash = ['type' => 'danger', 'message' => 'Invalid or expired character link token.'];
                $this->audit('charlink.token_invalid', ['character_id' => $characterId]);
            } else {
                $expires = $linkTokenRow['expires_at'] ? strtotime((string)$linkTokenRow['expires_at']) : null;
                $usedAt = $linkTokenRow['used_at'];
                if ($usedAt !== null) {
                    $linkAllowed = false;
                    $linkFlash = ['type' => 'warning', 'message' => 'This link token has already been used.'];
                    $this->audit('charlink.token_used', ['character_id' => $characterId, 'token_id' => (int)$linkTokenRow['id']]);
                } elseif ($expires !== null && time() > $expires) {
                    $linkAllowed = false;
                    $linkFlash = ['type' => 'warning', 'message' => 'This link token has expired.'];
                    $this->audit('charlink.token_expired', ['character_id' => $characterId, 'token_id' => (int)$linkTokenRow['id']]);
                } else {
                    $linkTargetUserId = (int)$linkTokenRow['user_id'];
                }
            }
        }

        $userId = 0;
        if (!$linkIntent || $existingUserId > 0) {
            $userId = $this->upsertUser($characterId, $characterName, $owner, $jwtPayload);
        }

        $finalUserId = $userId > 0 ? $userId : 0;

        if ($linkIntent && $linkAllowed && $linkTargetUserId !== null) {
            $targetUser = $this->db->one("SELECT id FROM eve_users WHERE id=? LIMIT 1", [$linkTargetUserId]);
            if (!$targetUser) {
                $linkFlash = ['type' => 'danger', 'message' => 'Unable to link character: target account not found.'];
                $linkAllowed = false;
                $this->audit('charlink.target_missing', ['character_id' => $characterId, 'target_user_id' => $linkTargetUserId]);
            }
        }

        if ($linkIntent && $linkAllowed && $linkTargetUserId !== null) {
            if ($existingUserId > 0 && $existingUserId !== $linkTargetUserId) {
                $linkFlash = ['type' => 'danger', 'message' => 'This character is already the main character for another account.'];
                $finalUserId = $existingUserId;
                $linkAllowed = false;
                $this->audit('charlink.main_conflict', [
                    'character_id' => $characterId,
                    'existing_user_id' => $existingUserId,
                    'target_user_id' => $linkTargetUserId,
                ]);
            }
        }

        if ($linkIntent && $linkAllowed && $linkTargetUserId !== null) {
            $existingLink = $this->db->one(
                "SELECT user_id, status
                 FROM character_links
                 WHERE character_id=? LIMIT 1",
                [$characterId]
            );

            if ($existingLink && (string)($existingLink['status'] ?? '') === 'linked') {
                $linkedUserId = (int)$existingLink['user_id'];
                if ($linkedUserId !== $linkTargetUserId) {
                    $linkFlash = ['type' => 'danger', 'message' => 'This character is already linked to another account.'];
                    $this->audit('charlink.link_conflict', [
                        'character_id' => $characterId,
                        'linked_user_id' => $linkedUserId,
                        'target_user_id' => $linkTargetUserId,
                    ]);
                } else {
                    $linkFlash = ['type' => 'info', 'message' => 'This character is already linked to your account.'];
                    $finalUserId = $linkTargetUserId;
                }
            } else {
                $this->db->run(
                    "INSERT INTO character_links (user_id, character_id, character_name, status, linked_at, linked_by_user_id)
                     VALUES (?, ?, ?, 'linked', NOW(), ?)
                     ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), character_name=VALUES(character_name), status='linked', linked_at=NOW(), linked_by_user_id=VALUES(linked_by_user_id), revoked_at=NULL, revoked_by_user_id=NULL",
                    [$linkTargetUserId, $characterId, $characterName, $linkTargetUserId]
                );
                if ($linkTokenRow) {
                    $this->db->run(
                        "UPDATE module_charlink_states
                         SET used_at=NOW(), used_character_id=?
                         WHERE id=?",
                        [$characterId, (int)$linkTokenRow['id']]
                    );
                }
                $finalUserId = $linkTargetUserId;
                $linkFlash = ['type' => 'success', 'message' => 'Character linked successfully.'];
            }

            $linkRedirect = '/user/alts';
        } elseif ($linkIntent && !$linkAllowed && $finalUserId <= 0) {
            throw new \RuntimeException('Linking failed; no existing account found for this character.');
        }

        if ($finalUserId === $userId) {
            $linked = $this->db->one(
                "SELECT user_id
                 FROM character_links
                 WHERE character_id=? AND status='linked' LIMIT 1",
                [$characterId]
            );
            if ($linked) {
                $finalUserId = (int)$linked['user_id'];
            }
        }

        $this->upsertToken($finalUserId, $characterId, $token, $jwtPayload);
        $this->warmIdentity($characterId, (string)$token['access_token']);

        $pendingTargets = $_SESSION['charlink_pending_targets'] ?? null;
        if (is_array($pendingTargets)) {
            unset($_SESSION['charlink_pending_targets']);
            $pendingTargets = array_values(array_unique(array_filter($pendingTargets, 'is_string')));
            if (!empty($pendingTargets)) {
                $existing = $this->db->one(
                    "SELECT enabled_targets_json
                     FROM module_charlink_links
                     WHERE user_id=? AND character_id=? LIMIT 1",
                    [$finalUserId, $characterId]
                );
                $existingTargets = [];
                if ($existing) {
                    $existingTargets = json_decode((string)($existing['enabled_targets_json'] ?? '[]'), true);
                    if (!is_array($existingTargets)) $existingTargets = [];
                }
                $merged = array_values(array_unique(array_merge($existingTargets, $pendingTargets)));
                $this->db->run(
                    "INSERT INTO module_charlink_links (user_id, character_id, enabled_targets_json, created_at, updated_at)
                     VALUES (?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE enabled_targets_json=VALUES(enabled_targets_json), updated_at=NOW()",
                    [$finalUserId, $characterId, json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
                );
            }
        }

        if (isset($_SESSION['sso_scopes_override'])) {
            unset($_SESSION['sso_scopes_override']);
        }

        $_SESSION['user_id'] = $finalUserId;
        $_SESSION['character_id'] = $characterId;
        if ($linkFlash) {
            $_SESSION['charlink_flash'] = $linkFlash;
        }
        if ($linkRedirect) {
            $_SESSION['charlink_redirect'] = $linkRedirect;
        }

        return [
            'user_id' => $finalUserId,
            'character_id' => $characterId,
            'character_name' => $characterName,
            'jwt' => $jwtPayload,
            'token' => $token,
        ];
    }

    public function refreshTokenForCharacter(int $userId, int $characterId, string $refreshToken): array
    {
        if ($refreshToken === '') {
            return ['status' => 'failed', 'message' => 'Missing refresh token.'];
        }

        $meta = $this->getMetadata();
        $clientId = (string)$this->cfg['client_id'];
        $secret   = (string)$this->cfg['client_secret'];
        $basic = base64_encode($clientId . ':' . $secret);

        [$status, $body] = HttpClient::postForm(
            $meta['token_endpoint'],
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
            ['Authorization: Basic ' . $basic],
            15
        );

        $this->audit('sso.refresh_token_response', [
            'http_status' => $status,
            'body' => $body,
            'character_id' => $characterId,
        ]);

        if ($status < 200 || $status >= 300) {
            return ['status' => 'failed', 'message' => "Refresh failed (HTTP {$status}): " . substr($body, 0, 300)];
        }

        $token = json_decode($body, true);
        if (!is_array($token) || empty($token['access_token'])) {
            return ['status' => 'failed', 'message' => 'Invalid token JSON.'];
        }

        if (empty($token['refresh_token'])) {
            $token['refresh_token'] = $refreshToken;
        }

        $jwtPayload = $this->decodeJwtPayload((string)$token['access_token']);
        $this->upsertToken($userId, $characterId, $token, $jwtPayload);

        $expiresAt = null;
        if (!empty($jwtPayload['exp']) && is_numeric($jwtPayload['exp'])) {
            $expiresAt = gmdate('Y-m-d H:i:s', (int)$jwtPayload['exp']);
        }
        $scopes = $jwtPayload['scp'] ?? [];
        if (!is_array($scopes)) {
            $scopes = [];
        }

        return [
            'status' => 'success',
            'token' => $token,
            'jwt' => $jwtPayload,
            'expires_at' => $expiresAt,
            'scopes' => $scopes,
        ];
    }

    private function getMetadata(): array
    {
        $url = (string)$this->cfg['metadata_url'];

        $row = $this->db->one("SELECT payload_json, fetched_at, ttl_seconds FROM oauth_provider_cache WHERE provider='eve' LIMIT 1");
        if ($row) {
            $fetched = strtotime((string)$row['fetched_at']) ?: 0;
            $ttl = (int)$row['ttl_seconds'];
            if (time() < ($fetched + $ttl)) {
                $data = json_decode((string)$row['payload_json'], true);
                if (is_array($data)) return $data;
            }
        }

        $data = HttpClient::getJson($url, 10);

        foreach (['authorization_endpoint','token_endpoint','jwks_uri','issuer'] as $k) {
            if (empty($data[$k])) throw new \RuntimeException("SSO metadata missing {$k}");
        }

        $this->db->run(
            "REPLACE INTO oauth_provider_cache (provider, payload_json, fetched_at, ttl_seconds)
             VALUES ('eve', ?, NOW(), 86400)",
            [json_encode($data, JSON_UNESCAPED_SLASHES)]
        );

        $this->audit('sso.metadata', $data);
        return $data;
    }

    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return [];

        $payload = $parts[1];
        $payload .= str_repeat('=', (4 - (strlen($payload) % 4)) % 4);
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) return [];

        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function characterIdFromJwt(array $jwt): int
    {
        // EVE v2 JWT uses sub like "CHARACTER:EVE:<id>"
        $sub = (string)($jwt['sub'] ?? '');
        if (preg_match('~(\d+)$~', $sub, $m)) return (int)$m[1];

        // fallback (some libs store character_id)
        $cid = $jwt['character_id'] ?? null;
        return is_numeric($cid) ? (int)$cid : 0;
    }

    private function upsertUser(int $characterId, string $name, string $owner, array $jwtPayload): int
    {
        $existing = $this->db->one("SELECT id FROM eve_users WHERE character_id=? LIMIT 1", [$characterId]);

        if ($existing) {
            $this->db->run(
                "UPDATE eve_users SET character_name=?, owner_hash=?, jwt_payload_json=? WHERE id=?",
                [$name, $owner ?: null, json_encode($jwtPayload), (int)$existing['id']]
            );
            return (int)$existing['id'];
        }

        $this->db->run(
            "INSERT INTO eve_users (character_id, character_name, owner_hash, jwt_payload_json)
             VALUES (?, ?, ?, ?)",
            [$characterId, $name, $owner ?: null, json_encode($jwtPayload)]
        );

        $row = $this->db->one("SELECT id FROM eve_users WHERE character_id=? LIMIT 1", [$characterId]);
        return (int)($row['id'] ?? 0);
    }

    private function upsertToken(int $userId, int $characterId, array $token, array $jwtPayload): void
    {
        $expiresAt = null;
        if (!empty($jwtPayload['exp']) && is_numeric($jwtPayload['exp'])) {
            $expiresAt = gmdate('Y-m-d H:i:s', (int)$jwtPayload['exp']);
        }

        $scopes = $jwtPayload['scp'] ?? [];
        if (!is_array($scopes)) {
            $scopes = [];
        }

        $this->db->run(
            "REPLACE INTO eve_tokens (user_id, character_id, access_token, refresh_token, expires_at, scopes_json, token_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $userId,
                $characterId,
                (string)$token['access_token'],
                (string)($token['refresh_token'] ?? ''),
                $expiresAt,
                json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($token),
            ]
        );
    }

    private function warmIdentity(int $characterId, string $accessToken): void
    {
        $http = new HttpClient();
        $esi  = new EsiClient($http);
        $cache = new EsiCache($this->db, $esi);

        // Character (public) – corp_id
        $char = $cache->getCached(
            "char:{$characterId}",
            "GET /latest/characters/{$characterId}/",
            3600,
            fn() => $esi->get("/latest/characters/{$characterId}/")
        );

        $corpId = (int)($char['corporation_id'] ?? 0);

        // Character portrait
        $cache->getCached(
            "char:{$characterId}",
            "GET /latest/characters/{$characterId}/portrait/",
            86400,
            fn() => $esi->get("/latest/characters/{$characterId}/portrait/")
        );

        if ($corpId > 0) {
            // Corporation (public) – alliance_id (optional)
            $corp = $cache->getCached(
                "corp:{$corpId}",
                "GET /latest/corporations/{$corpId}/",
                3600,
                fn() => $esi->get("/latest/corporations/{$corpId}/")
            );

            $cache->getCached(
                "corp:{$corpId}",
                "GET /latest/corporations/{$corpId}/icons/",
                86400,
                fn() => $esi->get("/latest/corporations/{$corpId}/icons/")
            );

            $allianceId = (int)($corp['alliance_id'] ?? 0);

            if ($allianceId > 0) {
                $cache->getCached(
                    "alliance:{$allianceId}",
                    "GET /latest/alliances/{$allianceId}/",
                    3600,
                    fn() => $esi->get("/latest/alliances/{$allianceId}/")
                );

                $cache->getCached(
                    "alliance:{$allianceId}",
                    "GET /latest/alliances/{$allianceId}/icons/",
                    86400,
                    fn() => $esi->get("/latest/alliances/{$allianceId}/icons/")
                );
            }
        }

        $this->audit('esi.warm_identity', [
            'character_id' => $characterId,
            'corp_id' => $corpId,
        ]);
    }


    private function audit(string $event, array $payload): void
    {
        $this->db->run(
            "INSERT INTO sso_audit (event, payload_json) VALUES (?, ?)",
            [$event, json_encode($payload, JSON_UNESCAPED_SLASHES)]
        );
    }
}
