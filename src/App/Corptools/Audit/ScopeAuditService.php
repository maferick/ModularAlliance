<?php
declare(strict_types=1);

namespace App\Corptools\Audit;

use App\Core\Db;

final class ScopeAuditService
{
    public function __construct(private Db $db) {}

    public function evaluate(
        int $userId,
        int $characterId,
        array $token,
        array $requiredScopes,
        array $optionalScopes,
        ?int $policyId
    ): array {
        $now = date('Y-m-d H:i:s');
        $grantedScopes = $this->normalizeScopes($token['scopes'] ?? []);
        $requiredScopes = $this->normalizeScopes($requiredScopes);
        $optionalScopes = $this->normalizeScopes($optionalScopes);
        $missing = array_values(array_diff($requiredScopes, $grantedScopes));

        $status = 'COMPLIANT';
        $reason = '';
        if (empty($token['access_token'])) {
            $status = 'TOKEN_INVALID';
            $reason = 'No token on file.';
        } elseif (!empty($token['expired'])) {
            $status = 'TOKEN_EXPIRED';
            $reason = 'Token expired.';
        } elseif (!empty($missing)) {
            $status = 'MISSING_SCOPES';
            $reason = 'Missing required scopes.';
        }

        $this->db->run(
            "INSERT INTO module_corptools_character_scope_status
             (character_id, user_id, policy_id, status, reason, required_scopes_json, optional_scopes_json, granted_scopes_json, missing_scopes_json, token_expires_at, checked_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               user_id=VALUES(user_id),
               policy_id=VALUES(policy_id),
               status=VALUES(status),
               reason=VALUES(reason),
               required_scopes_json=VALUES(required_scopes_json),
               optional_scopes_json=VALUES(optional_scopes_json),
               granted_scopes_json=VALUES(granted_scopes_json),
               missing_scopes_json=VALUES(missing_scopes_json),
               token_expires_at=VALUES(token_expires_at),
               checked_at=VALUES(checked_at),
               updated_at=VALUES(updated_at)",
            [
                $characterId,
                $userId,
                $policyId,
                $status,
                $reason,
                json_encode($requiredScopes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($optionalScopes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($grantedScopes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($missing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $token['expires_at'] ?? null,
                $now,
                $now,
            ]
        );

        $this->logEvent('scope_check', $userId, $characterId, [
            'status' => $status,
            'missing_scopes' => $missing,
            'required_scopes' => $requiredScopes,
        ]);

        return [
            'status' => $status,
            'reason' => $reason,
            'missing_scopes' => $missing,
        ];
    }

    public function logEvent(string $event, ?int $userId, ?int $characterId, array $payload): void
    {
        $this->db->run(
            "INSERT INTO module_corptools_audit_events (event, user_id, character_id, payload_json)
             VALUES (?, ?, ?, ?)",
            [
                $event,
                $userId ?: null,
                $characterId ?: null,
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    private function normalizeScopes(array $scopes): array
    {
        $scopes = array_values(array_unique(array_filter($scopes, 'is_string')));
        sort($scopes);
        return $scopes;
    }
}
