<?php
declare(strict_types=1);

/*
Module Name: Authentication
Description: EVE SSO authentication and profile routes.
Version: 1.0.0
*/

use App\Core\EveSso;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Universe;
use App\Corptools\Settings as CorpToolsSettings;
use App\Corptools\ScopePolicy;
use App\Corptools\Audit\ScopeAuditService;
use App\Http\Request;
use App\Http\Response;

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->menu([
        'slug' => 'user.linking',
        'title' => 'Scopes & Linking',
        'url' => '/me/linking',
        'sort_order' => 35,
        'area' => 'user_top',
    ]);

    $getScopeProfiles = function (int $userId) use ($app): array {
        $scopePolicy = new ScopePolicy($app->db, new Universe($app->db));
        $scopeSet = $userId > 0 ? $scopePolicy->getEffectiveScopesForUser($userId) : ['required' => [], 'optional' => []];
        $memberScopes = array_values(array_unique(array_merge(
            $scopeSet['required'] ?? [],
            $scopeSet['optional'] ?? []
        )));

        $basicScopes = $app->config['eve_sso']['basic_scopes'] ?? ['publicData'];
        if (!is_array($basicScopes) || empty($basicScopes)) {
            $basicScopes = ['publicData'];
        }

        $corpSettings = new CorpToolsSettings($app->db);
        $settings = $corpSettings->get();
        $corpAuditEnabled = $settings['corp_audit'] ?? [];
        $corpAuditScopes = [
            'wallets' => ['esi-wallet.read_corporation_wallets.v1'],
            'structures' => ['esi-corporations.read_structures.v1'],
            'assets' => ['esi-assets.read_corporation_assets.v1'],
            'sov' => ['esi-sovereignty.read_corporation_campaigns.v1'],
            'jump_bridges' => ['esi-universe.read_structures.v1'],
        ];
        $orgScopes = [];
        foreach ($corpAuditScopes as $key => $scopes) {
            if (!empty($corpAuditEnabled[$key])) {
                $orgScopes = array_merge($orgScopes, $scopes);
            }
        }
        $orgScopes = array_values(array_unique($orgScopes));

        return [
            'basic' => [
                'label' => 'Character Profile (Basic)',
                'description' => 'Basic profile and member widgets.',
                'bucket' => 'basic',
                'scopes' => $basicScopes,
            ],
            'member_audit' => [
                'label' => 'Member Audit (Full for Main + Alts)',
                'description' => 'Full personal audit for your main and linked characters.',
                'bucket' => 'member_audit',
                'scopes' => $memberScopes,
            ],
            'org_audit' => [
                'label' => 'Org Audit (Corp/Alliance Staff)',
                'description' => 'Director-level corp dashboards and org audit.',
                'bucket' => 'org_audit',
                'scopes' => $orgScopes,
            ],
        ];
    };

    $registry->route('GET', '/auth/login', function () use ($app): Response {
        $cfg = $app->config['eve_sso'] ?? [];
        $override = $_SESSION['sso_scopes_override'] ?? null;
        if (is_array($override) && !empty($override)) {
            $cfg['scopes'] = array_values(array_unique(array_filter($override, 'is_string')));
        } else {
            $scopePolicy = new ScopePolicy($app->db, new Universe($app->db));
            $defaultPolicy = $scopePolicy->getDefaultPolicy();
            if ($defaultPolicy && !empty($defaultPolicy['required_scopes'])) {
                $cfg['scopes'] = $defaultPolicy['required_scopes'];
            }
        }
        $sso = new EveSso($app->db, $cfg);

        $url = $sso->beginLogin();
        return Response::redirect($url);
    }, ['public' => true]);

    $registry->route('GET', '/auth/callback', function (Request $req) use ($app): Response {
        $code  = $req->query['code']  ?? null;
        $state = $req->query['state'] ?? null;

        if (!is_string($code) || $code === '' || !is_string($state) || $state === '') {
            return Response::text("Missing code/state\n", 400);
        }

        try {
            $cfg = $app->config['eve_sso'] ?? [];
            $sso = new EveSso($app->db, $cfg);
            $result = $sso->handleCallback($code, $state);

            $userId = (int)($result['user_id'] ?? 0);
            $characterId = (int)($result['character_id'] ?? 0);
            $bucket = (string)($result['bucket'] ?? 'basic');
            if ($userId > 0 && $characterId > 0 && $bucket === 'member_audit') {
                $scopePolicy = new ScopePolicy($app->db, new Universe($app->db));
                $scopeSet = $scopePolicy->getEffectiveScopesForUser($userId);
                $tokenRow = $app->db->one(
                    "SELECT access_token, scopes_json, expires_at
                     FROM eve_token_buckets
                     WHERE character_id=? AND bucket='member_audit' AND org_type='character' AND org_id=0
                     LIMIT 1",
                    [$characterId]
                );
                $tokenScopes = json_decode((string)($tokenRow['scopes_json'] ?? '[]'), true);
                if (!is_array($tokenScopes)) $tokenScopes = [];
                $token = [
                    'access_token' => (string)($tokenRow['access_token'] ?? ''),
                    'scopes' => $tokenScopes,
                    'expires_at' => $tokenRow['expires_at'] ?? null,
                    'expired' => false,
                ];
                if (!empty($token['expires_at'])) {
                    $expiresAt = strtotime((string)$token['expires_at']);
                    if ($expiresAt !== false && time() > $expiresAt) {
                        $token['expired'] = true;
                    }
                }
                $scopeAudit = new ScopeAuditService($app->db);
                $scopeAudit->evaluate(
                    $userId,
                    $characterId,
                    $token,
                    $scopeSet['required'] ?? [],
                    $scopeSet['optional'] ?? [],
                    $scopeSet['policy']['id'] ?? null
                );
            }

            $redirect = $_SESSION['charlink_redirect'] ?? null;
            if (is_string($redirect) && $redirect !== '') {
                unset($_SESSION['charlink_redirect']);
                return Response::redirect($redirect);
            }

            // ✅ Directly land on dashboard
            return Response::redirect('/');
        } catch (\Throwable $e) {
            return Response::text("SSO failed: " . $e->getMessage() . "\n", 500);
        }
    }, ['public' => true]);

    $registry->route('GET', '/auth/logout', function (): Response {
        session_destroy();
        return Response::redirect('/');
    }, ['public' => true]);

    $registry->route('GET', '/auth/authorize', function (Request $req) use ($app, $getScopeProfiles): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return Response::redirect('/auth/login');
        }

        $profileKey = (string)($req->query['profile'] ?? '');
        $profiles = $getScopeProfiles($uid);
        $profile = $profiles[$profileKey] ?? null;
        if (!$profile) {
            return Response::redirect('/me/linking');
        }

        $bucket = (string)($profile['bucket'] ?? 'basic');
        $scopes = $profile['scopes'] ?? ['publicData'];
        if (!is_array($scopes) || empty($scopes)) {
            $scopes = ['publicData'];
        }

        $orgType = '';
        $orgId = 0;
        if ($bucket === 'member_audit') {
            $orgType = 'character';
        }

        if ($bucket === 'org_audit') {
            $rights = new Rights($app->db);
            if (!$rights->userHasRight($uid, 'corptools.director')) {
                return Response::redirect('/me/linking');
            }

            $orgId = (int)($req->query['org_id'] ?? 0);
            $orgType = 'corporation';

            $corpSettings = new CorpToolsSettings($app->db);
            $settings = $corpSettings->get();
            $allowedIds = array_values(array_filter(array_map('intval', $settings['general']['corp_ids'] ?? [])));
            if ($orgId <= 0 || (!empty($allowedIds) && !in_array($orgId, $allowedIds, true))) {
                return Response::redirect('/admin/corptools/linking');
            }
        }

        $_SESSION['sso_scopes_override'] = array_values(array_unique(array_filter($scopes, 'is_string')));
        $_SESSION['sso_token_bucket'] = $bucket;
        $_SESSION['sso_org_context'] = [
            'org_type' => $orgType,
            'org_id' => $orgId,
        ];
        $_SESSION['charlink_redirect'] = $bucket === 'org_audit' ? '/admin/corptools/linking' : '/me/linking';

        return Response::redirect('/auth/login');
    });

    $registry->route('GET', '/me/linking', function () use ($app, $getScopeProfiles): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $rights = new Rights($app->db);
        $hasRight = function (string $right) use ($rights, $uid): bool {
            if ($uid <= 0) return false;
            return $rights->userHasRight($uid, $right);
        };

        $leftTree  = $app->menu->tree('left', $hasRight);
        $adminTree = $app->menu->tree('admin_top', $hasRight);
        $userTree  = $app->menu->tree('user_top', fn(string $r) => true);
        $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));

        $profiles = $getScopeProfiles($uid);
        $memberScopes = $profiles['member_audit']['scopes'] ?? [];
        $basicScopes = $profiles['basic']['scopes'] ?? [];

        $main = $app->db->one(
            "SELECT character_id, character_name FROM eve_users WHERE id=? LIMIT 1",
            [$uid]
        );
        $mainCharacterId = (int)($main['character_id'] ?? 0);
        $characters = [];
        if ($mainCharacterId > 0) {
            $characters[] = [
                'character_id' => $mainCharacterId,
                'character_name' => (string)($main['character_name'] ?? 'Unknown'),
            ];
        }
        $links = $app->db->all(
            "SELECT character_id, character_name
             FROM character_links
             WHERE user_id=? AND status='linked'
             ORDER BY linked_at ASC",
            [$uid]
        );
        foreach ($links as $link) {
            $linkId = (int)($link['character_id'] ?? 0);
            if ($linkId > 0 && $linkId !== $mainCharacterId) {
                $characters[] = [
                    'character_id' => $linkId,
                    'character_name' => (string)($link['character_name'] ?? 'Unknown'),
                ];
            }
        }

        $characterIds = array_values(array_unique(array_filter(array_map(
            fn($row) => (int)($row['character_id'] ?? 0),
            $characters
        ))));

        $basicTokens = [];
        $memberTokens = [];
        if (!empty($characterIds)) {
            $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
            $basicTokens = $app->db->all(
                "SELECT character_id, scopes_json, expires_at, status, last_refresh_at, error_last
                 FROM eve_tokens
                 WHERE character_id IN ({$placeholders})",
                $characterIds
            );
            $memberTokens = $app->db->all(
                "SELECT character_id, scopes_json, expires_at, status, last_refresh_at, error_last
                 FROM eve_token_buckets
                 WHERE character_id IN ({$placeholders})
                   AND bucket='member_audit'
                   AND org_type='character'
                   AND org_id=0",
                $characterIds
            );
        }

        $indexTokens = function (array $rows): array {
            $map = [];
            foreach ($rows as $row) {
                $cid = (int)($row['character_id'] ?? 0);
                if ($cid <= 0) continue;
                $scopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
                if (!is_array($scopes)) $scopes = [];
                $expiresAt = $row['expires_at'] ? strtotime((string)$row['expires_at']) : null;
                $status = (string)($row['status'] ?? 'ACTIVE');
                $map[$cid] = [
                    'scopes' => $scopes,
                    'expires_at' => $row['expires_at'] ?? null,
                    'expires_at_ts' => $expiresAt,
                    'expired' => $expiresAt !== null && $expiresAt !== false && time() > $expiresAt,
                    'status' => $status,
                    'last_refresh_at' => $row['last_refresh_at'] ?? null,
                    'error_last' => $row['error_last'] ?? null,
                ];
            }
            return $map;
        };

        $basicMap = $indexTokens($basicTokens);
        $memberMap = $indexTokens($memberTokens);

        $computeStatus = function (array $tokenMap, array $requiredScopes, array $characterIds): array {
            $missingTokens = 0;
            $expiredTokens = 0;
            $expiringSoon = 0;
            $missingScopes = 0;
            $needsReauth = 0;
            $refreshFailed = 0;
            $now = time();
            $refreshWindow = 1800;

            foreach ($characterIds as $characterId) {
                $token = $tokenMap[$characterId] ?? null;
                if (!$token) {
                    $missingTokens++;
                    continue;
                }

                $status = (string)($token['status'] ?? 'ACTIVE');
                if (in_array($status, ['NEEDS_REAUTH', 'REVOKED'], true)) {
                    $needsReauth++;
                } elseif ($status === 'ERROR') {
                    $refreshFailed++;
                }

                if (!empty($token['expired'])) {
                    $expiredTokens++;
                } elseif (!empty($token['expires_at_ts']) && ($token['expires_at_ts'] - $now) <= $refreshWindow) {
                    $expiringSoon++;
                }

                if (!empty($requiredScopes)) {
                    $diff = array_diff($requiredScopes, $token['scopes'] ?? []);
                    if (!empty($diff)) {
                        $missingScopes++;
                    }
                }
            }

            $status = 'Not Linked';
            if ($missingTokens === 0 && $needsReauth === 0 && $refreshFailed === 0 && $expiredTokens === 0 && $expiringSoon === 0 && $missingScopes === 0) {
                $status = 'OK';
            } elseif ($needsReauth > 0 || $refreshFailed > 0) {
                $status = 'Re-Authorize';
            } elseif ($expiredTokens > 0 || $expiringSoon > 0) {
                $status = 'Expiring Soon';
            } elseif ($missingScopes > 0) {
                $status = 'Missing Scopes';
            }

            return [
                'status' => $status,
                'missing_tokens' => $missingTokens,
                'expired_tokens' => $expiredTokens,
                'expiring_soon' => $expiringSoon,
                'missing_scopes' => $missingScopes,
                'needs_reauth' => $needsReauth,
                'refresh_failed' => $refreshFailed,
            ];
        };

        $basicStatus = $computeStatus($basicMap, $basicScopes, $characterIds);
        $memberStatus = $computeStatus($memberMap, $memberScopes, $characterIds);

        $statusBadge = function (string $status): string {
            return match ($status) {
                'OK' => "<span class='badge bg-success'>OK</span>",
                'Missing Scopes' => "<span class='badge bg-warning text-dark'>Missing Scopes</span>",
                'Expiring Soon' => "<span class='badge bg-warning text-dark'>Expiring Soon</span>",
                'Re-Authorize' => "<span class='badge bg-danger'>Re-Authorize</span>",
                default => "<span class='badge bg-danger'>Not Linked</span>",
            };
        };

        $summaryLine = function (array $status, int $total): string {
            $parts = [];
            if ($status['missing_tokens'] > 0) {
                $parts[] = $status['missing_tokens'] . " missing token(s)";
            }
            if ($status['needs_reauth'] > 0) {
                $parts[] = $status['needs_reauth'] . " need re-authorize";
            }
            if ($status['refresh_failed'] > 0) {
                $parts[] = $status['refresh_failed'] . " refresh failed";
            }
            if ($status['expired_tokens'] > 0) {
                $parts[] = $status['expired_tokens'] . " expired";
            }
            if ($status['expiring_soon'] > 0) {
                $parts[] = $status['expiring_soon'] . " expiring soon";
            }
            if ($status['missing_scopes'] > 0) {
                $parts[] = $status['missing_scopes'] . " missing scopes";
            }
            if (empty($parts)) {
                return "{$total} characters covered";
            }
            return implode(' · ', $parts);
        };

        $renderCharacterStatuses = function (array $tokenMap, array $characters, array $requiredScopes, string $profileKey): string {
            $rows = '';
            $now = time();
            $refreshWindow = 1800;

            foreach ($characters as $character) {
                $characterId = (int)($character['character_id'] ?? 0);
                if ($characterId <= 0) continue;
                $name = htmlspecialchars((string)($character['character_name'] ?? 'Unknown'));
                $token = $tokenMap[$characterId] ?? null;
                $statusText = 'Not linked';
                $badge = 'bg-danger';
                $detail = 'Authorize this character to link the token.';
                $action = "<a class='btn btn-sm btn-primary' href='/auth/authorize?profile=" . htmlspecialchars($profileKey) . "'>Authorize</a>";

                if ($token) {
                    $status = (string)($token['status'] ?? 'ACTIVE');
                    $expiresAtTs = $token['expires_at_ts'] ?? null;
                    $missingScopes = !empty($requiredScopes)
                        ? array_diff($requiredScopes, $token['scopes'] ?? [])
                        : [];

                    if (in_array($status, ['NEEDS_REAUTH', 'REVOKED'], true)) {
                        $statusText = 'Re-authorize required';
                        $detail = 'Refresh token is invalid or revoked.';
                        $action = "<a class='btn btn-sm btn-danger' href='/auth/authorize?profile=" . htmlspecialchars($profileKey) . "'>Re-authorize</a>";
                    } elseif ($status === 'ERROR') {
                        $statusText = 'Refresh failed';
                        $detail = htmlspecialchars((string)($token['error_last'] ?? 'Refresh failed.'));
                        $action = "<a class='btn btn-sm btn-danger' href='/auth/authorize?profile=" . htmlspecialchars($profileKey) . "'>Re-authorize</a>";
                    } elseif (!empty($missingScopes)) {
                        $statusText = 'Missing scopes';
                        $badge = 'bg-warning text-dark';
                        $detail = 'Missing: ' . htmlspecialchars(implode(', ', $missingScopes));
                        $action = "<a class='btn btn-sm btn-primary' href='/auth/authorize?profile=" . htmlspecialchars($profileKey) . "'>Authorize</a>";
                    } elseif ($expiresAtTs !== null && $expiresAtTs <= $now) {
                        $statusText = 'Expired (refresh queued)';
                        $badge = 'bg-warning text-dark';
                        $detail = 'Token expired and will refresh on next use.';
                        $action = '';
                    } elseif ($expiresAtTs !== null && ($expiresAtTs - $now) <= $refreshWindow) {
                        $statusText = 'Expiring soon';
                        $badge = 'bg-warning text-dark';
                        $minutes = max(0, (int)ceil(($expiresAtTs - $now) / 60));
                        $detail = "Refresh queued (expires in ~{$minutes} min).";
                        $action = '';
                    } else {
                        $statusText = 'Token OK';
                        $badge = 'bg-success';
                        if ($expiresAtTs !== null) {
                            $minutes = max(0, (int)ceil(($expiresAtTs - $now) / 60));
                            $detail = "Next refresh in ~{$minutes} min.";
                        } else {
                            $detail = 'Refresh scheduled on use.';
                        }
                        $action = '';
                    }
                }

                $rows .= "<tr>
                    <td>{$name}</td>
                    <td><span class='badge {$badge}'>{$statusText}</span></td>
                    <td class='text-muted small'>{$detail}</td>
                    <td class='text-end'>{$action}</td>
                  </tr>";
            }

            if ($rows === '') {
                $rows = "<tr><td colspan='4' class='text-muted'>No characters linked.</td></tr>";
            }

            return "<div class='mt-3'>
                <div class='fw-semibold mb-2'>Per character status</div>
                <div class='table-responsive'>
                  <table class='table table-sm'>
                    <thead>
                      <tr>
                        <th>Character</th>
                        <th>Status</th>
                        <th>Details</th>
                        <th class='text-end'>Action</th>
                      </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                  </table>
                </div>
              </div>";
        };

        $renderProfile = function (array $profile, array $status, string $actionUrl, array $scopes, int $totalCharacters, array $tokenMap, array $characters, array $requiredScopes, string $profileKey) use ($statusBadge, $summaryLine, $renderCharacterStatuses): string {
            $badge = $statusBadge($status['status'] ?? 'Not Linked');
            $summary = htmlspecialchars($summaryLine($status, $totalCharacters));
            $scopeList = '';
            foreach ($scopes as $scope) {
                $scopeList .= '<li><code>' . htmlspecialchars($scope) . '</code></li>';
            }
            if ($scopeList === '') {
                $scopeList = "<li class='text-muted'>No scopes configured.</li>";
            }
            $characterStatusHtml = $renderCharacterStatuses($tokenMap, $characters, $requiredScopes, $profileKey);

            return "<div class='card card-body mb-3'>
                <div class='d-flex flex-wrap justify-content-between align-items-start gap-3'>
                  <div>
                    <div class='fw-semibold fs-5'>" . htmlspecialchars($profile['label'] ?? '') . "</div>
                    <div class='text-muted'>" . htmlspecialchars($profile['description'] ?? '') . "</div>
                    <div class='mt-2'>{$badge} <span class='text-muted small ms-2'>{$summary}</span></div>
                  </div>
                  <div class='text-end'>
                    <a class='btn btn-primary' href='" . htmlspecialchars($actionUrl) . "'>Authorize / Re-authorize</a>
                  </div>
                </div>
                {$characterStatusHtml}
                <details class='mt-3'>
                  <summary class='text-muted'>Show required permissions</summary>
                  <ul class='mt-2 mb-0'>{$scopeList}</ul>
                </details>
              </div>";
        };

        $totalCharacters = max(1, count($characterIds));
        $basicCard = $renderProfile(
            $profiles['basic'],
            $basicStatus,
            '/auth/authorize?profile=basic',
            $basicScopes,
            $totalCharacters,
            $basicMap,
            $characters,
            $basicScopes,
            'basic'
        );
        $memberCard = $renderProfile(
            $profiles['member_audit'],
            $memberStatus,
            '/auth/authorize?profile=member_audit',
            $memberScopes,
            $totalCharacters,
            $memberMap,
            $characters,
            $memberScopes,
            'member_audit'
        );

        $notes = "<div class='alert alert-info mt-3'>
            <strong>Tip:</strong> To fully load the member audit, authorize the audit token on each linked character.
            <a href='/user/alts' class='alert-link'>Manage linked characters</a>.
          </div>";

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Scopes &amp; Linking</h1>
                      <div class='text-muted'>Authorize the access profiles needed for your account.</div>
                    </div>
                  </div>
                  {$basicCard}
                  {$memberCard}
                  {$notes}";

        return Response::html(\App\Core\Layout::page('Scopes & Linking', $body, $leftTree, $adminTree, $userTree), 200);
    });

    $registry->route('GET', '/me', function (Request $req) use ($app): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $rights = new Rights($app->db);
        $hasRight = function (string $right) use ($rights, $uid): bool {
            if ($uid <= 0) return false;
            return $rights->userHasRight($uid, $right);
        };

        $leftTree  = $app->menu->tree('left', $hasRight);
        $adminTree = $app->menu->tree('admin_top', $hasRight);
        $userTree  = $app->menu->tree('user_top', fn(string $r) => true);
        $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));

        $u = new Universe($app->db);
        $primary = $app->db->one(
            "SELECT character_id, character_name FROM eve_users WHERE id=? LIMIT 1",
            [$uid]
        );
        $primaryCharacterId = (int)($primary['character_id'] ?? 0);

        $characters = [];
        if ($primaryCharacterId > 0) {
            $characters[] = [
                'character_id' => $primaryCharacterId,
                'character_name' => (string)($primary['character_name'] ?? 'Unknown'),
                'is_main' => true,
            ];
        }
        $links = $app->db->all(
            "SELECT character_id, character_name, linked_at
             FROM character_links
             WHERE user_id=? AND status='linked'
             ORDER BY linked_at ASC",
            [$uid]
        );
        foreach ($links as $link) {
            $linkId = (int)($link['character_id'] ?? 0);
            if ($linkId <= 0 || $linkId === $primaryCharacterId) continue;
            $characters[] = [
                'character_id' => $linkId,
                'character_name' => (string)($link['character_name'] ?? 'Unknown'),
                'linked_at' => (string)($link['linked_at'] ?? ''),
                'is_main' => false,
            ];
        }

        $search = trim((string)($req->query['q'] ?? ''));
        if ($search !== '') {
            $characters = array_values(array_filter($characters, function (array $row) use ($search): bool {
                return str_contains(mb_strtolower($row['character_name']), mb_strtolower($search));
            }));
        }

        $view = (string)($req->query['view'] ?? 'cards');
        $view = $view === 'table' ? 'table' : 'cards';
        $perPage = $view === 'table' ? 10 : 6;
        $page = max(1, (int)($req->query['page'] ?? 1));
        $total = count($characters);
        $offset = ($page - 1) * $perPage;
        $pagedCharacters = array_slice($characters, $offset, $perPage);

        $characterIds = array_values(array_unique(array_map(fn($row) => (int)$row['character_id'], $characters)));
        $summaryMap = [];
        if (!empty($characterIds)) {
            $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
            $rows = $app->db->all(
                "SELECT character_id, total_sp, last_login_at, location_system_id
                 FROM module_corptools_character_summary
                 WHERE character_id IN ({$placeholders})",
                $characterIds
            );
            foreach ($rows as $row) {
                $summaryMap[(int)$row['character_id']] = $row;
            }
        }

        $renderPagination = function (int $total, int $page, int $perPage, array $query): string {
            $pages = (int)ceil($total / max(1, $perPage));
            if ($pages <= 1) return '';
            $page = max(1, min($page, $pages));
            $buildUrl = function (int $target) use ($query): string {
                $query['page'] = $target;
                return '/me?' . http_build_query($query);
            };
            $prev = $page > 1 ? "<li class='page-item'><a class='page-link' href='" . htmlspecialchars($buildUrl($page - 1)) . "'>Prev</a></li>" : "<li class='page-item disabled'><span class='page-link'>Prev</span></li>";
            $next = $page < $pages ? "<li class='page-item'><a class='page-link' href='" . htmlspecialchars($buildUrl($page + 1)) . "'>Next</a></li>" : "<li class='page-item disabled'><span class='page-link'>Next</span></li>";
            $items = '';
            $start = max(1, $page - 2);
            $end = min($pages, $page + 2);
            for ($i = $start; $i <= $end; $i++) {
                $active = $i === $page ? 'active' : '';
                $items .= "<li class='page-item {$active}'><a class='page-link' href='" . htmlspecialchars($buildUrl($i)) . "'>{$i}</a></li>";
            }
            return "<nav class='mt-3'><ul class='pagination pagination-sm'>{$prev}{$items}{$next}</ul></nav>";
        };

        $cardsHtml = '';
        $tableRows = '';
        foreach ($pagedCharacters as $character) {
            $characterId = (int)($character['character_id'] ?? 0);
            if ($characterId <= 0) continue;
            $profile = $u->characterProfile($characterId);
            $portrait = $profile['character']['portrait']['px256x256'] ?? $profile['character']['portrait']['px128x128'] ?? null;
            $corpName = htmlspecialchars((string)($profile['corporation']['name'] ?? '—'));
            $allianceName = htmlspecialchars((string)($profile['alliance']['name'] ?? '—'));
            $name = htmlspecialchars((string)($profile['character']['name'] ?? $character['character_name'] ?? 'Unknown'));
            $summary = $summaryMap[$characterId] ?? [];
            $totalSp = isset($summary['total_sp']) ? number_format((int)$summary['total_sp']) : '—';
            $lastLogin = htmlspecialchars((string)($summary['last_login_at'] ?? '—'));
            $locationId = (int)($summary['location_system_id'] ?? 0);
            $location = $locationId > 0 ? htmlspecialchars($u->name('system', $locationId)) : '—';
            $mainBadge = !empty($character['is_main']) ? "<span class='badge bg-primary ms-2'>Main</span>" : '';

            $actions = '';
            if (empty($character['is_main'])) {
                $actions = "<form method='post' action='/user/alts/make-main' onsubmit=\"return confirm('Make {$name} your main character?');\">
                    <input type='hidden' name='character_id' value='{$characterId}'>
                    <button class='btn btn-sm btn-outline-light'>Make Main</button>
                  </form>";
            }

            $cardsHtml .= "<div class='col-md-6 col-xl-4'>
                <div class='card card-body h-100'>
                  <div class='d-flex align-items-center gap-3'>
                    " . ($portrait ? "<img src='" . htmlspecialchars($portrait) . "' width='64' height='64' style='border-radius:12px;'>" : "") . "
                    <div>
                      <div class='fw-semibold'>{$name}{$mainBadge}</div>
                      <div class='text-muted small'>{$corpName}</div>
                      <div class='text-muted small'>{$allianceName}</div>
                    </div>
                  </div>
                  <div class='mt-3'>
                    <div class='text-muted small'>Skill Points</div>
                    <div class='fw-semibold'>{$totalSp}</div>
                  </div>
                  <div class='mt-2'>
                    <div class='text-muted small'>Last Login</div>
                    <div class='fw-semibold'>{$lastLogin}</div>
                  </div>
                  <div class='mt-2'>
                    <div class='text-muted small'>Location</div>
                    <div class='fw-semibold'>{$location}</div>
                  </div>
                  <div class='mt-3 d-flex flex-wrap gap-2'>
                    <a class='btn btn-sm btn-outline-secondary' href='/corptools/audit?character_id={$characterId}'>Audit</a>
                    {$actions}
                  </div>
                </div>
              </div>";

            $tableRows .= "<tr>
                <td>{$name}{$mainBadge}</td>
                <td>{$corpName}</td>
                <td>{$allianceName}</td>
                <td>{$totalSp}</td>
                <td>{$lastLogin}</td>
                <td>{$location}</td>
                <td class='text-end'>
                  <a class='btn btn-sm btn-outline-secondary' href='/corptools/audit?character_id={$characterId}'>Audit</a>
                  {$actions}
                </td>
              </tr>";
        }

        if ($cardsHtml === '') {
            $cardsHtml = "<div class='col-12 text-muted'>No characters match your search.</div>";
        }
        if ($tableRows === '') {
            $tableRows = "<tr><td colspan='7' class='text-muted'>No characters match your search.</td></tr>";
        }

        $viewToggle = "<div class='btn-group'>
            <a class='btn btn-sm " . ($view === 'cards' ? 'btn-primary' : 'btn-outline-light') . "' href='/me?" . htmlspecialchars(http_build_query(['q' => $search, 'view' => 'cards'])) . "'>Cards</a>
            <a class='btn btn-sm " . ($view === 'table' ? 'btn-primary' : 'btn-outline-light') . "' href='/me?" . htmlspecialchars(http_build_query(['q' => $search, 'view' => 'table'])) . "'>Table</a>
          </div>";

        $query = array_filter(['q' => $search, 'view' => $view], fn($val) => $val !== '');
        $pagination = $renderPagination($total, $page, $perPage, $query);

        $listHtml = $view === 'table'
            ? "<div class='card card-body mt-3'>
                  <div class='table-responsive'>
                    <table class='table table-sm align-middle'>
                      <thead>
                        <tr>
                          <th>Character</th>
                          <th>Corporation</th>
                          <th>Alliance</th>
                          <th>SP</th>
                          <th>Last Login</th>
                          <th>Location</th>
                          <th class='text-end'>Actions</th>
                        </tr>
                      </thead>
                      <tbody>{$tableRows}</tbody>
                    </table>
                  </div>
                </div>"
            : "<div class='row g-3 mt-3'>{$cardsHtml}</div>";

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Profile</h1>
                      <div class='text-muted'>Your main character is shown first. Use search and view toggles to explore linked pilots.</div>
                    </div>
                    {$viewToggle}
                  </div>
                  <form method='get' class='card card-body mt-3'>
                    <div class='row g-2 align-items-end'>
                      <div class='col-md-6'>
                        <label class='form-label'>Search characters</label>
                        <input class='form-control' name='q' value='" . htmlspecialchars($search) . "' placeholder='Type a character name'>
                      </div>
                      <div class='col-md-4'>
                        <label class='form-label'>View</label>
                        <select class='form-select' name='view'>
                          <option value='cards'" . ($view === 'cards' ? ' selected' : '') . ">Cards</option>
                          <option value='table'" . ($view === 'table' ? ' selected' : '') . ">Table</option>
                        </select>
                      </div>
                      <div class='col-md-2'>
                        <button class='btn btn-primary w-100'>Apply</button>
                      </div>
                    </div>
                  </form>
                  {$listHtml}
                  {$pagination}";

        return Response::html(\App\Core\Layout::page('Profile', $body, $leftTree, $adminTree, $userTree), 200);
    });
};
