<?php
declare(strict_types=1);

/*
Module Name: Corp Tools
Description: Corporation dashboards inspired by CorpTools.
Version: 1.0.0
Module Slug: corptools
*/

use App\Core\App;
use App\Core\EsiCache;
use App\Core\EsiClient;
use App\Core\HttpClient;
use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Settings;
use App\Core\Universe;
use App\Corptools\MemberQueryBuilder;
use App\Corptools\Settings as CorpToolsSettings;
use App\Corptools\Audit\Dispatcher as AuditDispatcher;
use App\Corptools\Audit\Collectors\AssetsCollector;
use App\Corptools\Audit\Collectors\ClonesCollector;
use App\Corptools\Audit\Collectors\LocationCollector;
use App\Corptools\Audit\Collectors\RolesCollector;
use App\Corptools\Audit\Collectors\ShipCollector;
use App\Corptools\Audit\Collectors\SkillsCollector;
use App\Corptools\Audit\Collectors\WalletCollector;
use App\Corptools\Audit\SimpleCollector;
use App\Http\Request;
use App\Http\Response;

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->right('corptools.view', 'Access the CorpTools dashboards.');
    $registry->right('corptools.director', 'Access director-level corp dashboards.');
    $registry->right('corptools.admin', 'Manage CorpTools settings and integrations.');

    $registry->menu([
        'slug' => 'corptools',
        'title' => 'Corp Tools',
        'url' => '/corptools',
        'sort_order' => 40,
        'area' => 'left',
        'right_slug' => 'corptools.view',
    ]);

    $registry->menu([
        'slug' => 'admin.corptools',
        'title' => 'Corp Tools',
        'url' => '/admin/corptools',
        'sort_order' => 45,
        'area' => 'admin_top',
        'right_slug' => 'corptools.admin',
    ]);

    $renderPage = function (string $title, string $bodyHtml) use ($app): string {
        $rights = new Rights($app->db);
        $hasRight = function (string $right) use ($rights): bool {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) return false;
            return $rights->userHasRight($uid, $right);
        };

        $leftTree = $app->menu->tree('left', $hasRight);
        $adminTree = $app->menu->tree('admin_top', $hasRight);
        $userTree = $app->menu->tree('user_top', fn(string $r) => true);

        $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
        if ($loggedIn) {
            $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));
        } else {
            $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] === 'user.login'));
        }

        return Layout::page($title, $bodyHtml, $leftTree, $adminTree, $userTree);
    };

    $formatIsk = function (float $amount): string {
        return number_format($amount, 2) . ' ISK';
    };

    $corptoolsSettings = new CorpToolsSettings($app->db);
    $getCorpToolsSettings = function () use ($corptoolsSettings): array {
        return $corptoolsSettings->get();
    };

    $tokenData = function (int $characterId) use ($app): array {
        $row = $app->db->one(
            "SELECT access_token, scopes_json, expires_at
             FROM eve_tokens
             WHERE character_id=? LIMIT 1",
            [$characterId]
        );
        $scopes = [];
        $accessToken = null;
        $expired = false;
        if ($row) {
            $accessToken = (string)($row['access_token'] ?? '');
            $scopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
            if (!is_array($scopes)) $scopes = [];
            $expiresAt = $row['expires_at'] ? strtotime((string)$row['expires_at']) : null;
            if ($expiresAt !== null && time() > $expiresAt) {
                $expired = true;
            }
        }
        return ['access_token' => $accessToken, 'scopes' => $scopes, 'expired' => $expired];
    };

    $hasScopes = function (array $tokenScopes, array $requiredScopes): bool {
        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $tokenScopes, true)) return false;
        }
        return true;
    };

    $getCorpProfiles = function (array $corpIds) use ($app): array {
        $client = new EsiClient(new HttpClient());
        $cache = new EsiCache($app->db, $client);
        $universe = new Universe($app->db);

        $profiles = [];
        foreach ($corpIds as $corpId) {
            $corpId = (int)$corpId;
            if ($corpId <= 0) continue;
            $corp = $cache->getCached(
                "corp:{$corpId}",
                "GET /latest/corporations/{$corpId}/",
                3600,
                fn() => $client->get("/latest/corporations/{$corpId}/")
            );
            $icons = $cache->getCached(
                "corp:{$corpId}",
                "GET /latest/corporations/{$corpId}/icons/",
                86400,
                fn() => $client->get("/latest/corporations/{$corpId}/icons/")
            );
            $name = (string)($corp['name'] ?? 'Unknown');
            $ticker = (string)($corp['ticker'] ?? '');
            $label = $ticker !== '' ? "{$name} [{$ticker}]" : $name;
            $profiles[$label] = [
                'id' => $corpId,
                'name' => $name,
                'label' => $label,
                'icons' => $icons,
            ];
        }

        return $profiles;
    };

    $getCorpIds = function () use ($app): array {
        $settings = new Settings($app->db);
        $identityType = $settings->get('site.identity.type', 'corporation') ?? 'corporation';
        $identityType = $identityType === 'alliance' ? 'alliance' : 'corporation';
        $identityIdValue = $settings->get('site.identity.id', '0') ?? '0';
        $identityId = (int)$identityIdValue;
        if ($identityId <= 0) {
            return [];
        }
        if ($identityType === 'corporation') {
            return [$identityId];
        }

        $client = new EsiClient(new HttpClient());
        $cache = new EsiCache($app->db, $client);
        $corps = $cache->getCached(
            "corptools:alliance:{$identityId}",
            "GET /latest/alliances/{$identityId}/corporations/",
            3600
        );
        return is_array($corps) ? array_map('intval', $corps) : [];
    };

    $getCorpToken = function (int $corpId, array $requiredScopes) use ($app, $hasScopes): array {
        $rows = $app->db->all(
            "SELECT character_id, access_token, scopes_json, expires_at
             FROM eve_tokens"
        );
        $u = new Universe($app->db);

        foreach ($rows as $row) {
            $characterId = (int)($row['character_id'] ?? 0);
            if ($characterId <= 0) continue;

            $accessToken = (string)($row['access_token'] ?? '');
            $scopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
            if (!is_array($scopes)) $scopes = [];
            $expiresAt = $row['expires_at'] ? strtotime((string)$row['expires_at']) : null;
            $expired = $expiresAt !== null && time() > $expiresAt;

            if ($expired || $accessToken === '' || !$hasScopes($scopes, $requiredScopes)) {
                continue;
            }

            $profile = $u->characterProfile($characterId);
            $memberCorpId = (int)($profile['corporation']['id'] ?? 0);
            if ($memberCorpId !== $corpId) {
                continue;
            }

            return [
                'character_id' => $characterId,
                'access_token' => $accessToken,
                'scopes' => $scopes,
                'expired' => false,
            ];
        }

        return ['character_id' => 0, 'access_token' => null, 'scopes' => [], 'expired' => true];
    };

    $corpContext = function () use ($getCorpIds, $getCorpProfiles): array {
        $corpIds = $getCorpIds();

        $profiles = $getCorpProfiles($corpIds);
        $selected = (string)($_GET['corp'] ?? '');
        if ($selected === '' && !empty($profiles)) {
            $selected = array_key_first($profiles);
        }
        $corpProfile = $profiles[$selected] ?? null;

        return [
            'profiles' => $profiles,
            'selected' => $corpProfile,
        ];
    };

    $auditCollectors = function (): array {
        return [
            new AssetsCollector(),
            new ClonesCollector(),
            new LocationCollector(),
            new ShipCollector(),
            new SkillsCollector(),
            new WalletCollector(),
            new RolesCollector(),
            new SimpleCollector('implants', ['esi-clones.read_implants.v1'], ['/latest/characters/{character_id}/implants/'], 1800),
            new SimpleCollector('contacts', ['esi-characters.read_contacts.v1'], ['/latest/characters/{character_id}/contacts/'], 3600),
            new SimpleCollector('contracts', ['esi-contracts.read_character_contracts.v1'], ['/latest/characters/{character_id}/contracts/'], 1800),
            new SimpleCollector('corp_history', [], ['/latest/characters/{character_id}/corporationhistory/'], 86400),
            new SimpleCollector('loyalty', ['esi-characters.read_loyalty.v1'], ['/latest/characters/{character_id}/loyalty/points/'], 3600),
            new SimpleCollector('markets', ['esi-markets.read_character_orders.v1'], ['/latest/characters/{character_id}/orders/'], 600),
            new SimpleCollector('mining', ['esi-industry.read_character_mining.v1'], ['/latest/characters/{character_id}/mining/'], 1800),
            new SimpleCollector('notifications', ['esi-characters.read_notifications.v1'], ['/latest/characters/{character_id}/notifications/'], 300),
            new SimpleCollector('standings', ['esi-characters.read_standings.v1'], ['/latest/characters/{character_id}/standings/'], 7200),
            new SimpleCollector('activity', ['esi-characters.read_statistics.v1'], ['/latest/characters/{character_id}/stats/'], 3600),
        ];
    };

    $enabledAuditKeys = function (array $settings): array {
        $enabled = [];
        foreach (($settings['audit_scopes'] ?? []) as $key => $value) {
            if ($value) $enabled[] = $key;
        }
        return $enabled;
    };

    $updateMemberSummary = function (int $userId, int $mainCharacterId, string $mainName) use ($app): void {
        $mainSummary = $app->db->one(
            "SELECT corp_id, alliance_id FROM module_corptools_character_summary WHERE character_id=? LIMIT 1",
            [$mainCharacterId]
        );
        $corpId = (int)($mainSummary['corp_id'] ?? 0);
        $allianceId = (int)($mainSummary['alliance_id'] ?? 0);

        $stats = $app->db->one(
            "SELECT MAX(total_sp) AS max_sp, MIN(audit_loaded) AS audit_loaded
             FROM module_corptools_character_summary WHERE user_id=?",
            [$userId]
        );
        $highestSp = (int)($stats['max_sp'] ?? 0);
        $auditLoaded = (int)($stats['audit_loaded'] ?? 0);

        $lastLogin = $app->db->one("SELECT updated_at FROM eve_users WHERE id=? LIMIT 1", [$userId]);
        $lastLoginAt = $lastLogin['updated_at'] ?? null;

        $corpJoinedAt = null;
        if ($corpId > 0) {
            $historyRow = $app->db->one(
                "SELECT data_json FROM module_corptools_character_audit
                 WHERE character_id=? AND category='corp_history' LIMIT 1",
                [$mainCharacterId]
            );
            if ($historyRow) {
                $historyPayloads = json_decode((string)($historyRow['data_json'] ?? '[]'), true);
                $history = is_array($historyPayloads) ? ($historyPayloads[0] ?? []) : [];
                if (is_array($history)) {
                    foreach ($history as $entry) {
                        if (!is_array($entry)) continue;
                        if ((int)($entry['corporation_id'] ?? 0) !== $corpId) continue;
                        $corpJoinedAt = $entry['start_date'] ?? null;
                    }
                }
            }
        }

        $app->db->run(
            "INSERT INTO module_corptools_member_summary
             (user_id, main_character_id, main_character_name, corp_id, alliance_id, highest_sp, last_login_at, corp_joined_at, audit_loaded, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               main_character_id=VALUES(main_character_id),
               main_character_name=VALUES(main_character_name),
               corp_id=VALUES(corp_id),
               alliance_id=VALUES(alliance_id),
               highest_sp=VALUES(highest_sp),
               last_login_at=VALUES(last_login_at),
               corp_joined_at=VALUES(corp_joined_at),
               audit_loaded=VALUES(audit_loaded),
               updated_at=NOW()",
            [
                $userId,
                $mainCharacterId,
                $mainName,
                $corpId,
                $allianceId,
                $highestSp,
                $lastLoginAt,
                $corpJoinedAt,
                $auditLoaded,
            ]
        );
    };

    $registry->cron('invoice_sync', 900, function (App $app) use ($tokenData, $getCorpIds, $getCorpToolsSettings) {
        $settings = $getCorpToolsSettings();
        $corpIds = $getCorpIds();
        if (empty($corpIds)) return;

        $walletDivisions = $settings['invoices']['wallet_divisions']
            ?? $settings['general']['holding_wallet_divisions']
            ?? [1];
        if (!is_array($walletDivisions) || empty($walletDivisions)) {
            $walletDivisions = [1];
        }

        $client = new EsiClient(new HttpClient());
        $cache = new EsiCache($app->db, $client);

        foreach ($corpIds as $corpId) {
            $corpId = (int)$corpId;
            if ($corpId <= 0) continue;

            $tokenRow = $app->db->one(
                "SELECT character_id FROM eve_tokens WHERE JSON_CONTAINS(scopes_json, '" . '"' . "esi-wallet.read_corporation_wallets.v1" . '"' . "') LIMIT 1"
            );
            if (!$tokenRow) continue;

            $characterId = (int)($tokenRow['character_id'] ?? 0);
            if ($characterId <= 0) continue;

            $token = $tokenData($characterId);
            if (empty($token['access_token']) || $token['expired']) continue;

            $charData = $cache->getCached(
                "char:{$characterId}",
                "GET /latest/characters/{$characterId}/",
                3600,
                fn() => $client->get("/latest/characters/{$characterId}/")
            );
            $charCorpId = (int)($charData['corporation_id'] ?? 0);
            if ($charCorpId !== $corpId) continue;

            foreach ($walletDivisions as $division) {
                $division = (int)$division;
                if ($division <= 0) continue;

                $entries = $cache->getCachedAuth(
                    "corptools:wallet:{$corpId}:{$division}",
                    "GET /latest/corporations/{$corpId}/wallets/{$division}/journal/",
                    300,
                    (string)$token['access_token'],
                    [403, 404]
                );

                if (!is_array($entries)) continue;

                foreach ($entries as $entry) {
                    if (!is_array($entry)) continue;
                    $journalId = (int)($entry['id'] ?? 0);
                    if ($journalId <= 0) continue;
                    $amount = (float)($entry['amount'] ?? 0);
                    $balance = (float)($entry['balance'] ?? 0);
                    $refType = (string)($entry['ref_type'] ?? '');
                    $date = (string)($entry['date'] ?? '');
                    $firstParty = (int)($entry['first_party_id'] ?? 0);
                    $secondParty = (int)($entry['second_party_id'] ?? 0);
                    $reason = (string)($entry['reason'] ?? '');

                    $app->db->run(
                        "INSERT INTO module_corptools_invoice_payments
                         (corp_id, wallet_division, journal_id, ref_type, amount, balance, entry_date, first_party_id, second_party_id, reason, raw_json, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE
                           ref_type=VALUES(ref_type), amount=VALUES(amount), balance=VALUES(balance), entry_date=VALUES(entry_date),
                           first_party_id=VALUES(first_party_id), second_party_id=VALUES(second_party_id), reason=VALUES(reason), raw_json=VALUES(raw_json)",
                        [
                            $corpId,
                            $division,
                            $journalId,
                            $refType,
                            $amount,
                            $balance,
                            $date,
                            $firstParty,
                            $secondParty,
                            $reason,
                            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ]
                    );
                }
            }
        }
    });

    $registry->cron('audit_refresh', 3600, function (App $app) use (
        $tokenData,
        $auditCollectors,
        $enabledAuditKeys,
        $updateMemberSummary,
        $getCorpToolsSettings
    ) {
        $settings = $getCorpToolsSettings();
        $enabledKeys = $enabledAuditKeys($settings);
        if (empty($enabledKeys)) return;

        $dispatcher = new AuditDispatcher($app->db);
        $universe = new Universe($app->db);

        $users = $app->db->all("SELECT id, character_id, character_name FROM eve_users");
        $links = $app->db->all(
            "SELECT user_id, character_id, character_name FROM character_links WHERE status='linked'"
        );
        $linksByUser = [];
        foreach ($links as $link) {
            $linksByUser[(int)$link['user_id']][] = [
                'character_id' => (int)($link['character_id'] ?? 0),
                'character_name' => (string)($link['character_name'] ?? ''),
            ];
        }

        foreach ($users as $user) {
            $userId = (int)($user['id'] ?? 0);
            $mainCharacterId = (int)($user['character_id'] ?? 0);
            $mainName = (string)($user['character_name'] ?? '');
            if ($userId <= 0 || $mainCharacterId <= 0) continue;

            $characters = array_merge(
                [['character_id' => $mainCharacterId, 'character_name' => $mainName]],
                $linksByUser[$userId] ?? []
            );

            foreach ($characters as $character) {
                $characterId = (int)($character['character_id'] ?? 0);
                if ($characterId <= 0) continue;
                $token = $tokenData($characterId);
                if (empty($token['access_token']) || $token['expired']) {
                    continue;
                }

                $profile = $universe->characterProfile($characterId);
                $characterName = (string)($profile['character']['name'] ?? ($character['character_name'] ?? 'Unknown'));
                $baseSummary = [
                    'corp_id' => (int)($profile['corporation']['id'] ?? 0),
                    'alliance_id' => (int)($profile['alliance']['id'] ?? 0),
                    'is_main' => $characterId === $mainCharacterId ? 1 : 0,
                ];

                $dispatcher->run(
                    $userId,
                    $characterId,
                    $characterName,
                    $token,
                    $auditCollectors(),
                    $enabledKeys,
                    $baseSummary
                );
            }

            $updateMemberSummary($userId, $mainCharacterId, $mainName);
        }
    });

    $registry->cron('corp_audit_refresh', 3600, function (App $app) use ($getCorpIds, $getCorpToken, $getCorpToolsSettings) {
        $settings = $getCorpToolsSettings();
        $enabled = $settings['corp_audit'] ?? [];
        $corpIds = $getCorpIds();
        if (empty($corpIds)) return;

        $client = new EsiClient(new HttpClient());
        $cache = new EsiCache($app->db, $client);

        $collectors = [
            'wallets' => [
                'scopes' => ['esi-wallet.read_corporation_wallets.v1'],
                'endpoint' => '/latest/corporations/{corp_id}/wallets/',
                'ttl' => 600,
            ],
            'structures' => [
                'scopes' => ['esi-corporations.read_structures.v1'],
                'endpoint' => '/latest/corporations/{corp_id}/structures/',
                'ttl' => 900,
            ],
            'assets' => [
                'scopes' => ['esi-assets.read_corporation_assets.v1'],
                'endpoint' => '/latest/corporations/{corp_id}/assets/',
                'ttl' => 900,
            ],
            'sov' => [
                'scopes' => ['esi-sovereignty.read_corporation_campaigns.v1'],
                'endpoint' => '/latest/corporation/{corp_id}/sov/',
                'ttl' => 1800,
            ],
            'jump_bridges' => [
                'scopes' => ['esi-universe.read_structures.v1'],
                'endpoint' => '/latest/corporations/{corp_id}/structures/',
                'ttl' => 1800,
            ],
        ];

        foreach ($corpIds as $corpId) {
            $corpId = (int)$corpId;
            if ($corpId <= 0) continue;

            foreach ($collectors as $key => $cfg) {
                if (empty($enabled[$key])) continue;
                $token = $getCorpToken($corpId, $cfg['scopes']);
                if (empty($token['access_token']) || $token['expired']) continue;

                $endpoint = str_replace('{corp_id}', (string)$corpId, $cfg['endpoint']);
                $payload = $cache->getCachedAuth(
                    "corptools:corp:{$corpId}",
                    "GET {$endpoint}",
                    (int)$cfg['ttl'],
                    (string)$token['access_token'],
                    [403, 404]
                );

                $app->db->run(
                    "INSERT INTO module_corptools_corp_audit (corp_id, category, data_json, fetched_at)
                     VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE data_json=VALUES(data_json), fetched_at=NOW()",
                    [
                        $corpId,
                        $key,
                        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]
                );

                if ($key === 'structures' && is_array($payload)) {
                    foreach ($payload as $structure) {
                        if (!is_array($structure)) continue;
                        $structureId = (int)($structure['structure_id'] ?? 0);
                        if ($structureId <= 0) continue;
                        $systemId = (int)($structure['solar_system_id'] ?? 0);
                        $regionId = 0;
                        if ($systemId > 0) {
                            $system = $universe->entity('system', $systemId);
                            $extra = json_decode((string)($system['extra_json'] ?? '[]'), true);
                            if (is_array($extra)) {
                                $regionId = (int)($extra['region_id'] ?? 0);
                            }
                        }

                        $app->db->run(
                            "INSERT INTO module_corptools_industry_structures
                             (corp_id, structure_id, name, system_id, region_id, rigs_json, services_json, fuel_expires_at, state)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE
                               name=VALUES(name), system_id=VALUES(system_id), region_id=VALUES(region_id),
                               rigs_json=VALUES(rigs_json), services_json=VALUES(services_json),
                               fuel_expires_at=VALUES(fuel_expires_at), state=VALUES(state)",
                            [
                                $corpId,
                                $structureId,
                                (string)($structure['name'] ?? ''),
                                $systemId,
                                $regionId,
                                json_encode($structure['rigs'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                json_encode($structure['services'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                $structure['fuel_expires'] ?? null,
                                (string)($structure['state'] ?? ''),
                            ]
                        );
                    }
                }
            }

            if (!empty($enabled['metenox'])) {
                $app->db->run(
                    "INSERT INTO module_corptools_corp_audit (corp_id, category, data_json, fetched_at)
                     VALUES (?, 'metenox', ?, NOW())
                     ON DUPLICATE KEY UPDATE data_json=VALUES(data_json), fetched_at=NOW()",
                    [$corpId, json_encode(['status' => 'scaffolded'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
                );
            }
        }
    });

    $registry->cron('cleanup', 86400, function (App $app) use ($getCorpToolsSettings) {
        $settings = $getCorpToolsSettings();
        $retentionDays = (int)($settings['general']['retention_days'] ?? 30);
        if ($retentionDays <= 0) return;
        $cutoff = date('Y-m-d H:i:s', time() - ($retentionDays * 86400));

        $app->db->run(
            "DELETE FROM module_corptools_audit_runs WHERE started_at < ?",
            [$cutoff]
        );
        $app->db->run(
            "DELETE FROM module_corptools_character_audit WHERE updated_at < ?",
            [$cutoff]
        );
        $app->db->run(
            "DELETE FROM module_corptools_pings WHERE received_at < ?",
            [$cutoff]
        );
    });

    $registry->route('GET', '/corptools', function () use ($app, $renderPage, $corpContext, $tokenData, $hasScopes, $formatIsk): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) {
            $body = "<h1>Corp Tools</h1><div class='alert alert-warning mt-3'>No corporation is configured for CorpTools.</div>";
            return Response::html($renderPage('Corp Tools', $body), 200);
        }

        $corpLabel = htmlspecialchars((string)$corp['label']);
        $icon = $corp['icons']['px64x64'] ?? null;
        $token = $tokenData($cid);
        $invoiceTotal = (float)($app->db->one(
            "SELECT COALESCE(SUM(amount),0) AS total
             FROM module_corptools_invoice_payments
             WHERE corp_id=? AND entry_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$corp['id']]
        )['total'] ?? 0);

        $miningTotal = (float)($app->db->one(
            "SELECT COALESCE(SUM(quantity),0) AS total
             FROM module_corptools_moon_events
             WHERE corp_id=? AND event_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$corp['id']]
        )['total'] ?? 0);

        $walletDelta = (float)($app->db->one(
            "SELECT COALESCE(SUM(amount),0) AS total
             FROM module_corptools_invoice_payments
             WHERE corp_id=? AND entry_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
            [$corp['id']]
        )['total'] ?? 0);

        $notificationsCount = 0;
        $notificationsStatus = 'Needs scopes';
        $requiredNotifications = ['esi-characters.read_notifications.v1'];
        if (!$token['expired'] && $token['access_token'] && $hasScopes($token['scopes'], $requiredNotifications)) {
            $client = new EsiClient(new HttpClient());
            $cache = new EsiCache($app->db, $client);
            $notifications = $cache->getCachedAuth(
                "corptools:notifications:{$cid}",
                "GET /latest/characters/{$cid}/notifications/",
                300,
                (string)$token['access_token'],
                [403, 404]
            );
            if (is_array($notifications)) {
                $notificationsCount = count($notifications);
                $notificationsStatus = 'Cached';
            } else {
                $notificationsStatus = 'Unavailable';
            }
        }

        $iconHtml = $icon ? "<img src='" . htmlspecialchars((string)$icon) . "' width='48' height='48' style='border-radius:10px;'>" : '';

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-3'>
                    <div class='d-flex align-items-center gap-3'>
                      {$iconHtml}
                      <div>
                        <h1 class='mb-1'>Corp Tools</h1>
                        <div class='text-muted'>{$corpLabel}</div>
                      </div>
                    </div>
                    <div class='d-flex gap-2'>
                      <a class='btn btn-outline-light' href='/corptools/characters'>My Characters</a>
                      <a class='btn btn-outline-light' href='/corptools/overview'>At a Glance</a>
                      <a class='btn btn-outline-light' href='/corptools/invoices'>Invoices</a>
                      <a class='btn btn-outline-light' href='/corptools/moons'>Moons</a>
                      <a class='btn btn-outline-light' href='/corptools/members'>Members</a>
                      <a class='btn btn-outline-light' href='/corptools/industry'>Industry</a>
                      <a class='btn btn-outline-light' href='/corptools/corp-audit'>Corp Audit</a>
                      <a class='btn btn-outline-light' href='/corptools/notifications'>Notifications</a>
                    </div>
                  </div>
                  <div class='row g-3 mt-3'>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Wallet delta (24h)</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars($formatIsk($walletDelta)) . "</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Invoice total (7d)</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars($formatIsk($invoiceTotal)) . "</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Mining total (7d)</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars(number_format($miningTotal, 2)) . "</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Notifications</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars((string)$notificationsCount) . "</div>
                        <div class='text-muted small'>" . htmlspecialchars($notificationsStatus) . "</div>
                      </div>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>At a glance</div>
                    <div class='text-muted'>Use the tabs above to drill into corp wallets, moon extractions, industry assets, and notifications.</div>
                  </div>";

        return Response::html($renderPage('Corp Tools', $body), 200);
    }, ['right' => 'corptools.view']);

    $registry->route('GET', '/corptools/characters', function () use ($app, $renderPage): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $primary = $app->db->one("SELECT character_id, character_name FROM eve_users WHERE id=? LIMIT 1", [$uid]);
        $mainId = (int)($primary['character_id'] ?? 0);
        $mainName = (string)($primary['character_name'] ?? 'Unknown');

        $links = $app->db->all(
            "SELECT character_id, character_name FROM character_links WHERE user_id=? AND status='linked' ORDER BY linked_at ASC",
            [$uid]
        );

        $characters = array_merge(
            [['character_id' => $mainId, 'character_name' => $mainName, 'is_main' => true]],
            array_map(fn($row) => [
                'character_id' => (int)($row['character_id'] ?? 0),
                'character_name' => (string)($row['character_name'] ?? ''),
                'is_main' => false,
            ], $links)
        );

        $cards = '';
        foreach ($characters as $character) {
            $characterId = (int)($character['character_id'] ?? 0);
            if ($characterId <= 0) continue;
            $summary = $app->db->one(
                "SELECT audit_loaded, last_audit_at, total_sp, wallet_balance, assets_count
                 FROM module_corptools_character_summary WHERE character_id=? LIMIT 1",
                [$characterId]
            );
            $auditLoaded = ((int)($summary['audit_loaded'] ?? 0) === 1) ? 'Loaded' : 'Pending';
            $lastAudit = htmlspecialchars((string)($summary['last_audit_at'] ?? '—'));
            $sp = number_format((int)($summary['total_sp'] ?? 0));
            $wallet = number_format((float)($summary['wallet_balance'] ?? 0), 2);
            $assets = (int)($summary['assets_count'] ?? 0);
            $name = htmlspecialchars((string)($character['character_name'] ?? 'Unknown'));
            $badge = $character['is_main'] ? "<span class='badge bg-primary ms-2'>Main</span>" : '';

            $cards .= "<div class='col-md-6'>
                <div class='card card-body'>
                  <div class='d-flex justify-content-between'>
                    <div>
                      <div class='fw-semibold'>{$name}{$badge}</div>
                      <div class='text-muted small'>Audit: {$auditLoaded}</div>
                    </div>
                    <div class='text-muted small'>Last audit: {$lastAudit}</div>
                  </div>
                  <div class='row text-muted mt-2'>
                    <div class='col-4'>SP: {$sp}</div>
                    <div class='col-4'>Wallet: {$wallet}</div>
                    <div class='col-4'>Assets: {$assets}</div>
                  </div>
                </div>
              </div>";
        }
        if ($cards === '') {
            $cards = "<div class='col-12 text-muted'>No linked characters found.</div>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>My Characters</h1>
                      <div class='text-muted'>Audit coverage across your linked characters.</div>
                    </div>
                  </div>
                  <div class='row g-3 mt-3'>{$cards}</div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Audit cadence</div>
                    <div class='text-muted'>Audits refresh hourly through the CorpTools cron jobs. Missing scopes will show as pending.</div>
                  </div>";

        return Response::html($renderPage('My Characters', $body), 200);
    }, ['right' => 'corptools.view']);

    $registry->route('GET', '/corptools/overview', function () use ($app, $renderPage, $formatIsk): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $summary = $app->db->one(
            "SELECT SUM(wallet_balance) AS wallet_total, SUM(assets_count) AS assets_total, MAX(total_sp) AS max_sp
             FROM module_corptools_character_summary WHERE user_id=?",
            [$uid]
        );

        $walletTotal = (float)($summary['wallet_total'] ?? 0);
        $assetsTotal = (int)($summary['assets_total'] ?? 0);
        $maxSp = (int)($summary['max_sp'] ?? 0);

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>At a Glance</h1>
                      <div class='text-muted'>Cross-character overview for your account.</div>
                    </div>
                  </div>
                  <div class='row g-3 mt-3'>
                    <div class='col-md-4'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Total wallet</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars($formatIsk($walletTotal)) . "</div>
                      </div>
                    </div>
                    <div class='col-md-4'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Total assets</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars((string)$assetsTotal) . "</div>
                      </div>
                    </div>
                    <div class='col-md-4'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Highest skillpoints</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars(number_format($maxSp)) . "</div>
                      </div>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Next steps</div>
                    <div class='text-muted'>Visit My Characters to see audit coverage and missing scopes per pilot.</div>
                  </div>";

        return Response::html($renderPage('At a Glance', $body), 200);
    }, ['right' => 'corptools.view']);

    $registry->route('GET', '/corptools/invoices', function (Request $req) use ($app, $renderPage, $corpContext, $formatIsk, $getCorpToken): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) {
            $body = "<h1>Invoices</h1><div class='alert alert-warning mt-3'>No corporation is configured.</div>";
            return Response::html($renderPage('Invoices', $body), 200);
        }

        $start = (string)($req->query['start'] ?? '');
        $end = (string)($req->query['end'] ?? '');
        $params = [$corp['id']];
        $where = "corp_id=?";
        if ($start !== '') {
            $where .= " AND entry_date >= ?";
            $params[] = $start . ' 00:00:00';
        }
        if ($end !== '') {
            $where .= " AND entry_date <= ?";
            $params[] = $end . ' 23:59:59';
        }

        $token = $getCorpToken((int)$corp['id'], ['esi-wallet.read_corporation_wallets.v1']);
        $requiredScopes = ['esi-wallet.read_corporation_wallets.v1'];
        $missingScopes = !$token['expired'] && $token['access_token'] ? [] : $requiredScopes;

        $rows = $app->db->all(
            "SELECT entry_date, ref_type, amount, wallet_division
             FROM module_corptools_invoice_payments
             WHERE {$where}
             ORDER BY entry_date DESC
             LIMIT 200",
            $params
        );

        $rowsHtml = '';
        foreach ($rows as $row) {
            $date = htmlspecialchars((string)($row['entry_date'] ?? ''));
            $refType = htmlspecialchars((string)($row['ref_type'] ?? ''));
            $amount = (float)($row['amount'] ?? 0);
            $division = (int)($row['wallet_division'] ?? 0);
            $rowsHtml .= "<tr>
                <td>{$date}</td>
                <td>{$refType}</td>
                <td>Division {$division}</td>
                <td>" . htmlspecialchars($formatIsk($amount)) . "</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='4' class='text-muted'>No invoice payments cached yet.</td></tr>";
        }

        $missingHtml = '';
        if (!empty($missingScopes)) {
            $missingList = htmlspecialchars(implode(', ', $missingScopes));
            $missingHtml = "<div class='alert alert-warning'>Missing scopes: {$missingList}. <a href='/charlink'>Link via Character Link Hub</a>.</div>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Invoices</h1>
                      <div class='text-muted'>Wallet journal entries cached for invoice tracking.</div>
                    </div>
                  </div>
                  <div class='mt-3'>{$missingHtml}</div>
                  <form class='card card-body mt-3' method='get'>
                    <div class='row g-2'>
                      <div class='col-md-4'>
                        <label class='form-label'>Start date</label>
                        <input type='date' class='form-control' name='start' value='" . htmlspecialchars($start) . "'>
                      </div>
                      <div class='col-md-4'>
                        <label class='form-label'>End date</label>
                        <input type='date' class='form-control' name='end' value='" . htmlspecialchars($end) . "'>
                      </div>
                      <div class='col-md-4 d-flex align-items-end'>
                        <button class='btn btn-primary me-2'>Filter</button>
                        <a class='btn btn-outline-secondary' href='/corptools/invoices'>Reset</a>
                      </div>
                    </div>
                  </form>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Division</th>
                            <th>Amount</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Invoices', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools/members', function (Request $req) use ($app, $renderPage, $corpContext, $getCorpToolsSettings): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) {
            $body = "<h1>Members</h1><div class='alert alert-warning mt-3'>No corporation is configured.</div>";
            return Response::html($renderPage('Members', $body), 200);
        }

        $settings = $getCorpToolsSettings();
        $defaultAssetMin = (string)($settings['filters']['asset_value_min'] ?? '');
        $defaultAuditOnly = !empty($settings['filters']['audit_loaded_only']) ? '1' : '';

        $filters = [
            'asset_presence' => (string)($req->query['asset_presence'] ?? ''),
            'asset_value_min' => (string)($req->query['asset_value_min'] ?? $defaultAssetMin),
            'location_region_id' => (string)($req->query['location_region_id'] ?? ''),
            'location_system_id' => (string)($req->query['location_system_id'] ?? ''),
            'ship_type_id' => (string)($req->query['ship_type_id'] ?? ''),
            'corp_role' => (string)($req->query['corp_role'] ?? ''),
            'corp_title' => (string)($req->query['corp_title'] ?? ''),
            'highest_sp_min' => (string)($req->query['highest_sp_min'] ?? ''),
            'last_login_since' => (string)($req->query['last_login_since'] ?? ''),
            'corp_joined_since' => (string)($req->query['corp_joined_since'] ?? ''),
            'home_station_id' => (string)($req->query['home_station_id'] ?? ''),
            'death_clone_location_id' => (string)($req->query['death_clone_location_id'] ?? ''),
            'jump_clone_location_id' => (string)($req->query['jump_clone_location_id'] ?? ''),
            'audit_loaded' => (string)($req->query['audit_loaded'] ?? $defaultAuditOnly),
            'skill_id' => (string)($req->query['skill_id'] ?? ''),
            'asset_type_id' => (string)($req->query['asset_type_id'] ?? ''),
            'asset_group_id' => (string)($req->query['asset_group_id'] ?? ''),
            'asset_category_id' => (string)($req->query['asset_category_id'] ?? ''),
        ];

        $builder = new MemberQueryBuilder();
        $query = $builder->build($filters);
        $rows = $app->db->all($query['sql'] . ' LIMIT 200', $query['params']);

        $u = new Universe($app->db);
        $rowsHtml = '';
        foreach ($rows as $row) {
            $name = htmlspecialchars((string)($row['main_character_name'] ?? $row['character_name'] ?? 'Unknown'));
            $sp = number_format((int)($row['highest_sp'] ?? 0));
            $audit = ((int)($row['audit_loaded'] ?? 0) === 1) ? 'Yes' : 'No';
            $systemId = (int)($row['location_system_id'] ?? 0);
            $systemName = $systemId > 0 ? htmlspecialchars($u->name('system', $systemId)) : '—';
            $shipTypeId = (int)($row['current_ship_type_id'] ?? 0);
            $shipName = $shipTypeId > 0 ? htmlspecialchars($u->name('type', $shipTypeId)) : '—';
            $assets = (int)($row['assets_count'] ?? 0);
            $title = htmlspecialchars((string)($row['corp_title'] ?? '—'));

            $rowsHtml .= "<tr>
                <td>{$name}</td>
                <td>{$sp}</td>
                <td>{$audit}</td>
                <td>{$systemName}</td>
                <td>{$shipName}</td>
                <td>{$assets}</td>
                <td>{$title}</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='7' class='text-muted'>No members matched the filters yet.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Members</h1>
                      <div class='text-muted'>Security filters driven by audit snapshots.</div>
                    </div>
                  </div>
                  <form method='get' class='card card-body mt-3'>
                    <div class='row g-2'>
                      <div class='col-md-3'>
                        <label class='form-label'>Assets</label>
                        <select class='form-select' name='asset_presence'>
                          <option value=''>Any</option>
                          <option value='has'" . ($filters['asset_presence'] === 'has' ? ' selected' : '') . ">Has assets</option>
                          <option value='none'" . ($filters['asset_presence'] === 'none' ? ' selected' : '') . ">No assets</option>
                        </select>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Asset value min (ISK)</label>
                        <input class='form-control' name='asset_value_min' value='" . htmlspecialchars($filters['asset_value_min']) . "' placeholder='0'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Location system ID</label>
                        <input class='form-control' name='location_system_id' value='" . htmlspecialchars($filters['location_system_id']) . "' placeholder='30000142'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Ship type ID</label>
                        <input class='form-control' name='ship_type_id' value='" . htmlspecialchars($filters['ship_type_id']) . "' placeholder='603'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Corp role contains</label>
                        <input class='form-control' name='corp_role' value='" . htmlspecialchars($filters['corp_role']) . "' placeholder='Director'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Corp title</label>
                        <input class='form-control' name='corp_title' value='" . htmlspecialchars($filters['corp_title']) . "' placeholder='Quartermaster'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Highest SP min</label>
                        <input class='form-control' name='highest_sp_min' value='" . htmlspecialchars($filters['highest_sp_min']) . "' placeholder='50000000'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Audit loaded</label>
                        <select class='form-select' name='audit_loaded'>
                          <option value=''>Any</option>
                          <option value='1'" . ($filters['audit_loaded'] === '1' ? ' selected' : '') . ">Loaded</option>
                        </select>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Skill ID</label>
                        <input class='form-control' name='skill_id' value='" . htmlspecialchars($filters['skill_id']) . "' placeholder='3300'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Asset type ID</label>
                        <input class='form-control' name='asset_type_id' value='" . htmlspecialchars($filters['asset_type_id']) . "' placeholder='34'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Asset group ID</label>
                        <input class='form-control' name='asset_group_id' value='" . htmlspecialchars($filters['asset_group_id']) . "' placeholder='18'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Asset category ID</label>
                        <input class='form-control' name='asset_category_id' value='" . htmlspecialchars($filters['asset_category_id']) . "' placeholder='6'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Last login since</label>
                        <input type='date' class='form-control' name='last_login_since' value='" . htmlspecialchars($filters['last_login_since']) . "'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Corp joined since</label>
                        <input type='date' class='form-control' name='corp_joined_since' value='" . htmlspecialchars($filters['corp_joined_since']) . "'>
                      </div>
                      <div class='col-md-3 d-flex align-items-end'>
                        <button class='btn btn-primary me-2'>Apply filters</button>
                        <a class='btn btn-outline-secondary' href='/corptools/members'>Reset</a>
                      </div>
                    </div>
                  </form>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Member</th>
                            <th>Highest SP</th>
                            <th>Audit</th>
                            <th>Location</th>
                            <th>Ship</th>
                            <th>Assets</th>
                            <th>Title</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Members', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools/moons', function () use ($app, $renderPage, $corpContext, $getCorpToolsSettings): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) {
            $body = "<h1>Moon Tracking</h1><div class='alert alert-warning mt-3'>No corporation is configured.</div>";
            return Response::html($renderPage('Moon Tracking', $body), 200);
        }

        $settings = $getCorpToolsSettings();
        $defaultTax = (float)($settings['moons']['default_tax_rate'] ?? 0);

        $rows = $app->db->all(
            "SELECT event_date, moon_name, pilot_name, ore_name, quantity, tax_rate
             FROM module_corptools_moon_events
             WHERE corp_id=?
             ORDER BY event_date DESC
             LIMIT 200",
            [$corp['id']]
        );

        $rowsHtml = '';
        foreach ($rows as $row) {
            $date = htmlspecialchars((string)($row['event_date'] ?? ''));
            $moon = htmlspecialchars((string)($row['moon_name'] ?? 'Unknown'));
            $pilot = htmlspecialchars((string)($row['pilot_name'] ?? 'Unknown'));
            $ore = htmlspecialchars((string)($row['ore_name'] ?? 'Unknown'));
            $qty = htmlspecialchars((string)($row['quantity'] ?? '0'));
            $tax = htmlspecialchars((string)($row['tax_rate'] ?? '0'));
            $rowsHtml .= "<tr>
                <td>{$date}</td>
                <td>{$moon}</td>
                <td>{$pilot}</td>
                <td>{$ore}</td>
                <td>{$qty}</td>
                <td>{$tax}%</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='6' class='text-muted'>No moon events recorded yet.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Moon Tracking</h1>
                      <div class='text-muted'>Manual or imported moon extraction records.</div>
                    </div>
                  </div>
                  <form method='post' action='/corptools/moons/add' class='card card-body mt-3'>
                    <div class='row g-2'>
                      <div class='col-md-3'>
                        <label class='form-label'>Date</label>
                        <input type='date' class='form-control' name='event_date' required>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Moon</label>
                        <input type='text' class='form-control' name='moon_name' required>
                      </div>
                      <div class='col-md-2'>
                        <label class='form-label'>Pilot</label>
                        <input type='text' class='form-control' name='pilot_name' required>
                      </div>
                      <div class='col-md-2'>
                        <label class='form-label'>Ore</label>
                        <input type='text' class='form-control' name='ore_name' required>
                      </div>
                      <div class='col-md-1'>
                        <label class='form-label'>Qty</label>
                        <input type='number' step='0.01' class='form-control' name='quantity' required>
                      </div>
                      <div class='col-md-1'>
                        <label class='form-label'>Tax %</label>
                        <input type='number' step='0.01' class='form-control' name='tax_rate' value='" . htmlspecialchars((string)$defaultTax) . "'>
                      </div>
                    </div>
                    <button class='btn btn-primary mt-3'>Add event</button>
                  </form>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Date</th>
                            <th>Moon</th>
                            <th>Pilot</th>
                            <th>Ore</th>
                            <th>Quantity</th>
                            <th>Tax</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Moon Tracking', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('POST', '/corptools/moons/add', function (Request $req) use ($app, $corpContext, $getCorpToolsSettings): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) return Response::redirect('/corptools/moons');

        $eventDate = (string)($req->post['event_date'] ?? '');
        $moonName = trim((string)($req->post['moon_name'] ?? ''));
        $pilotName = trim((string)($req->post['pilot_name'] ?? ''));
        $oreName = trim((string)($req->post['ore_name'] ?? ''));
        $quantity = (float)($req->post['quantity'] ?? 0);
        $taxRate = (float)($req->post['tax_rate'] ?? 0);
        if ($taxRate <= 0) {
            $settings = $getCorpToolsSettings();
            $taxRate = (float)($settings['moons']['default_tax_rate'] ?? 0);
        }

        if ($eventDate !== '' && $moonName !== '' && $pilotName !== '' && $oreName !== '') {
            $app->db->run(
                "INSERT INTO module_corptools_moon_events
                 (corp_id, event_date, moon_name, pilot_name, ore_name, quantity, tax_rate, created_by_user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$corp['id'], $eventDate, $moonName, $pilotName, $oreName, $quantity, $taxRate, $uid]
            );
        }

        return Response::redirect('/corptools/moons');
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools/industry', function () use ($app, $renderPage, $corpContext): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) {
            $body = "<h1>Industry</h1><div class='alert alert-warning mt-3'>No corporation is configured.</div>";
            return Response::html($renderPage('Industry', $body), 200);
        }

        $rows = $app->db->all(
            "SELECT structure_id, name, system_id, region_id, rigs_json, services_json, state, fuel_expires_at
             FROM module_corptools_industry_structures
             WHERE corp_id=?
             ORDER BY name ASC
             LIMIT 200",
            [$corp['id']]
        );

        $u = new Universe($app->db);
        $rowsHtml = '';
        foreach ($rows as $structure) {
            $name = htmlspecialchars((string)($structure['name'] ?? 'Unknown structure'));
            $systemId = (int)($structure['system_id'] ?? 0);
            $regionId = (int)($structure['region_id'] ?? 0);
            $systemName = $systemId > 0 ? htmlspecialchars($u->name('system', $systemId)) : '—';
            $regionName = $regionId > 0 ? htmlspecialchars($u->name('region', $regionId)) : '—';
            $rigs = json_decode((string)($structure['rigs_json'] ?? '[]'), true);
            $rigNames = [];
            if (is_array($rigs)) {
                foreach ($rigs as $rigId) {
                    $rigId = (int)$rigId;
                    if ($rigId > 0) $rigNames[] = $u->name('type', $rigId);
                }
            }
            $rigText = htmlspecialchars(implode(', ', array_filter($rigNames)));
            if ($rigText === '') $rigText = '—';
            $rowsHtml .= "<tr>
                <td>{$name}</td>
                <td>{$systemName}</td>
                <td>{$regionName}</td>
                <td>{$rigText}</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='4' class='text-muted'>No structure data available. Run the CorpTools corp audit job to populate structures.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Industry Dash</h1>
                      <div class='text-muted'>Structures, rigs, and services overview.</div>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Structure</th>
                            <th>System</th>
                            <th>Region</th>
                            <th>Rigs</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Industry', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools/corp-audit', function () use ($app, $renderPage, $corpContext): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        if (!$corp) {
            $body = "<h1>Corp Audit</h1><div class='alert alert-warning mt-3'>No corporation is configured.</div>";
            return Response::html($renderPage('Corp Audit', $body), 200);
        }

        $rows = $app->db->all(
            "SELECT category, data_json, fetched_at
             FROM module_corptools_corp_audit
             WHERE corp_id=?
             ORDER BY category ASC",
            [$corp['id']]
        );

        $cards = '';
        foreach ($rows as $row) {
            $category = htmlspecialchars((string)($row['category'] ?? 'unknown'));
            $data = json_decode((string)($row['data_json'] ?? '[]'), true);
            $count = is_array($data) ? count($data) : 0;
            $fetched = htmlspecialchars((string)($row['fetched_at'] ?? '—'));
            $cards .= "<div class='col-md-4'>
                <div class='card card-body'>
                  <div class='fw-semibold text-capitalize'>{$category}</div>
                  <div class='text-muted small'>Records: {$count}</div>
                  <div class='text-muted small'>Last sync: {$fetched}</div>
                </div>
              </div>";
        }
        if ($cards === '') {
            $cards = "<div class='col-12 text-muted'>No corp audit data cached yet. Run the corp audit cron job.</div>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Corp Audit</h1>
                      <div class='text-muted'>Corp-wide dashboards for wallets, structures, assets, and more.</div>
                    </div>
                  </div>
                  <div class='row g-3 mt-3'>{$cards}</div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Metenox / Sov / Jump Bridges</div>
                    <div class='text-muted'>These dashboards are scaffolded and will populate as corp audit scopes are enabled.</div>
                  </div>";

        return Response::html($renderPage('Corp Audit', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools/notifications', function () use ($app, $renderPage, $tokenData, $hasScopes): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $token = $tokenData($cid);
        $requiredScopes = ['esi-characters.read_notifications.v1'];
        $missingScopes = !$token['expired'] && $token['access_token'] && $hasScopes($token['scopes'], $requiredScopes) ? [] : $requiredScopes;
        $notifications = [];

        if (empty($missingScopes)) {
            $client = new EsiClient(new HttpClient());
            $cache = new EsiCache($app->db, $client);
            $notifications = $cache->getCachedAuth(
                "corptools:notifications:{$cid}",
                "GET /latest/characters/{$cid}/notifications/",
                300,
                (string)$token['access_token'],
                [403, 404]
            );
            if (!is_array($notifications)) $notifications = [];
        }

        $rowsHtml = '';
        foreach ($notifications as $note) {
            if (!is_array($note)) continue;
            $type = htmlspecialchars((string)($note['type'] ?? 'Unknown'));
            $timestamp = htmlspecialchars((string)($note['timestamp'] ?? ''));
            $rowsHtml .= "<tr>
                <td>{$timestamp}</td>
                <td>{$type}</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='2' class='text-muted'>No notifications cached yet.</td></tr>";
        }

        $missingHtml = '';
        if (!empty($missingScopes)) {
            $missingList = htmlspecialchars(implode(', ', $missingScopes));
            $missingHtml = "<div class='alert alert-warning'>Missing scopes: {$missingList}. <a href='/charlink'>Link via Character Link Hub</a>.</div>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Notifications</h1>
                      <div class='text-muted'>Character and corp pings (cached).</div>
                    </div>
                  </div>
                  <div class='mt-3'>{$missingHtml}</div>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Timestamp</th>
                            <th>Type</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Notification rules</div>
                    <div class='text-muted'>Webhook dispatch is configured in Admin → Corp Tools → Pinger.</div>
                  </div>";

        return Response::html($renderPage('Notifications', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('POST', '/corptools/pinger', function (Request $req) use ($app, $getCorpToolsSettings): Response {
        $settings = $getCorpToolsSettings();
        $secret = (string)($settings['pinger']['shared_secret'] ?? '');
        $provided = (string)($req->server['HTTP_X_CORPTOOLS_TOKEN'] ?? ($req->query['token'] ?? ''));
        if ($secret !== '' && hash_equals($secret, $provided) === false) {
            return Response::text("Unauthorized\n", 403);
        }

        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = ['raw' => $raw];
        }

        $eventId = (string)($payload['event_id'] ?? $payload['id'] ?? '');
        $eventHash = hash('sha256', $eventId !== '' ? $eventId : $raw);

        $inserted = $app->db->run(
            "INSERT IGNORE INTO module_corptools_pings (event_hash, source, payload_json, received_at)
             VALUES (?, 'webhook', ?, NOW())",
            [$eventHash, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
        );

        if ($inserted > 0) {
            $webhook = (string)($settings['pinger']['webhook_url'] ?? '');
            if ($webhook !== '') {
                $ch = curl_init($webhook);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    CURLOPT_TIMEOUT => 5,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        return Response::text("OK\n", 202);
    }, ['public' => true]);

    $registry->route('GET', '/corptools/health', function () use ($app, $renderPage, $getCorpToolsSettings): Response {
        $tables = [
            'module_corptools_settings',
            'module_corptools_character_summary',
            'module_corptools_member_summary',
            'module_corptools_character_audit',
            'module_corptools_pings',
            'module_corptools_industry_structures',
            'module_corptools_corp_audit',
        ];

        $rowsHtml = '';
        foreach ($tables as $table) {
            $exists = $app->db->one("SHOW TABLES LIKE ?", [$table]);
            $status = $exists ? "<span class='badge bg-success'>OK</span>" : "<span class='badge bg-danger'>Missing</span>";
            $rowsHtml .= "<tr><td>{$table}</td><td>{$status}</td></tr>";
        }

        $settings = $getCorpToolsSettings();
        $pingerWebhook = htmlspecialchars((string)($settings['pinger']['webhook_url'] ?? ''));
        $retentionDays = htmlspecialchars((string)($settings['general']['retention_days'] ?? 30));

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>CorpTools Health Check</h1>
                      <div class='text-muted'>Schema and configuration verification.</div>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Schema</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead><tr><th>Table</th><th>Status</th></tr></thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Configuration</div>
                    <div class='text-muted'>Retention days: {$retentionDays}</div>
                    <div class='text-muted'>Pinger webhook: " . ($pingerWebhook !== '' ? $pingerWebhook : 'Not set') . "</div>
                  </div>";

        return Response::html($renderPage('CorpTools Health', $body), 200);
    }, ['right' => 'corptools.admin']);

    $registry->route('GET', '/admin/corptools', function () use ($app, $renderPage, $getCorpToolsSettings): Response {
        $settings = $getCorpToolsSettings();
        $tab = (string)($_GET['tab'] ?? 'general');

        $tabs = [
            'general' => 'General',
            'audit' => 'Audit Scopes',
            'corp_audit' => 'Corp Audit',
            'invoices' => 'Invoices',
            'moons' => 'Moons',
            'indy' => 'Indy Dash',
            'pinger' => 'Pinger',
            'filters' => 'Filters',
        ];

        $nav = "<ul class='nav nav-tabs mt-3'>";
        foreach ($tabs as $key => $label) {
            $active = $tab === $key ? 'active' : '';
            $nav .= "<li class='nav-item'>
                        <a class='nav-link {$active}' href='/admin/corptools?tab={$key}'>" . htmlspecialchars($label) . "</a>
                      </li>";
        }
        $nav .= "</ul>";

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Corp Tools Settings</h1>
                      <div class='text-muted'>Configure the CorpTools ecosystem.</div>
                    </div>
                  </div>
                  {$nav}";

        $sectionHtml = '';
        if ($tab === 'general') {
            $divisions = htmlspecialchars(implode(',', $settings['general']['holding_wallet_divisions'] ?? [1]));
            $label = htmlspecialchars((string)($settings['general']['holding_wallet_label'] ?? 'Holding Wallet'));
            $retention = htmlspecialchars((string)($settings['general']['retention_days'] ?? 30));
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='general'>
                <label class='form-label'>Holding wallet divisions (comma-separated)</label>
                <input class='form-control' name='holding_wallet_divisions' value='{$divisions}'>
                <label class='form-label mt-3'>Holding wallet label</label>
                <input class='form-control' name='holding_wallet_label' value='{$label}'>
                <label class='form-label mt-3'>Retention days</label>
                <input class='form-control' name='retention_days' value='{$retention}'>
                <button class='btn btn-primary mt-3'>Save General Settings</button>
              </form>";
        } elseif ($tab === 'audit') {
            $audit = $settings['audit_scopes'] ?? [];
            $rows = '';
            foreach ($audit as $key => $enabled) {
                $checked = $enabled ? 'checked' : '';
                $rows .= "<div class='form-check'>
                            <input class='form-check-input' type='checkbox' name='scopes[]' value='{$key}' id='audit-{$key}' {$checked}>
                            <label class='form-check-label' for='audit-{$key}'>" . htmlspecialchars(str_replace('_', ' ', (string)$key)) . "</label>
                          </div>";
            }
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='audit'>
                <div class='fw-semibold mb-2'>Enabled audit scopes</div>
                {$rows}
                <button class='btn btn-primary mt-3'>Save Audit Scopes</button>
              </form>";
        } elseif ($tab === 'corp_audit') {
            $corpAudit = $settings['corp_audit'] ?? [];
            $rows = '';
            foreach ($corpAudit as $key => $enabled) {
                $checked = $enabled ? 'checked' : '';
                $rows .= "<div class='form-check'>
                            <input class='form-check-input' type='checkbox' name='corp_scopes[]' value='{$key}' id='corp-{$key}' {$checked}>
                            <label class='form-check-label' for='corp-{$key}'>" . htmlspecialchars(str_replace('_', ' ', (string)$key)) . "</label>
                          </div>";
            }
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='corp_audit'>
                <div class='fw-semibold mb-2'>Corp audit collectors</div>
                {$rows}
                <button class='btn btn-primary mt-3'>Save Corp Audit Settings</button>
              </form>";
        } elseif ($tab === 'invoices') {
            $divisions = htmlspecialchars(implode(',', $settings['invoices']['wallet_divisions'] ?? [1]));
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='invoices'>
                <label class='form-label'>Wallet divisions (comma-separated)</label>
                <input class='form-control' name='wallet_divisions' value='{$divisions}'>
                <button class='btn btn-primary mt-3'>Save Invoice Settings</button>
              </form>";
        } elseif ($tab === 'moons') {
            $tax = htmlspecialchars((string)($settings['moons']['default_tax_rate'] ?? 0));
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='moons'>
                <label class='form-label'>Default tax rate (%)</label>
                <input class='form-control' name='default_tax_rate' value='{$tax}'>
                <button class='btn btn-primary mt-3'>Save Moon Settings</button>
              </form>";
        } elseif ($tab === 'indy') {
            $enabled = !empty($settings['indy']['enabled']) ? 'checked' : '';
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='indy'>
                <div class='form-check'>
                  <input class='form-check-input' type='checkbox' name='enabled' value='1' id='indy-enabled' {$enabled}>
                  <label class='form-check-label' for='indy-enabled'>Enable Indy dashboards</label>
                </div>
                <button class='btn btn-primary mt-3'>Save Indy Settings</button>
              </form>";
        } elseif ($tab === 'pinger') {
            $webhook = htmlspecialchars((string)($settings['pinger']['webhook_url'] ?? ''));
            $secret = htmlspecialchars((string)($settings['pinger']['shared_secret'] ?? ''));
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='pinger'>
                <label class='form-label'>Webhook URL</label>
                <input class='form-control' name='webhook_url' value='{$webhook}' placeholder='https://...'>
                <label class='form-label mt-3'>Shared secret (optional)</label>
                <input class='form-control' name='shared_secret' value='{$secret}'>
                <button class='btn btn-primary mt-3'>Save Pinger Settings</button>
              </form>";

            $rules = $app->db->all(
                "SELECT name, filters_json, is_enabled
                 FROM module_corptools_notification_rules
                 ORDER BY id DESC"
            );
            $ruleRows = '';
            foreach ($rules as $rule) {
                $name = htmlspecialchars((string)($rule['name'] ?? 'Rule'));
                $filters = json_decode((string)($rule['filters_json'] ?? '[]'), true);
                $filtersText = is_array($filters) ? implode(', ', array_filter($filters, 'is_string')) : '';
                $filtersText = htmlspecialchars($filtersText !== '' ? $filtersText : '—');
                $enabled = ((int)($rule['is_enabled'] ?? 0) === 1) ? 'Enabled' : 'Disabled';
                $ruleRows .= "<tr>
                    <td>{$name}</td>
                    <td>{$filtersText}</td>
                    <td>{$enabled}</td>
                  </tr>";
            }
            if ($ruleRows === '') {
                $ruleRows = "<tr><td colspan='3' class='text-muted'>No rules configured.</td></tr>";
            }

            $sectionHtml .= "<form method='post' action='/admin/corptools/rules' class='card card-body mt-3'>
                <div class='fw-semibold mb-2'>Add notification rule</div>
                <div class='row g-2'>
                  <div class='col-md-4'>
                    <label class='form-label'>Rule name</label>
                    <input class='form-control' name='name' required>
                  </div>
                  <div class='col-md-6'>
                    <label class='form-label'>Filters (comma-separated)</label>
                    <input class='form-control' name='filters' placeholder='Region, System, Type'>
                  </div>
                  <div class='col-md-2 d-flex align-items-end'>
                    <button class='btn btn-outline-light'>Add</button>
                  </div>
                </div>
              </form>
              <div class='card card-body mt-3'>
                <div class='fw-semibold mb-2'>Existing rules</div>
                <div class='table-responsive'>
                  <table class='table table-sm align-middle'>
                    <thead>
                      <tr>
                        <th>Name</th>
                        <th>Filters</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>{$ruleRows}</tbody>
                  </table>
                </div>
              </div>";
        } elseif ($tab === 'filters') {
            $assetValue = htmlspecialchars((string)($settings['filters']['asset_value_min'] ?? 0));
            $auditOnly = !empty($settings['filters']['audit_loaded_only']) ? 'checked' : '';
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='filters'>
                <label class='form-label'>Default asset value min</label>
                <input class='form-control' name='asset_value_min' value='{$assetValue}'>
                <div class='form-check mt-3'>
                  <input class='form-check-input' type='checkbox' name='audit_loaded_only' value='1' id='audit-only' {$auditOnly}>
                  <label class='form-check-label' for='audit-only'>Filter to audit-loaded members by default</label>
                </div>
                <button class='btn btn-primary mt-3'>Save Filter Defaults</button>
              </form>";
        }

        $body .= $sectionHtml;

        return Response::html($renderPage('Corp Tools Settings', $body), 200);
    }, ['right' => 'corptools.admin']);

    $registry->route('POST', '/admin/corptools/settings', function (Request $req) use ($app): Response {
        $section = (string)($req->post['section'] ?? 'general');
        $settings = new CorpToolsSettings($app->db);

        if ($section === 'general') {
            $divisions = array_values(array_filter(array_map('trim', explode(',', (string)($req->post['holding_wallet_divisions'] ?? '')))));
            $divisions = array_map('intval', $divisions);
            $settings->updateSection('general', [
                'holding_wallet_divisions' => $divisions ?: [1],
                'holding_wallet_label' => trim((string)($req->post['holding_wallet_label'] ?? 'Holding Wallet')),
                'retention_days' => (int)($req->post['retention_days'] ?? 30),
            ]);
        } elseif ($section === 'audit') {
            $selected = $req->post['scopes'] ?? [];
            if (!is_array($selected)) $selected = [];
            $current = $settings->get()['audit_scopes'] ?? [];
            $next = [];
            foreach ($current as $key => $enabled) {
                $next[$key] = in_array($key, $selected, true);
            }
            $settings->updateSection('audit_scopes', $next);
        } elseif ($section === 'corp_audit') {
            $selected = $req->post['corp_scopes'] ?? [];
            if (!is_array($selected)) $selected = [];
            $current = $settings->get()['corp_audit'] ?? [];
            $next = [];
            foreach ($current as $key => $enabled) {
                $next[$key] = in_array($key, $selected, true);
            }
            $settings->updateSection('corp_audit', $next);
        } elseif ($section === 'invoices') {
            $divisions = array_values(array_filter(array_map('trim', explode(',', (string)($req->post['wallet_divisions'] ?? '')))));
            $divisions = array_map('intval', $divisions);
            $settings->updateSection('invoices', [
                'wallet_divisions' => $divisions ?: [1],
            ]);
        } elseif ($section === 'moons') {
            $settings->updateSection('moons', [
                'default_tax_rate' => (float)($req->post['default_tax_rate'] ?? 0),
            ]);
        } elseif ($section === 'indy') {
            $settings->updateSection('indy', [
                'enabled' => !empty($req->post['enabled']),
            ]);
        } elseif ($section === 'pinger') {
            $settings->updateSection('pinger', [
                'webhook_url' => trim((string)($req->post['webhook_url'] ?? '')),
                'shared_secret' => trim((string)($req->post['shared_secret'] ?? '')),
            ]);
        } elseif ($section === 'filters') {
            $settings->updateSection('filters', [
                'asset_value_min' => (float)($req->post['asset_value_min'] ?? 0),
                'audit_loaded_only' => !empty($req->post['audit_loaded_only']),
            ]);
        }

        return Response::redirect('/admin/corptools?tab=' . urlencode($section));
    }, ['right' => 'corptools.admin']);

    $registry->route('POST', '/admin/corptools/rules', function (Request $req) use ($app): Response {
        $name = trim((string)($req->post['name'] ?? ''));
        $filtersRaw = trim((string)($req->post['filters'] ?? ''));
        if ($name !== '') {
            $filters = array_values(array_filter(array_map('trim', explode(',', $filtersRaw))));
            $app->db->run(
                "INSERT INTO module_corptools_notification_rules
                 (scope_type, scope_id, name, filters_json, is_enabled, created_at, updated_at)
                 VALUES ('global', 0, ?, ?, 1, NOW(), NOW())",
                [$name, json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
            );
        }
        return Response::redirect('/admin/corptools');
    }, ['right' => 'corptools.admin']);
};
