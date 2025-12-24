<?php
declare(strict_types=1);

/*
Module Name: Corp Tools
Description: Corporation dashboards inspired by CorpTools.
Version: 1.1.0
Module Slug: corptools
*/

use App\Core\App;
use App\Core\EsiCache;
use App\Core\EsiClient;
use App\Core\EsiDateTime;
use App\Core\EveSso;
use App\Core\HttpClient;
use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Settings;
use App\Core\Universe;
use App\Corptools\MemberQueryBuilder;
use App\Corptools\ScopePolicy;
use App\Corptools\Settings as CorpToolsSettings;
use App\Corptools\Audit\Dispatcher as AuditDispatcher;
use App\Corptools\Audit\Collectors\AssetsCollector;
use App\Corptools\Audit\Collectors\ClonesCollector;
use App\Corptools\Audit\Collectors\LocationCollector;
use App\Corptools\Audit\Collectors\RolesCollector;
use App\Corptools\Audit\Collectors\ShipCollector;
use App\Corptools\Audit\Collectors\SkillsCollector;
use App\Corptools\Audit\Collectors\SkillQueueCollector;
use App\Corptools\Audit\Collectors\WalletCollector;
use App\Corptools\Audit\SimpleCollector;
use App\Corptools\Audit\ScopeAuditService;
use App\Corptools\Cron\JobRegistry;
use App\Corptools\Cron\JobRunner;
use App\Http\Request;
use App\Http\Response;

return function (ModuleRegistry $registry): void {
    $app = $registry->app();
    $moduleVersion = '1.1.0';

    $registry->right('corptools.view', 'Access the CorpTools dashboards.');
    $registry->right('corptools.director', 'Access director-level corp dashboards.');
    $registry->right('corptools.admin', 'Manage CorpTools settings and integrations.');
    $registry->right('corptools.audit.read', 'View CorpTools audit data.');
    $registry->right('corptools.audit.write', 'Run CorpTools audit jobs.');
    $registry->right('corptools.member_audit', 'Manage member audit dashboards.');
    $registry->right('corptools.pinger.manage', 'Manage CorpTools pinger rules.');
    $registry->right('corptools.cron.manage', 'Manage CorpTools cron jobs.');

    $registry->menu([
        'slug' => 'corptools.member_tools',
        'title' => 'Member Tools',
        'url' => '/corptools',
        'sort_order' => 40,
        'area' => 'left',
        'right_slug' => 'corptools.view',
    ]);
    $registry->menu([
        'slug' => 'corptools',
        'title' => 'Dashboard',
        'url' => '/corptools',
        'sort_order' => 41,
        'area' => 'left',
        'parent_slug' => 'corptools.member_tools',
        'right_slug' => 'corptools.view',
    ]);
    $registry->menu([
        'slug' => 'corptools.characters',
        'title' => 'My Characters',
        'url' => '/corptools/characters',
        'sort_order' => 42,
        'area' => 'left',
        'parent_slug' => 'corptools.member_tools',
        'right_slug' => 'corptools.view',
    ]);
    $registry->menu([
        'slug' => 'corptools.audit',
        'title' => 'Character Audit',
        'url' => '/corptools/audit',
        'sort_order' => 43,
        'area' => 'left',
        'parent_slug' => 'corptools.member_tools',
        'right_slug' => 'corptools.audit.read',
    ]);
    $registry->menu([
        'slug' => 'corptools.overview',
        'title' => 'Overview',
        'url' => '/corptools/overview',
        'sort_order' => 44,
        'area' => 'left',
        'parent_slug' => 'corptools.member_tools',
        'right_slug' => 'corptools.view',
    ]);

    $registry->menu([
        'slug' => 'corptools.admin_tools',
        'title' => 'Admin / HR Tools',
        'url' => '/corptools/members',
        'sort_order' => 50,
        'area' => 'left',
        'right_slug' => 'corptools.director',
    ]);
    $registry->menu([
        'slug' => 'corptools.members',
        'title' => 'Members',
        'url' => '/corptools/members',
        'sort_order' => 51,
        'area' => 'left',
        'parent_slug' => 'corptools.admin_tools',
        'right_slug' => 'corptools.director',
    ]);
    $registry->menu([
        'slug' => 'corptools.corp_audit',
        'title' => 'Corp Audit',
        'url' => '/corptools/corp-audit',
        'sort_order' => 52,
        'area' => 'left',
        'parent_slug' => 'corptools.admin_tools',
        'right_slug' => 'corptools.director',
    ]);
    $registry->menu([
        'slug' => 'corptools.invoices',
        'title' => 'Invoices',
        'url' => '/corptools/invoices',
        'sort_order' => 53,
        'area' => 'left',
        'parent_slug' => 'corptools.admin_tools',
        'right_slug' => 'corptools.director',
    ]);
    $registry->menu([
        'slug' => 'corptools.moons',
        'title' => 'Moons',
        'url' => '/corptools/moons',
        'sort_order' => 54,
        'area' => 'left',
        'parent_slug' => 'corptools.admin_tools',
        'right_slug' => 'corptools.director',
    ]);
    $registry->menu([
        'slug' => 'corptools.industry',
        'title' => 'Industry',
        'url' => '/corptools/industry',
        'sort_order' => 55,
        'area' => 'left',
        'parent_slug' => 'corptools.admin_tools',
        'right_slug' => 'corptools.director',
    ]);
    $registry->menu([
        'slug' => 'corptools.notifications',
        'title' => 'Notifications',
        'url' => '/corptools/notifications',
        'sort_order' => 56,
        'area' => 'left',
        'parent_slug' => 'corptools.admin_tools',
        'right_slug' => 'corptools.director',
    ]);

    $registry->menu([
        'slug' => 'admin.corptools',
        'title' => 'Corp Tools',
        'url' => '/admin/corptools',
        'sort_order' => 45,
        'area' => 'admin_top',
        'right_slug' => 'corptools.admin',
    ]);
    $registry->menu([
        'slug' => 'admin.corptools.status',
        'title' => 'CorpTools Status',
        'url' => '/admin/corptools/status',
        'sort_order' => 46,
        'area' => 'admin_top',
        'right_slug' => 'corptools.admin',
    ]);
    $registry->menu([
        'slug' => 'admin.corptools.member_audit',
        'title' => 'Member Audit',
        'url' => '/admin/corptools/member-audit',
        'sort_order' => 47,
        'area' => 'admin_top',
        'right_slug' => 'corptools.member_audit',
    ]);
    $registry->menu([
        'slug' => 'admin.corptools.cron',
        'title' => 'CorpTools Cron',
        'url' => '/admin/corptools/cron',
        'sort_order' => 48,
        'area' => 'admin_top',
        'right_slug' => 'corptools.cron.manage',
    ]);

    $rights = new Rights($app->db);
    $hasRight = function (string $right) use ($rights): bool {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return false;
        return $rights->userHasRight($uid, $right);
    };

    $renderPage = function (string $title, string $bodyHtml) use ($app, $hasRight): string {
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

    $scopeCatalog = [
        'esi-assets.read_assets.v1' => 'Character assets and inventory.',
        'esi-clones.read_clones.v1' => 'Clone locations and jump clones.',
        'esi-clones.read_implants.v1' => 'Implant inventory.',
        'esi-contracts.read_character_contracts.v1' => 'Personal contracts.',
        'esi-characters.read_contacts.v1' => 'Contact list.',
        'esi-characters.read_corporation_roles.v1' => 'Corporation roles.',
        'esi-characters.read_titles.v1' => 'Corporation titles.',
        'esi-location.read_location.v1' => 'Current system location.',
        'esi-location.read_ship_type.v1' => 'Active ship type.',
        'esi-skills.read_skills.v1' => 'Skill sheet.',
        'esi-skills.read_skillqueue.v1' => 'Skill queue.',
        'esi-wallet.read_character_wallet.v1' => 'Wallet balance.',
        'esi-characters.read_loyalty.v1' => 'Loyalty points.',
        'esi-markets.read_character_orders.v1' => 'Market orders.',
        'esi-industry.read_character_mining.v1' => 'Mining ledger.',
        'esi-characters.read_notifications.v1' => 'Notifications feed.',
        'esi-characters.read_standings.v1' => 'Standings.',
        'esi-characters.read_statistics.v1' => 'Character statistics.',
    ];

    $renderPagination = function (int $total, int $page, int $perPage, string $base, array $query): string {
        $pages = (int)ceil($total / max(1, $perPage));
        if ($pages <= 1) {
            return '';
        }
        $page = max(1, min($page, $pages));
        $buildUrl = function (int $target) use ($base, $query): string {
            $query['page'] = $target;
            return $base . '?' . http_build_query($query);
        };
        $items = '';
        $prev = $page > 1 ? "<li class='page-item'><a class='page-link' href='" . htmlspecialchars($buildUrl($page - 1)) . "'>Prev</a></li>" : "<li class='page-item disabled'><span class='page-link'>Prev</span></li>";
        $next = $page < $pages ? "<li class='page-item'><a class='page-link' href='" . htmlspecialchars($buildUrl($page + 1)) . "'>Next</a></li>" : "<li class='page-item disabled'><span class='page-link'>Next</span></li>";

        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $page ? 'active' : '';
            $items .= "<li class='page-item {$active}'><a class='page-link' href='" . htmlspecialchars($buildUrl($i)) . "'>{$i}</a></li>";
        }

        return "<nav class='mt-3'><ul class='pagination pagination-sm'>{$prev}{$items}{$next}</ul></nav>";
    };

    $csrfToken = function (string $key): string {
        $token = bin2hex(random_bytes(16));
        $_SESSION['csrf_tokens'][$key] = $token;
        return $token;
    };

    $csrfCheck = function (string $key, ?string $token): bool {
        $stored = $_SESSION['csrf_tokens'][$key] ?? null;
        unset($_SESSION['csrf_tokens'][$key]);
        return is_string($stored) && is_string($token) && hash_equals($stored, $token);
    };

    $parseEsiDatetimeToMysql = function (?string $value): ?string {
        return EsiDateTime::parseEsiDatetimeToMysql($value);
    };

    $corptoolsSettings = new CorpToolsSettings($app->db);
    $universeShared = new Universe($app->db);
    $scopePolicy = new ScopePolicy($app->db, $universeShared);
    $scopeAudit = new ScopeAuditService($app->db);
    $sso = new EveSso($app->db, $app->config['eve_sso'] ?? []);
    $getCorpToolsSettings = function () use ($corptoolsSettings): array {
        return $corptoolsSettings->get();
    };

    $getEffectiveScopesForUser = function (int $userId) use ($scopePolicy): array {
        return $scopePolicy->getEffectiveScopesForUser($userId);
    };

    $getDefaultPolicy = function () use ($scopePolicy): ?array {
        return $scopePolicy->getDefaultPolicy();
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
        $expiresAt = null;
        if ($row) {
            $accessToken = (string)($row['access_token'] ?? '');
            $scopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
            if (!is_array($scopes)) $scopes = [];
            $expiresAt = $row['expires_at'] ? (string)$row['expires_at'] : null;
            $expiresAtTs = $expiresAt ? strtotime($expiresAt) : null;
            if ($expiresAtTs !== null && time() > $expiresAtTs) {
                $expired = true;
            }
        }
        return ['access_token' => $accessToken, 'scopes' => $scopes, 'expired' => $expired, 'expires_at' => $expiresAt];
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
            $profiles[$corpId] = [
                'id' => $corpId,
                'name' => $name,
                'label' => $label,
                'icons' => $icons,
            ];
        }

        return $profiles;
    };

    $getIdentityCorpIds = function () use ($app): array {
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

    $getAvailableCorpIds = function () use ($getCorpToolsSettings, $getIdentityCorpIds): array {
        $settings = $getCorpToolsSettings();
        $corpIds = $settings['general']['corp_ids'] ?? [];
        if (is_array($corpIds) && !empty($corpIds)) {
            return array_values(array_filter(array_map('intval', $corpIds), fn(int $id) => $id > 0));
        }
        return $getIdentityCorpIds();
    };

    $getCorpToken = function (int $corpId, array $requiredScopes) use ($app, $hasScopes, $sso, $scopeAudit): array {
        $rows = $app->db->all(
            "SELECT user_id, character_id, access_token, refresh_token, scopes_json, expires_at
             FROM eve_tokens"
        );
        $u = new Universe($app->db);

        foreach ($rows as $row) {
            $characterId = (int)($row['character_id'] ?? 0);
            if ($characterId <= 0) continue;

            $userId = (int)($row['user_id'] ?? 0);
            $accessToken = (string)($row['access_token'] ?? '');
            $refreshToken = (string)($row['refresh_token'] ?? '');
            $scopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
            if (!is_array($scopes)) $scopes = [];
            $expiresAt = $row['expires_at'] ? strtotime((string)$row['expires_at']) : null;
            $expired = $expiresAt !== null && time() > $expiresAt;

            if (($expired || $accessToken === '') && $refreshToken !== '') {
                $refresh = $sso->refreshTokenForCharacter($userId, $characterId, $refreshToken);
                if (($refresh['status'] ?? '') === 'success') {
                    $accessToken = (string)($refresh['token']['access_token'] ?? '');
                    $refreshToken = (string)($refresh['token']['refresh_token'] ?? $refreshToken);
                    $scopes = $refresh['scopes'] ?? [];
                    $expired = false;
                    $scopeAudit->logEvent('token_refresh', $userId, $characterId, [
                        'status' => 'success',
                        'context' => 'corp_token',
                    ]);
                } else {
                    $scopeAudit->logEvent('token_refresh', $userId, $characterId, [
                        'status' => 'failed',
                        'context' => 'corp_token',
                        'message' => $refresh['message'] ?? 'Refresh failed.',
                    ]);
                    $expired = true;
                }
            }

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

    $corpContext = function () use ($getAvailableCorpIds, $getCorpProfiles, $getCorpToolsSettings, $hasRight): array {
        $settings = $getCorpToolsSettings();
        $corpIds = $getAvailableCorpIds();
        $profiles = $getCorpProfiles($corpIds);

        $configuredId = (int)($settings['general']['corp_context_id'] ?? 0);
        $allowSwitch = !empty($settings['general']['allow_context_switch']);
        $selectedId = $configuredId;
        $isConfigured = $configuredId > 0 && isset($profiles[$configuredId]);

        if ($allowSwitch && $hasRight('corptools.director')) {
            $overrideId = (int)($_SESSION['corptools_corp_context_id'] ?? 0);
            if ($overrideId > 0 && isset($profiles[$overrideId])) {
                $selectedId = $overrideId;
            }
        }

        if ($selectedId <= 0 || !isset($profiles[$selectedId])) {
            $selectedId = !empty($profiles) ? (int)array_key_first($profiles) : 0;
        }

        return [
            'profiles' => $profiles,
            'selected' => $selectedId > 0 ? $profiles[$selectedId] : null,
            'configured_id' => $configuredId,
            'is_configured' => $isConfigured,
            'allow_switch' => $allowSwitch,
            'is_override' => $allowSwitch && $hasRight('corptools.director') && $selectedId > 0 && $selectedId !== $configuredId,
        ];
    };

    $renderCorpContext = function (array $context, string $returnTo) use ($hasRight): string {
        $flash = $_SESSION['corptools_context_flash'] ?? null;
        unset($_SESSION['corptools_context_flash']);
        $flashHtml = '';
        if (is_array($flash)) {
            $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
            $message = htmlspecialchars((string)($flash['message'] ?? ''));
            if ($message !== '') {
                $flashHtml = "<div class='alert alert-{$type} mb-2'>{$message}</div>";
            }
        }

        $corp = $context['selected'] ?? null;
        if (!$corp) {
            return "{$flashHtml}<div class='alert alert-warning mt-3'>Corp data shown for: <strong>None configured</strong>. Set a CorpTools corp context in Admin → Corp Tools.</div>";
        }

        $label = htmlspecialchars((string)($corp['label'] ?? 'Corporation'));
        $contextNote = $context['is_override'] ? 'director override' : ($context['is_configured'] ? 'configured' : 'defaulted');
        $helper = $context['is_configured']
            ? "Corp data shown for: <strong>{$label}</strong> ({$contextNote})."
            : "Corp data shown for: <strong>{$label}</strong> ({$contextNote}). Configure the canonical corp context in Admin → Corp Tools.";

        $switchHtml = '';
        if (!empty($context['allow_switch']) && $hasRight('corptools.director')) {
            $options = '';
            foreach ($context['profiles'] as $profile) {
                $pid = (int)($profile['id'] ?? 0);
                $pLabel = htmlspecialchars((string)($profile['label'] ?? 'Corporation'));
                $selected = ($corp['id'] ?? 0) === $pid ? 'selected' : '';
                $options .= "<option value='{$pid}' {$selected}>{$pLabel}</option>";
            }
            $switchHtml = "<form method='post' action='/corptools/context-switch' class='mt-2'>
                <input type='hidden' name='return_to' value='" . htmlspecialchars($returnTo) . "'>
                <div class='row g-2 align-items-end'>
                  <div class='col-md-5'>
                    <label class='form-label'>Switch corp context</label>
                    <select class='form-select' name='corp_id'>{$options}</select>
                  </div>
                  <div class='col-md-4'>
                    <div class='form-check'>
                      <input class='form-check-input' type='checkbox' name='confirm' value='1' id='context-confirm'>
                      <label class='form-check-label' for='context-confirm'>I understand this changes corp dashboards for this session.</label>
                    </div>
                  </div>
                  <div class='col-md-3 d-flex gap-2'>
                    <button class='btn btn-outline-primary'>Apply</button>
                    <button class='btn btn-outline-secondary' name='reset' value='1'>Reset</button>
                  </div>
                </div>
              </form>";
        }

        return "{$flashHtml}<div class='card card-body mt-3'>
                  <div class='fw-semibold'>Corp Context</div>
                  <div class='text-muted'>{$helper}</div>
                  <div class='text-muted small'>Corp context is always configured in settings or explicitly selected by a director; it never follows the last logged-in character.</div>
                  {$switchHtml}
                </div>";
    };

    $auditCollectors = function (): array {
        return [
            new AssetsCollector(),
            new ClonesCollector(),
            new LocationCollector(),
            new ShipCollector(),
            new SkillsCollector(),
            new SkillQueueCollector(),
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

    $updateMemberSummary = function (int $userId, int $mainCharacterId, string $mainName) use ($app, $parseEsiDatetimeToMysql): void {
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
                        $corpJoinedAt = $parseEsiDatetimeToMysql($entry['start_date'] ?? null);
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

    $runInvoiceSync = function (App $app, array $context = []) use ($tokenData, $getAvailableCorpIds, $getCorpToolsSettings, $parseEsiDatetimeToMysql, $scopeAudit): array {
        $settings = $getCorpToolsSettings();
        if (empty($settings['invoices']['enabled'])) {
            return ['message' => 'Invoices disabled'];
        }
        $corpIds = $getAvailableCorpIds();
        if (empty($corpIds)) return ['message' => 'No corp ids'];

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

                $response = $cache->getCachedAuthWithStatus(
                    "corptools:wallet:{$corpId}:{$division}",
                    "GET /latest/corporations/{$corpId}/wallets/{$division}/journal/",
                    300,
                    (string)$token['access_token'],
                    [403, 404]
                );
                if (($response['status'] ?? 200) >= 400) {
                    $scopeAudit->logEvent('esi_error', null, $characterId, [
                        'job' => 'invoice_sync',
                        'corp_id' => $corpId,
                        'division' => $division,
                        'status' => $response['status'] ?? null,
                    ]);
                }
                $entries = $response['data'] ?? [];

                if (!is_array($entries)) continue;

                foreach ($entries as $entry) {
                    if (!is_array($entry)) continue;
                    $journalId = (int)($entry['id'] ?? 0);
                    if ($journalId <= 0) continue;
                    $amount = (float)($entry['amount'] ?? 0);
                    $balance = (float)($entry['balance'] ?? 0);
                    $refType = (string)($entry['ref_type'] ?? '');
                    $date = $parseEsiDatetimeToMysql($entry['date'] ?? null) ?? date('Y-m-d H:i:s');
                    $firstParty = (int)($entry['first_party_id'] ?? 0);
                    $secondParty = (int)($entry['second_party_id'] ?? 0);
                    $reason = (string)($entry['reason'] ?? '');

                    try {
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
                    } catch (\Throwable $e) {
                        $scopeAudit->logEvent('db_error', null, $characterId, [
                            'job' => 'invoice_sync',
                            'journal_id' => $journalId,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
        return ['message' => 'Invoice sync complete'];
    };

    $runAuditRefresh = function (App $app, array $context = []) use (
        $auditCollectors,
        $enabledAuditKeys,
        $updateMemberSummary,
        $getCorpToolsSettings,
        $scopePolicy,
        $scopeAudit,
        $sso
    ): array {
        $settings = $getCorpToolsSettings();
        $enabledKeys = $enabledAuditKeys($settings);
        if (empty($enabledKeys)) return ['message' => 'Audit scopes disabled'];

        $dispatcher = new AuditDispatcher($app->db);
        $universe = new Universe($app->db);

        $batchSize = 50;
        $offset = 0;
        $now = time();
        $refreshThreshold = 300;
        $throttleMs = (int)($context['throttle_ms'] ?? 0);
        $logLines = [];
        $metrics = [
            'users_processed' => 0,
            'characters_processed' => 0,
            'token_refreshed' => 0,
            'token_refresh_failed' => 0,
            'audits_run' => 0,
            'audits_failed' => 0,
        ];

        $normalizeToken = function (?array $row) use ($now): array {
            if (!$row) {
                return ['access_token' => null, 'scopes' => [], 'expired' => true, 'expires_at' => null, 'refresh_token' => null];
            }
            $scopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
            if (!is_array($scopes)) $scopes = [];
            $expiresAt = $row['expires_at'] ? (string)$row['expires_at'] : null;
            $expired = false;
            if ($expiresAt) {
                $expiresAtTs = strtotime($expiresAt);
                if ($expiresAtTs !== false && $now > $expiresAtTs) {
                    $expired = true;
                }
            } else {
                $expired = true;
            }
            return [
                'access_token' => (string)($row['access_token'] ?? ''),
                'refresh_token' => (string)($row['refresh_token'] ?? ''),
                'scopes' => $scopes,
                'expired' => $expired,
                'expires_at' => $expiresAt,
            ];
        };

        while (true) {
            $users = $app->db->all(
                "SELECT id, character_id, character_name
                 FROM eve_users
                 ORDER BY id ASC
                 LIMIT ? OFFSET ?",
                [$batchSize, $offset]
            );
            if (empty($users)) {
                break;
            }

            $offset += $batchSize;
            $userIds = array_values(array_filter(array_map(fn($row) => (int)($row['id'] ?? 0), $users)));
            if (empty($userIds)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $memberRows = $app->db->all(
                "SELECT user_id, corp_id, alliance_id
                 FROM module_corptools_member_summary
                 WHERE user_id IN ({$placeholders})",
                $userIds
            );
            $memberMap = [];
            foreach ($memberRows as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                if ($uid <= 0) continue;
                $memberMap[$uid] = [
                    'corp_id' => (int)($row['corp_id'] ?? 0),
                    'alliance_id' => (int)($row['alliance_id'] ?? 0),
                ];
            }

            $links = $app->db->all(
                "SELECT user_id, character_id, character_name
                 FROM character_links
                 WHERE status='linked' AND user_id IN ({$placeholders})",
                $userIds
            );
            $linksByUser = [];
            foreach ($links as $link) {
                $linksByUser[(int)$link['user_id']][] = [
                    'character_id' => (int)($link['character_id'] ?? 0),
                    'character_name' => (string)($link['character_name'] ?? ''),
                ];
            }

            $characterIds = [];
            foreach ($users as $user) {
                $mainCharacterId = (int)($user['character_id'] ?? 0);
                if ($mainCharacterId > 0) {
                    $characterIds[$mainCharacterId] = true;
                }
                foreach ($linksByUser[(int)($user['id'] ?? 0)] ?? [] as $link) {
                    $cid = (int)($link['character_id'] ?? 0);
                    if ($cid > 0) {
                        $characterIds[$cid] = true;
                    }
                }
            }

            $tokenRows = [];
            if (!empty($characterIds)) {
                $charIds = array_keys($characterIds);
                $charPlaceholders = implode(',', array_fill(0, count($charIds), '?'));
                $tokenRows = $app->db->all(
                    "SELECT user_id, character_id, access_token, refresh_token, scopes_json, expires_at
                     FROM eve_tokens
                     WHERE character_id IN ({$charPlaceholders})",
                    $charIds
                );
            }

            $tokensByCharacter = [];
            foreach ($tokenRows as $row) {
                $cid = (int)($row['character_id'] ?? 0);
                if ($cid > 0) {
                    $tokensByCharacter[$cid] = $row;
                }
            }

            foreach ($users as $user) {
                $userId = (int)($user['id'] ?? 0);
                $mainCharacterId = (int)($user['character_id'] ?? 0);
                $mainName = (string)($user['character_name'] ?? '');
                if ($userId <= 0 || $mainCharacterId <= 0) continue;

                $metrics['users_processed']++;

                $characters = array_merge(
                    [['character_id' => $mainCharacterId, 'character_name' => $mainName]],
                    $linksByUser[$userId] ?? []
                );

                $corpId = (int)($memberMap[$userId]['corp_id'] ?? 0);
                $allianceId = (int)($memberMap[$userId]['alliance_id'] ?? 0);
                if ($corpId <= 0 && $mainCharacterId > 0) {
                    $profile = $universe->characterProfile($mainCharacterId);
                    $corpId = (int)($profile['corporation']['id'] ?? 0);
                    $allianceId = (int)($profile['alliance']['id'] ?? 0);
                }

                $scopeSet = $scopePolicy->getEffectiveScopesForContext($userId, $corpId, $allianceId);
                $requiredScopes = $scopeSet['required'] ?? [];
                $optionalScopes = $scopeSet['optional'] ?? [];
                $policyId = $scopeSet['policy']['id'] ?? null;

                foreach ($characters as $character) {
                    $characterId = (int)($character['character_id'] ?? 0);
                    if ($characterId <= 0) continue;

                    $metrics['characters_processed']++;
                    $token = $normalizeToken($tokensByCharacter[$characterId] ?? null);
                    $expiresAtTs = $token['expires_at'] ? strtotime((string)$token['expires_at']) : null;
                    $needsRefresh = empty($token['access_token']) || ($expiresAtTs !== null && ($now + $refreshThreshold) >= $expiresAtTs);

                    if ($needsRefresh && !empty($token['refresh_token'])) {
                        $refresh = $sso->refreshTokenForCharacter($userId, $characterId, (string)$token['refresh_token']);
                        if (($refresh['status'] ?? '') === 'success') {
                            $metrics['token_refreshed']++;
                            $token['access_token'] = (string)($refresh['token']['access_token'] ?? '');
                            $token['refresh_token'] = (string)($refresh['token']['refresh_token'] ?? $token['refresh_token']);
                            $token['scopes'] = $refresh['scopes'] ?? [];
                            $token['expires_at'] = $refresh['expires_at'] ?? null;
                            $token['expired'] = false;
                            $scopeAudit->logEvent('token_refresh', $userId, $characterId, [
                                'status' => 'success',
                                'expires_at' => $token['expires_at'],
                            ]);
                        } else {
                            $metrics['token_refresh_failed']++;
                            $token['refresh_failed'] = true;
                            $token['refresh_error'] = (string)($refresh['message'] ?? 'Refresh failed.');
                            $token['expired'] = true;
                            $scopeAudit->logEvent('token_refresh', $userId, $characterId, [
                                'status' => 'failed',
                                'message' => $token['refresh_error'],
                            ]);
                        }
                    } elseif ($needsRefresh) {
                        $metrics['token_refresh_failed']++;
                        $token['refresh_failed'] = true;
                        $token['refresh_error'] = 'No refresh token available.';
                        $token['expired'] = true;
                        $scopeAudit->logEvent('token_refresh', $userId, $characterId, [
                            'status' => 'failed',
                            'message' => $token['refresh_error'],
                        ]);
                    }

                    $profile = $universe->characterProfile($characterId);
                    $characterName = (string)($profile['character']['name'] ?? ($character['character_name'] ?? 'Unknown'));
                    $baseSummary = [
                        'corp_id' => (int)($profile['corporation']['id'] ?? 0),
                        'alliance_id' => (int)($profile['alliance']['id'] ?? 0),
                        'is_main' => $characterId === $mainCharacterId ? 1 : 0,
                    ];

                    try {
                        $dispatcher->run(
                            $userId,
                            $characterId,
                            $characterName,
                            $token,
                            $auditCollectors(),
                            $enabledKeys,
                            $baseSummary,
                            $requiredScopes,
                            $optionalScopes,
                            is_numeric($policyId) ? (int)$policyId : null,
                            $scopeAudit
                        );
                        $metrics['audits_run']++;
                    } catch (\Throwable $e) {
                        $metrics['audits_failed']++;
                        $scopeAudit->logEvent('db_error', $userId, $characterId, [
                            'job' => 'audit_refresh',
                            'message' => $e->getMessage(),
                        ]);
                        $logLines[] = "Audit failed for {$characterName}: " . $e->getMessage();
                    }
                    if ($throttleMs > 0) {
                        usleep($throttleMs * 1000);
                    }
                }

                $updateMemberSummary($userId, $mainCharacterId, $mainName);
            }
        }

        $logLines[] = "Processed {$metrics['users_processed']} users, {$metrics['characters_processed']} characters.";
        $logLines[] = "Audits run: {$metrics['audits_run']}, failed: {$metrics['audits_failed']}.";
        $logLines[] = "Token refreshes: {$metrics['token_refreshed']}, failures: {$metrics['token_refresh_failed']}.";

        return [
            'message' => 'Audit refresh complete',
            'metrics' => $metrics,
            'log_lines' => array_slice($logLines, -20),
        ];
    };

    $runCorpAuditRefresh = function (App $app, array $context = []) use ($getAvailableCorpIds, $getCorpToken, $getCorpToolsSettings, $parseEsiDatetimeToMysql, $scopeAudit): array {
        $settings = $getCorpToolsSettings();
        $enabled = $settings['corp_audit'] ?? [];
        if (empty(array_filter($enabled, fn($val) => !empty($val)))) {
            return ['message' => 'Corp audit disabled'];
        }
        $corpIds = $getAvailableCorpIds();
        if (empty($corpIds)) return ['message' => 'No corp ids'];

        $client = new EsiClient(new HttpClient());
        $cache = new EsiCache($app->db, $client);
        $universe = new Universe($app->db);

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
                $response = $cache->getCachedAuthWithStatus(
                    "corptools:corp:{$corpId}",
                    "GET {$endpoint}",
                    (int)$cfg['ttl'],
                    (string)$token['access_token'],
                    [403, 404]
                );
                if (($response['status'] ?? 200) >= 400) {
                    $scopeAudit->logEvent('esi_error', null, $token['character_id'] ?? null, [
                        'job' => 'corp_audit_refresh',
                        'corp_id' => $corpId,
                        'endpoint' => $endpoint,
                        'status' => $response['status'] ?? null,
                    ]);
                }
                $payload = $response['data'] ?? [];

                try {
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
                    $app->db->run(
                        "INSERT INTO module_corptools_corp_audit_snapshots (corp_id, category, data_json, fetched_at)
                         VALUES (?, ?, ?, NOW())",
                        [
                            $corpId,
                            $key,
                            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ]
                    );
                } catch (\Throwable $e) {
                    $scopeAudit->logEvent('db_error', null, $token['character_id'] ?? null, [
                        'job' => 'corp_audit_refresh',
                        'corp_id' => $corpId,
                        'category' => $key,
                        'message' => $e->getMessage(),
                    ]);
                }

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

                        $fuelExpires = $parseEsiDatetimeToMysql($structure['fuel_expires'] ?? null);
                        try {
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
                                    $fuelExpires,
                                    (string)($structure['state'] ?? ''),
                                ]
                            );
                        } catch (\Throwable $e) {
                            $scopeAudit->logEvent('db_error', null, $token['character_id'] ?? null, [
                                'job' => 'corp_audit_refresh',
                                'corp_id' => $corpId,
                                'structure_id' => $structureId,
                                'message' => $e->getMessage(),
                            ]);
                        }
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
                $app->db->run(
                    "INSERT INTO module_corptools_corp_audit_snapshots (corp_id, category, data_json, fetched_at)
                     VALUES (?, 'metenox', ?, NOW())",
                    [$corpId, json_encode(['status' => 'scaffolded'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
                );
            }
        }
        return ['message' => 'Corp audit refresh complete'];
    };

    $runCleanup = function (App $app, array $context = []) use ($getCorpToolsSettings): array {
        $settings = $getCorpToolsSettings();
        $retentionDays = (int)($settings['general']['retention_days'] ?? 30);
        if ($retentionDays <= 0) return ['message' => 'Retention disabled'];
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
        $app->db->run(
            "DELETE FROM module_corptools_character_audit_snapshots WHERE fetched_at < ?",
            [$cutoff]
        );
        $app->db->run(
            "DELETE FROM module_corptools_corp_audit_snapshots WHERE fetched_at < ?",
            [$cutoff]
        );
        $app->db->run(
            "DELETE FROM module_corptools_job_runs WHERE started_at < ?",
            [$cutoff]
        );
        return ['message' => 'Cleanup complete'];
    };

    $jobDefinitions = [
        [
            'key' => 'corptools.invoice_sync',
            'name' => 'Invoice Sync',
            'description' => 'Pull wallet journal entries for invoice tracking.',
            'schedule' => 900,
            'handler' => $runInvoiceSync,
        ],
        [
            'key' => 'corptools.audit_refresh',
            'name' => 'Character Audit Refresh',
            'description' => 'Refresh character audit snapshots and summaries.',
            'schedule' => 3600,
            'handler' => $runAuditRefresh,
        ],
        [
            'key' => 'corptools.corp_audit_refresh',
            'name' => 'Corp Audit Refresh',
            'description' => 'Refresh corp audit snapshots for wallets/structures.',
            'schedule' => 3600,
            'handler' => $runCorpAuditRefresh,
        ],
        [
            'key' => 'corptools.cleanup',
            'name' => 'Retention Cleanup',
            'description' => 'Clean audit snapshots, pings, and run logs.',
            'schedule' => 86400,
            'handler' => $runCleanup,
        ],
    ];

    foreach ($jobDefinitions as $definition) {
        JobRegistry::register($definition);
    }
    JobRegistry::sync($app->db);

    $registry->cron('corptools_scheduler', 60, function (App $app) {
        JobRegistry::sync($app->db);
        $runner = new JobRunner($app->db, JobRegistry::definitionsByKey());
        $runner->runDueJobs($app, ['trigger' => 'scheduler']);
    });

    $registry->route('POST', '/corptools/context-switch', function (Request $req) use ($corpContext, $hasRight): Response {
        if (!$hasRight('corptools.director')) {
            return Response::redirect('/corptools');
        }

        $returnTo = (string)($req->post['return_to'] ?? '/corptools');
        if ($returnTo === '' || $returnTo[0] !== '/') {
            $returnTo = '/corptools';
        }

        if (!empty($req->post['reset'])) {
            unset($_SESSION['corptools_corp_context_id']);
            $_SESSION['corptools_context_flash'] = [
                'type' => 'info',
                'message' => 'Corp context reset to the configured default.',
            ];
            return Response::redirect($returnTo);
        }

        if (empty($req->post['confirm'])) {
            $_SESSION['corptools_context_flash'] = [
                'type' => 'warning',
                'message' => 'Confirm the context switch before applying.',
            ];
            return Response::redirect($returnTo);
        }

        $context = $corpContext();
        $corpId = (int)($req->post['corp_id'] ?? 0);
        if ($corpId > 0 && isset($context['profiles'][$corpId])) {
            $_SESSION['corptools_corp_context_id'] = $corpId;
            $_SESSION['corptools_context_flash'] = [
                'type' => 'success',
                'message' => 'Corp context updated for this session.',
            ];
        } else {
            $_SESSION['corptools_context_flash'] = [
                'type' => 'danger',
                'message' => 'Unable to switch: corp context is not available.',
            ];
        }

        return Response::redirect($returnTo);
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools', function () use ($app, $renderPage, $corpContext, $tokenData, $hasScopes, $formatIsk, $renderCorpContext, $getCorpToolsSettings): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        $contextPanel = $renderCorpContext($context, '/corptools');
        if (!$corp) {
            $body = "<h1>Corp Tools</h1>{$contextPanel}";
            return Response::html($renderPage('Corp Tools', $body), 200);
        }

        $corpLabel = htmlspecialchars((string)$corp['label']);
        $icon = $corp['icons']['px64x64'] ?? null;
        $token = $tokenData($cid);
        $settings = $getCorpToolsSettings();
        $auditEnabled = !empty(array_filter($settings['audit_scopes'] ?? [], fn($enabled) => !empty($enabled)));
        $corpAuditEnabled = !empty(array_filter($settings['corp_audit'] ?? [], fn($enabled) => !empty($enabled)));
        $invoiceEnabled = !empty($settings['invoices']['enabled']);
        $moonsEnabled = !empty($settings['moons']['enabled']);
        $indyEnabled = !empty($settings['indy']['enabled']);
        $pingerEnabled = !empty($settings['pinger']['enabled']);
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

        $tiles = [
            ['label' => 'Audit', 'desc' => 'Character audit snapshots', 'url' => '/corptools/audit', 'enabled' => $auditEnabled],
            ['label' => 'Corp Overview', 'desc' => 'At-a-glance KPIs and trends', 'url' => '/corptools/overview', 'enabled' => true],
            ['label' => 'Notifications/Pings', 'desc' => 'Recent pings and alerts', 'url' => '/corptools/notifications', 'enabled' => $pingerEnabled],
            ['label' => 'Invoices', 'desc' => 'Wallet journal invoice tracking', 'url' => '/corptools/invoices', 'enabled' => $invoiceEnabled],
            ['label' => 'Moons', 'desc' => 'Moon extraction tracking', 'url' => '/corptools/moons', 'enabled' => $moonsEnabled],
            ['label' => 'Indy Dash', 'desc' => 'Industry structures & services', 'url' => '/corptools/industry', 'enabled' => $indyEnabled],
            ['label' => 'Corp Audit', 'desc' => 'Corp wallet/structure snapshots', 'url' => '/corptools/corp-audit', 'enabled' => $corpAuditEnabled],
            ['label' => 'Members', 'desc' => 'Audit-driven filters', 'url' => '/corptools/members', 'enabled' => $corpAuditEnabled || $auditEnabled],
        ];

        $tilesHtml = '';
        foreach ($tiles as $tile) {
            if (empty($tile['enabled'])) {
                continue;
            }
            $tilesHtml .= "<div class='col-md-3'>
                <a class='card card-body h-100 text-decoration-none' href='" . htmlspecialchars($tile['url']) . "'>
                  <div class='fw-semibold'>" . htmlspecialchars($tile['label']) . "</div>
                  <div class='text-muted small mt-2'>" . htmlspecialchars($tile['desc']) . "</div>
                </a>
              </div>";
        }
        if ($tilesHtml === '') {
            $tilesHtml = "<div class='col-12 text-muted'>No CorpTools modules are enabled yet. Configure settings in Admin → Corp Tools.</div>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-3'>
                    <div class='d-flex align-items-center gap-3'>
                      {$iconHtml}
                      <div>
                        <h1 class='mb-1'>Corp Tools</h1>
                        <div class='text-muted'>{$corpLabel}</div>
                      </div>
                    </div>
                  </div>
                  {$contextPanel}
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
                  <div class='row g-3 mt-3'>{$tilesHtml}</div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>At a glance</div>
                    <div class='text-muted'>Pick a tile to drill into audits, corp overviews, or notifications.</div>
                  </div>";

        return Response::html($renderPage('Corp Tools', $body), 200);
    }, ['right' => 'corptools.audit.read']);

    $registry->route('GET', '/corptools/characters', function () use ($app, $renderPage, $renderPagination): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $search = trim((string)($_GET['q'] ?? ''));
        $statusFilter = trim((string)($_GET['status'] ?? ''));

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

        $auditState = function (?array $summary): array {
            $auditLoaded = (int)($summary['audit_loaded'] ?? 0);
            $lastAudit = (string)($summary['last_audit_at'] ?? '');
            if ($lastAudit === '') {
                return ['key' => 'missing', 'label' => 'Missing scopes', 'badge' => 'bg-warning', 'hint' => 'No audit data yet.'];
            }
            $lastAuditTs = strtotime($lastAudit) ?: 0;
            if ($lastAuditTs > 0 && $lastAuditTs < strtotime('-7 days')) {
                return ['key' => 'stale', 'label' => 'Stale', 'badge' => 'bg-secondary', 'hint' => 'Last audit is over 7 days old.'];
            }
            if ($auditLoaded === 0) {
                return ['key' => 'partial', 'label' => 'Partial', 'badge' => 'bg-info', 'hint' => 'Some scopes missing or pending.'];
            }
            return ['key' => 'ok', 'label' => 'OK', 'badge' => 'bg-success', 'hint' => 'Audit data is current.'];
        };

        $statusCounts = ['ok' => 0, 'stale' => 0, 'partial' => 0, 'missing' => 0];
        $filteredCards = [];

        foreach ($characters as $character) {
            $characterId = (int)($character['character_id'] ?? 0);
            if ($characterId <= 0) continue;
            $summary = $app->db->one(
                "SELECT audit_loaded, last_audit_at, total_sp, wallet_balance, assets_count
                 FROM module_corptools_character_summary WHERE character_id=? LIMIT 1",
                [$characterId]
            );
            $state = $auditState($summary);
            if (isset($statusCounts[$state['key']])) {
                $statusCounts[$state['key']]++;
            }

            $name = htmlspecialchars((string)($character['character_name'] ?? 'Unknown'));
            if ($search !== '' && stripos($name, $search) === false) {
                continue;
            }
            if ($statusFilter !== '' && $state['key'] !== $statusFilter) {
                continue;
            }

            $lastAudit = htmlspecialchars((string)($summary['last_audit_at'] ?? '—'));
            $sp = number_format((int)($summary['total_sp'] ?? 0));
            $wallet = number_format((float)($summary['wallet_balance'] ?? 0), 2);
            $assets = (int)($summary['assets_count'] ?? 0);
            $badge = $character['is_main'] ? "<span class='badge bg-primary ms-2'>Main</span>" : '';
            $auditBadge = "<span class='badge {$state['badge']}'>" . htmlspecialchars($state['label']) . "</span>";

            $card = "<div class='col-md-6'>
                <div class='card card-body h-100'>
                  <div class='d-flex justify-content-between align-items-start'>
                    <div>
                      <div class='fw-semibold'>{$name}{$badge}</div>
                      <div class='text-muted small'>Audit status: {$auditBadge}</div>
                      <div class='text-muted small'>{$state['hint']}</div>
                    </div>
                    <div class='text-muted small'>Last audit: {$lastAudit}</div>
                  </div>
                  <div class='row text-muted mt-3'>
                    <div class='col-4'>SP: {$sp}</div>
                    <div class='col-4'>Wallet: {$wallet}</div>
                    <div class='col-4'>Assets: {$assets}</div>
                  </div>
                  <div class='d-flex justify-content-between align-items-center mt-3'>
                    <div class='form-check'>
                      <input class='form-check-input' type='checkbox' name='character_ids[]' value='{$characterId}' id='char-{$characterId}'>
                      <label class='form-check-label small text-muted' for='char-{$characterId}'>Select</label>
                    </div>
                    <a class='btn btn-sm btn-outline-secondary' href='/user/alts'>Manage link</a>
                  </div>
                </div>
              </div>";

            $filteredCards[] = $card;
        }

        $totalCharacters = array_sum($statusCounts);
        $summaryCards = "<div class='row g-3 mt-3'>
            <div class='col-md-3'>
              <div class='card card-body'>
                <div class='text-muted small'>Total characters</div>
                <div class='fs-5 fw-semibold'>" . htmlspecialchars((string)$totalCharacters) . "</div>
              </div>
            </div>
            <div class='col-md-3'>
              <div class='card card-body'>
                <div class='text-muted small'>Audit OK</div>
                <div class='fs-5 fw-semibold'>" . htmlspecialchars((string)$statusCounts['ok']) . "</div>
              </div>
            </div>
            <div class='col-md-3'>
              <div class='card card-body'>
                <div class='text-muted small'>Stale audits</div>
                <div class='fs-5 fw-semibold'>" . htmlspecialchars((string)$statusCounts['stale']) . "</div>
              </div>
            </div>
            <div class='col-md-3'>
              <div class='card card-body'>
                <div class='text-muted small'>Missing/Partial</div>
                <div class='fs-5 fw-semibold'>" . htmlspecialchars((string)($statusCounts['missing'] + $statusCounts['partial'])) . "</div>
              </div>
            </div>
          </div>";

        $filterForm = "<form method='get' class='card card-body mt-3'>
            <div class='row g-2 align-items-end'>
              <div class='col-md-6'>
                <label class='form-label'>Search characters</label>
                <input class='form-control' name='q' value='" . htmlspecialchars($search) . "' placeholder='Search by name'>
              </div>
              <div class='col-md-4'>
                <label class='form-label'>Audit status</label>
                <select class='form-select' name='status'>
                  <option value=''>All statuses</option>
                  <option value='ok'" . ($statusFilter === 'ok' ? ' selected' : '') . ">OK</option>
                  <option value='stale'" . ($statusFilter === 'stale' ? ' selected' : '') . ">Stale</option>
                  <option value='partial'" . ($statusFilter === 'partial' ? ' selected' : '') . ">Partial</option>
                  <option value='missing'" . ($statusFilter === 'missing' ? ' selected' : '') . ">Missing scopes</option>
                </select>
              </div>
              <div class='col-md-2 d-flex gap-2'>
                <button class='btn btn-primary'>Filter</button>
                <a class='btn btn-outline-secondary' href='/corptools/characters'>Reset</a>
              </div>
            </div>
          </form>";

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 12;
        $totalFiltered = count($filteredCards);
        $pageCards = array_slice($filteredCards, ($page - 1) * $perPage, $perPage);
        $cardsHtml = $pageCards ? implode('', $pageCards) : "<div class='col-12 text-muted'>No characters matched the filters.</div>";
        $pagination = $renderPagination(
            $totalFiltered,
            $page,
            $perPage,
            '/corptools/characters',
            array_filter(['q' => $search, 'status' => $statusFilter])
        );

        $bulkActions = "<div class='d-flex flex-wrap gap-2 mt-3'>
            <button class='btn btn-outline-primary'>Refresh audit for selected</button>
            <a class='btn btn-outline-secondary' href='/user/alts'>Manage main/linked characters</a>
          </div>";

        $flash = $_SESSION['corptools_characters_flash'] ?? null;
        unset($_SESSION['corptools_characters_flash']);
        $flashHtml = '';
        if (is_array($flash)) {
            $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
            $message = htmlspecialchars((string)($flash['message'] ?? ''));
            if ($message !== '') {
                $flashHtml = "<div class='alert alert-{$type} mt-3'>{$message}</div>";
            }
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>My Characters</h1>
                      <div class='text-muted'>Audit coverage across your linked characters. Main character is defined explicitly and is not the same as your last logged-in character.</div>
                    </div>
                  </div>
                  {$flashHtml}
                  {$filterForm}
                  {$summaryCards}
                  <form method='post' action='/corptools/characters/audit'>
                    {$bulkActions}
                    <div class='mt-4'>
                      <div class='fw-semibold mb-1'>Characters</div>
                      <div class='text-muted small'>Audit state reflects collected data, not just token presence.</div>
                    </div>
                    <div class='row g-3 mt-2'>{$cardsHtml}</div>
                    {$pagination}
                  </form>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Audit cadence</div>
                    <div class='text-muted'>Audits refresh hourly through the CorpTools cron jobs. Missing scopes will show as partial or missing, and stale audits should be refreshed.</div>
                  </div>";

        return Response::html($renderPage('My Characters', $body), 200);
    }, ['right' => 'corptools.view']);

    $registry->route('POST', '/corptools/characters/audit', function (Request $req) use ($app, $auditCollectors, $getCorpToolsSettings, $tokenData, $getEffectiveScopesForUser, $scopeAudit): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $selected = $req->post['character_ids'] ?? [];
        if (!is_array($selected)) $selected = [];
        $selectedIds = array_values(array_filter(array_map('intval', $selected), fn(int $id) => $id > 0));

        if (empty($selectedIds)) {
            $_SESSION['corptools_characters_flash'] = [
                'type' => 'warning',
                'message' => 'Select at least one character to refresh.',
            ];
            return Response::redirect('/corptools/characters');
        }

        $primary = $app->db->one("SELECT character_id, character_name FROM eve_users WHERE id=? LIMIT 1", [$uid]);
        $mainId = (int)($primary['character_id'] ?? 0);
        $owned = [];
        if ($mainId > 0) {
            $owned[$mainId] = (string)($primary['character_name'] ?? 'Unknown');
        }
        $links = $app->db->all(
            "SELECT character_id, character_name FROM character_links WHERE user_id=? AND status='linked'",
            [$uid]
        );
        foreach ($links as $link) {
            $charId = (int)($link['character_id'] ?? 0);
            if ($charId > 0) {
                $owned[$charId] = (string)($link['character_name'] ?? 'Unknown');
            }
        }

        $dispatch = new AuditDispatcher($app->db);
        $settings = $getCorpToolsSettings();
        $enabled = array_keys(array_filter($settings['audit_scopes'] ?? [], fn($enabled) => !empty($enabled)));
        $collectors = $auditCollectors();
        $u = new Universe($app->db);

        $success = 0;
        $skipped = 0;
        $missingTokens = 0;
        $scopeSet = $getEffectiveScopesForUser($uid);
        $requiredScopes = $scopeSet['required'] ?? [];
        $optionalScopes = $scopeSet['optional'] ?? [];
        $policyId = $scopeSet['policy']['id'] ?? null;

        foreach ($selectedIds as $characterId) {
            if (!isset($owned[$characterId])) {
                $skipped++;
                continue;
            }

            $token = $tokenData($characterId);
            $tokenMissing = $token['expired'] || empty($token['access_token']);
            if ($tokenMissing) {
                $missingTokens++;
            }

            $profile = $u->characterProfile($characterId);
            $baseSummary = [
                'is_main' => $characterId === $mainId ? 1 : 0,
                'corp_id' => (int)($profile['corporation']['id'] ?? 0),
                'alliance_id' => (int)($profile['alliance']['id'] ?? 0),
            ];

            $dispatch->run(
                $uid,
                $characterId,
                $owned[$characterId],
                $token,
                $collectors,
                $enabled,
                $baseSummary,
                $requiredScopes,
                $optionalScopes,
                is_numeric($policyId) ? (int)$policyId : null,
                $scopeAudit
            );
            if (!$tokenMissing) {
                $success++;
            }
        }

        $messages = [];
        if ($success > 0) {
            $messages[] = "Queued audits for {$success} character(s).";
        }
        if ($missingTokens > 0) {
            $messages[] = "{$missingTokens} character(s) are missing valid tokens.";
        }
        if ($skipped > 0) {
            $messages[] = "{$skipped} character(s) were skipped.";
        }
        if (empty($messages)) {
            $messages[] = 'No characters were refreshed.';
        }

        $_SESSION['corptools_characters_flash'] = [
            'type' => $success > 0 ? 'success' : 'warning',
            'message' => implode(' ', $messages),
        ];

        return Response::redirect('/corptools/characters');
    }, ['right' => 'corptools.audit.write']);

    $registry->route('GET', '/corptools/audit', function (Request $req) use ($app, $renderPage, $getCorpToolsSettings, $renderPagination, $hasRight): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $settings = $getCorpToolsSettings();
        $enabledScopes = array_keys(array_filter($settings['audit_scopes'] ?? [], fn($enabled) => !empty($enabled)));
        $tabConfig = [
            'location' => 'Location',
            'ship' => 'Active Ship',
            'wallet' => 'Wallet',
            'skill_queue' => 'Skill Queue',
        ];
        $tabs = array_values(array_filter(array_keys($tabConfig), fn(string $key) => in_array($key, $enabledScopes, true)));

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
                'character_name' => (string)($row['character_name'] ?? 'Unknown'),
                'is_main' => false,
            ], $links)
        );
        $allCharacters = $characters;

        $search = trim((string)($req->query['q'] ?? ''));
        $universe = new Universe($app->db);
        if ($search !== '') {
            $characters = array_values(array_filter($characters, fn($char) => stripos((string)$char['character_name'], $search) !== false));
        }

        $page = max(1, (int)($req->query['page'] ?? 1));
        $perPage = 25;
        $totalCharacters = count($characters);
        $pageCharacters = array_slice($characters, ($page - 1) * $perPage, $perPage);

        $selectedId = (int)($req->query['character_id'] ?? $mainId);
        $ownedIds = array_column($allCharacters, 'character_id');
        if ($selectedId <= 0 || !in_array($selectedId, $ownedIds, true)) {
            $selectedId = $mainId > 0 ? $mainId : (int)($ownedIds[0] ?? 0);
        }

        $activeTab = (string)($req->query['tab'] ?? ($tabs[0] ?? ''));
        if (!in_array($activeTab, $tabs, true)) {
            $activeTab = $tabs[0] ?? '';
        }

        $snapshots = [];
        if ($selectedId > 0) {
            $rows = $app->db->all(
                "SELECT s.category, s.data_json, s.fetched_at
                 FROM module_corptools_character_audit_snapshots s
                 JOIN (
                   SELECT category, MAX(fetched_at) AS max_fetched
                   FROM module_corptools_character_audit_snapshots
                   WHERE character_id=?
                   GROUP BY category
                 ) latest
                   ON s.category = latest.category AND s.fetched_at = latest.max_fetched
                 WHERE s.character_id=?",
                [$selectedId, $selectedId]
            );
            foreach ($rows as $row) {
                $snapshots[(string)$row['category']] = $row;
            }
        }

        $u = new Universe($app->db);

        $tabNav = '';
        if (!empty($tabs)) {
            $tabNav .= "<ul class='nav nav-tabs mt-3'>";
            foreach ($tabs as $tabKey) {
                $label = htmlspecialchars($tabConfig[$tabKey] ?? $tabKey);
                $active = $tabKey === $activeTab ? 'active' : '';
                $tabNav .= "<li class='nav-item'>
                    <a class='nav-link {$active}' href='/corptools/audit?character_id={$selectedId}&tab={$tabKey}'>" . $label . "</a>
                  </li>";
            }
            $tabNav .= "</ul>";
        }

        $content = "<div class='text-muted'>Enable audit scopes in admin to view details.</div>";
        if ($activeTab !== '' && isset($snapshots[$activeTab])) {
            $row = $snapshots[$activeTab];
            $payloads = json_decode((string)($row['data_json'] ?? '[]'), true);
            if (!is_array($payloads)) $payloads = [];
            $fetchedAt = htmlspecialchars((string)($row['fetched_at'] ?? '—'));

            if ($activeTab === 'location') {
                $location = $payloads[0] ?? [];
                $systemId = (int)($location['solar_system_id'] ?? 0);
                $systemName = $systemId > 0 ? htmlspecialchars($u->name('system', $systemId)) : 'Unknown';
                $content = "<div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Location Snapshot</div>
                    <div>System: {$systemName}</div>
                    <div class='text-muted small'>Fetched: {$fetchedAt}</div>
                  </div>";
            } elseif ($activeTab === 'ship') {
                $ship = $payloads[0] ?? [];
                $typeId = (int)($ship['ship_type_id'] ?? 0);
                $shipName = $typeId > 0 ? htmlspecialchars($u->name('type', $typeId)) : 'Unknown';
                $shipLabel = htmlspecialchars((string)($ship['ship_name'] ?? '—'));
                $content = "<div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Active Ship Snapshot</div>
                    <div>Ship type: {$shipName}</div>
                    <div>Ship name: {$shipLabel}</div>
                    <div class='text-muted small'>Fetched: {$fetchedAt}</div>
                  </div>";
            } elseif ($activeTab === 'wallet') {
                $balance = $payloads[0] ?? 0;
                $balanceText = htmlspecialchars(number_format((float)$balance, 2));
                $content = "<div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Wallet Snapshot</div>
                    <div>Balance: {$balanceText} ISK</div>
                    <div class='text-muted small'>Fetched: {$fetchedAt}</div>
                  </div>";
            } elseif ($activeTab === 'skill_queue') {
                $queue = $payloads[0] ?? [];
                if (!is_array($queue)) $queue = [];
                $rowsHtml = '';
                foreach (array_slice($queue, 0, 5) as $entry) {
                    if (!is_array($entry)) continue;
                    $skillId = (int)($entry['skill_id'] ?? 0);
                    $skillName = $skillId > 0 ? htmlspecialchars($u->name('type', $skillId)) : 'Unknown';
                    $finish = htmlspecialchars((string)($entry['finish_date'] ?? '—'));
                    $level = htmlspecialchars((string)($entry['finished_level'] ?? ''));
                    $rowsHtml .= "<tr><td>{$skillName}</td><td>{$level}</td><td>{$finish}</td></tr>";
                }
                if ($rowsHtml === '') {
                    $rowsHtml = "<tr><td colspan='3' class='text-muted'>No queue entries cached.</td></tr>";
                }
                $content = "<div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Skill Queue Snapshot</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead><tr><th>Skill</th><th>Level</th><th>Finish</th></tr></thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                    <div class='text-muted small'>Fetched: {$fetchedAt}</div>
                  </div>";
            }
        } elseif ($activeTab !== '') {
            $content = "<div class='card card-body mt-3 text-muted'>No audit snapshot for this category yet. Run the audit job to populate.</div>";
        }

        $characterRows = '';
        foreach ($pageCharacters as $char) {
            $charId = (int)($char['character_id'] ?? 0);
            if ($charId <= 0) continue;
            $charName = htmlspecialchars((string)($char['character_name'] ?? 'Unknown'));
            $badge = !empty($char['is_main']) ? "<span class='badge bg-primary ms-2'>Main</span>" : '';
            $selected = $charId === $selectedId ? "table-primary" : '';
            $characterRows .= "<tr class='{$selected}'>
                <td>{$charName}{$badge}</td>
                <td class='text-end'><a class='btn btn-sm btn-outline-light' href='/corptools/audit?character_id={$charId}&tab={$activeTab}'>Select</a></td>
              </tr>";
        }
        if ($characterRows === '') {
            $characterRows = "<tr><td colspan='2' class='text-muted'>No characters found.</td></tr>";
        }

        $pagination = $renderPagination(
            $totalCharacters,
            $page,
            $perPage,
            '/corptools/audit',
            array_filter([
                'q' => $search,
                'character_id' => $selectedId,
                'tab' => $activeTab,
            ], fn($val) => $val !== '' && $val !== null)
        );

        $auditAction = '';
        if ($hasRight('corptools.audit.write')) {
            $auditAction = "<form method='post' action='/corptools/characters/audit' class='mt-3'>
                <input type='hidden' name='character_ids[]' value='{$selectedId}'>
                <button class='btn btn-sm btn-outline-primary'>Refresh audit now</button>
              </form>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Audit</h1>
                      <div class='text-muted'>Select a character and review the enabled audit domains.</div>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <form method='get' class='row g-2 align-items-end'>
                      <div class='col-md-8'>
                        <label class='form-label'>Search characters</label>
                        <input class='form-control' name='q' value='" . htmlspecialchars($search) . "' placeholder='Search by name'>
                      </div>
                      <div class='col-md-4 d-flex gap-2'>
                        <button class='btn btn-primary'>Search</button>
                        <a class='btn btn-outline-secondary' href='/corptools/audit'>Reset</a>
                      </div>
                    </form>
                    <div class='table-responsive mt-3'>
                      <table class='table table-sm align-middle'>
                        <thead><tr><th>Character</th><th></th></tr></thead>
                        <tbody>{$characterRows}</tbody>
                      </table>
                    </div>
                    {$pagination}
                    {$auditAction}
                  </div>
                  {$tabNav}
                  {$content}";

        return Response::html($renderPage('Audit', $body), 200);
    }, ['right' => 'corptools.audit.read']);

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

    $registry->route('GET', '/corptools/invoices', function (Request $req) use ($app, $renderPage, $corpContext, $formatIsk, $getCorpToken, $renderCorpContext, $getCorpToolsSettings): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $settings = $getCorpToolsSettings();
        if (empty($settings['invoices']['enabled'])) {
            $body = "<h1>Invoices</h1><div class='alert alert-warning mt-3'>Invoices are disabled by administrators.</div>";
            return Response::html($renderPage('Invoices', $body), 200);
        }

        $context = $corpContext();
        $corp = $context['selected'];
        $contextPanel = $renderCorpContext($context, '/corptools/invoices');
        if (!$corp) {
            $body = "<h1>Invoices</h1>{$contextPanel}";
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
                  {$contextPanel}
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
                  </div>
                  {$pagination}";

        return Response::html($renderPage('Invoices', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools/members', function (Request $req) use ($app, $renderPage, $corpContext, $getCorpToolsSettings, $renderCorpContext, $renderPagination): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $context = $corpContext();
        $corp = $context['selected'];
        $contextPanel = $renderCorpContext($context, '/corptools/members');
        if (!$corp) {
            $body = "<h1>Members</h1>{$contextPanel}";
            return Response::html($renderPage('Members', $body), 200);
        }

        $settings = $getCorpToolsSettings();
        $defaultAssetMin = (string)($settings['filters']['asset_value_min'] ?? '');
        $defaultAuditOnly = !empty($settings['filters']['audit_loaded_only']) ? '1' : '';
        $queryParams = $req->query;
        unset($queryParams['page']);
        $useDefaults = empty(array_filter($queryParams, fn($val) => $val !== '' && $val !== null));
        $defaultAuditStatus = $useDefaults ? 'needs_attention' : (string)($req->query['audit_status'] ?? '');

        $resolveEntityId = function (string $type, string $value) use ($app): string {
            $value = trim($value);
            if ($value === '') return '';
            if (ctype_digit($value)) return $value;
            $row = $app->db->one(
                "SELECT entity_id FROM universe_entities WHERE entity_type=? AND name LIKE ? ORDER BY fetched_at DESC LIMIT 1",
                [$type, '%' . $value . '%']
            );
            return $row ? (string)($row['entity_id'] ?? '') : '';
        };

        $locationSystemInput = (string)($req->query['location_system_id'] ?? '');
        $shipTypeInput = (string)($req->query['ship_type_id'] ?? '');
        $nameInput = (string)($req->query['name'] ?? '');

        $filters = [
            'asset_presence' => (string)($req->query['asset_presence'] ?? ''),
            'name' => $nameInput,
            'corp_id' => (string)($corp['id'] ?? ''),
            'asset_value_min' => (string)($req->query['asset_value_min'] ?? $defaultAssetMin),
            'location_region_id' => (string)($req->query['location_region_id'] ?? ''),
            'location_system_id' => $resolveEntityId('system', $locationSystemInput),
            'ship_type_id' => $resolveEntityId('type', $shipTypeInput),
            'corp_role' => (string)($req->query['corp_role'] ?? ''),
            'corp_title' => (string)($req->query['corp_title'] ?? ''),
            'highest_sp_min' => (string)($req->query['highest_sp_min'] ?? ''),
            'last_login_since' => (string)($req->query['last_login_since'] ?? ''),
            'corp_joined_since' => (string)($req->query['corp_joined_since'] ?? ''),
            'home_station_id' => (string)($req->query['home_station_id'] ?? ''),
            'death_clone_location_id' => (string)($req->query['death_clone_location_id'] ?? ''),
            'jump_clone_location_id' => (string)($req->query['jump_clone_location_id'] ?? ''),
            'audit_loaded' => (string)($req->query['audit_loaded'] ?? ($useDefaults ? '' : $defaultAuditOnly)),
            'audit_status' => $defaultAuditStatus,
            'token_status' => (string)($req->query['token_status'] ?? ''),
            'missing_scopes' => (string)($req->query['missing_scopes'] ?? ''),
            'missing_scope' => (string)($req->query['missing_scope'] ?? ''),
            'last_audit_age' => (string)($req->query['last_audit_age'] ?? ''),
            'skill_id' => (string)($req->query['skill_id'] ?? ''),
            'asset_type_id' => (string)($req->query['asset_type_id'] ?? ''),
            'asset_group_id' => (string)($req->query['asset_group_id'] ?? ''),
            'asset_category_id' => (string)($req->query['asset_category_id'] ?? ''),
        ];

        $builder = new MemberQueryBuilder();
        $query = $builder->build($filters);
        $page = max(1, (int)($req->query['page'] ?? 1));
        $perPage = 50;
        $countRow = $app->db->one(
            "SELECT COUNT(*) AS total FROM (" . $query['sql'] . ") AS t",
            $query['params']
        );
        $totalRows = (int)($countRow['total'] ?? 0);
        $rows = $app->db->all(
            $query['sql'] . ' LIMIT ? OFFSET ?',
            array_merge($query['params'], [$perPage, ($page - 1) * $perPage])
        );

        $u = new Universe($app->db);
        $rowsHtml = '';
        foreach ($rows as $row) {
            $name = htmlspecialchars((string)($row['main_character_name'] ?? $row['character_name'] ?? 'Unknown'));
            $auditLoaded = ((int)($row['audit_loaded'] ?? 0) === 1) ? 'Loaded' : 'Missing';
            $systemId = (int)($row['location_system_id'] ?? 0);
            $systemName = $systemId > 0 ? htmlspecialchars($u->name('system', $systemId)) : '—';
            $shipTypeId = (int)($row['current_ship_type_id'] ?? 0);
            $shipName = $shipTypeId > 0 ? htmlspecialchars($u->name('type', $shipTypeId)) : '—';
            $title = htmlspecialchars((string)($row['corp_title'] ?? '—'));
            $lastAudit = htmlspecialchars((string)($row['last_audit_at'] ?? '—'));
            $lastLogin = htmlspecialchars((string)($row['last_login_at'] ?? '—'));
            $scopeStatus = (string)($row['scope_status'] ?? 'UNKNOWN');
            $scopeBadge = match ($scopeStatus) {
                'COMPLIANT' => 'bg-success',
                'MISSING_SCOPES' => 'bg-warning text-dark',
                'TOKEN_EXPIRED' => 'bg-secondary',
                'TOKEN_INVALID' => 'bg-danger',
                'TOKEN_REFRESH_FAILED' => 'bg-danger',
                default => 'bg-dark',
            };
            $tokenStatus = match ($scopeStatus) {
                'TOKEN_EXPIRED' => 'Expired',
                'TOKEN_INVALID' => 'Invalid',
                'TOKEN_REFRESH_FAILED' => 'Refresh failed',
                'UNKNOWN' => 'Unknown',
                default => 'Valid',
            };
            $missing = [];
            if (!empty($row['missing_scopes_json'])) {
                $missing = json_decode((string)$row['missing_scopes_json'], true);
                if (!is_array($missing)) $missing = [];
            }
            $missingText = $missing ? htmlspecialchars(implode(', ', $missing)) : '—';

            $rowsHtml .= "<tr>
                <td>
                  <div class='fw-semibold'>{$name}</div>
                  <div class='text-muted small'>Last login: {$lastLogin}</div>
                </td>
                <td>{$title}</td>
                <td><span class='badge {$scopeBadge}'>" . htmlspecialchars($scopeStatus) . "</span><div class='text-muted small'>{$auditLoaded}</div></td>
                <td>{$tokenStatus}</td>
                <td>{$missingText}</td>
                <td>{$lastAudit}</td>
                <td>{$systemName}</td>
                <td>{$shipName}</td>
              </tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='8' class='text-muted'>No members matched the filters yet.</td></tr>";
        }

        $pagination = $renderPagination(
            $totalRows,
            $page,
            $perPage,
            '/corptools/members',
            array_filter([
                'name' => $nameInput,
                'asset_presence' => $filters['asset_presence'],
                'asset_value_min' => $filters['asset_value_min'],
                'location_region_id' => $filters['location_region_id'],
                'location_system_id' => $locationSystemInput,
                'ship_type_id' => $shipTypeInput,
                'corp_role' => $filters['corp_role'],
                'corp_title' => $filters['corp_title'],
                'highest_sp_min' => $filters['highest_sp_min'],
                'audit_loaded' => $filters['audit_loaded'],
                'audit_status' => $filters['audit_status'],
                'token_status' => $filters['token_status'],
                'missing_scopes' => $filters['missing_scopes'],
                'missing_scope' => $filters['missing_scope'],
                'last_audit_age' => $filters['last_audit_age'],
                'skill_id' => $filters['skill_id'],
                'asset_type_id' => $filters['asset_type_id'],
                'asset_group_id' => $filters['asset_group_id'],
                'asset_category_id' => $filters['asset_category_id'],
                'last_login_since' => $filters['last_login_since'],
                'corp_joined_since' => $filters['corp_joined_since'],
            ], fn($val) => $val !== '' && $val !== null)
        );

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Members</h1>
                      <div class='text-muted'>Security filters driven by audit snapshots.</div>
                    </div>
                  </div>
                  {$contextPanel}
                  <form method='get' class='card card-body mt-3'>
                    <div class='row g-2'>
                      <div class='col-md-3'>
                        <label class='form-label'>Member name</label>
                        <input class='form-control' name='name' value='" . htmlspecialchars($nameInput) . "' placeholder='Search name'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Audit status</label>
                        <select class='form-select' name='audit_status'>
                          <option value=''>Any</option>
                          <option value='needs_attention'" . ($filters['audit_status'] === 'needs_attention' ? ' selected' : '') . ">Needs attention</option>
                          <option value='compliant'" . ($filters['audit_status'] === 'compliant' ? ' selected' : '') . ">Compliant</option>
                          <option value='missing_scopes'" . ($filters['audit_status'] === 'missing_scopes' ? ' selected' : '') . ">Missing scopes</option>
                          <option value='token_expired'" . ($filters['audit_status'] === 'token_expired' ? ' selected' : '') . ">Token expired</option>
                          <option value='token_invalid'" . ($filters['audit_status'] === 'token_invalid' ? ' selected' : '') . ">Token invalid</option>
                          <option value='token_refresh_failed'" . ($filters['audit_status'] === 'token_refresh_failed' ? ' selected' : '') . ">Token refresh failed</option>
                          <option value='audit_missing'" . ($filters['audit_status'] === 'audit_missing' ? ' selected' : '') . ">Audit missing</option>
                        </select>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Token status</label>
                        <select class='form-select' name='token_status'>
                          <option value=''>Any</option>
                          <option value='valid'" . ($filters['token_status'] === 'valid' ? ' selected' : '') . ">Valid</option>
                          <option value='expired'" . ($filters['token_status'] === 'expired' ? ' selected' : '') . ">Expired</option>
                          <option value='invalid'" . ($filters['token_status'] === 'invalid' ? ' selected' : '') . ">Invalid</option>
                          <option value='refresh_failed'" . ($filters['token_status'] === 'refresh_failed' ? ' selected' : '') . ">Refresh failed</option>
                        </select>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Missing scopes</label>
                        <select class='form-select' name='missing_scopes'>
                          <option value=''>Any</option>
                          <option value='1'" . ($filters['missing_scopes'] === '1' ? ' selected' : '') . ">Missing any</option>
                        </select>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Assets</label>
                        <select class='form-select' name='asset_presence'>
                          <option value=''>Any</option>
                          <option value='has'" . ($filters['asset_presence'] === 'has' ? ' selected' : '') . ">Has assets</option>
                          <option value='none'" . ($filters['asset_presence'] === 'none' ? ' selected' : '') . ">No assets</option>
                        </select>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Missing scope (exact)</label>
                        <input class='form-control' name='missing_scope' value='" . htmlspecialchars($filters['missing_scope']) . "' placeholder='esi-skills.read_skills.v1'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Last audit age</label>
                        <select class='form-select' name='last_audit_age'>
                          <option value=''>Any</option>
                          <option value='7d'" . ($filters['last_audit_age'] === '7d' ? ' selected' : '') . ">Last 7 days</option>
                          <option value='30d'" . ($filters['last_audit_age'] === '30d' ? ' selected' : '') . ">Last 30 days</option>
                          <option value='30plus'" . ($filters['last_audit_age'] === '30plus' ? ' selected' : '') . ">Older than 30 days</option>
                          <option value='never'" . ($filters['last_audit_age'] === 'never' ? ' selected' : '') . ">Never audited</option>
                        </select>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Asset value min (ISK)</label>
                        <input class='form-control' name='asset_value_min' value='" . htmlspecialchars($filters['asset_value_min']) . "' placeholder='0'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Location system (name or ID)</label>
                        <input class='form-control' name='location_system_id' value='" . htmlspecialchars($locationSystemInput) . "' placeholder='Jita or 30000142'>
                      </div>
                      <div class='col-md-3'>
                        <label class='form-label'>Ship type (name or ID)</label>
                        <input class='form-control' name='ship_type_id' value='" . htmlspecialchars($shipTypeInput) . "' placeholder='Hulk or 603'>
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
                            <th>Title</th>
                            <th>Audit status</th>
                            <th>Token</th>
                            <th>Missing scopes</th>
                            <th>Last audit</th>
                            <th>Location</th>
                            <th>Ship</th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>
                  {$pagination}";

        return Response::html($renderPage('Members', $body), 200);
    }, ['right' => 'corptools.director']);

    $registry->route('GET', '/corptools/moons', function () use ($app, $renderPage, $corpContext, $getCorpToolsSettings, $renderCorpContext): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $settings = $getCorpToolsSettings();
        if (empty($settings['moons']['enabled'])) {
            $body = "<h1>Moon Tracking</h1><div class='alert alert-warning mt-3'>Moon tracking is disabled by administrators.</div>";
            return Response::html($renderPage('Moon Tracking', $body), 200);
        }

        $context = $corpContext();
        $corp = $context['selected'];
        $contextPanel = $renderCorpContext($context, '/corptools/moons');
        if (!$corp) {
            $body = "<h1>Moon Tracking</h1>{$contextPanel}";
            return Response::html($renderPage('Moon Tracking', $body), 200);
        }

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
                  {$contextPanel}
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

        $settings = $getCorpToolsSettings();
        if (empty($settings['moons']['enabled'])) {
            return Response::redirect('/corptools/moons');
        }

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

    $registry->route('GET', '/corptools/industry', function () use ($app, $renderPage, $corpContext, $renderCorpContext, $getCorpToolsSettings): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $settings = $getCorpToolsSettings();
        if (empty($settings['indy']['enabled'])) {
            $body = "<h1>Industry</h1><div class='alert alert-warning mt-3'>Industry dashboards are disabled by administrators.</div>";
            return Response::html($renderPage('Industry', $body), 200);
        }

        $context = $corpContext();
        $corp = $context['selected'];
        $contextPanel = $renderCorpContext($context, '/corptools/industry');
        if (!$corp) {
            $body = "<h1>Industry</h1>{$contextPanel}";
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
                  {$contextPanel}
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

    $registry->route('GET', '/corptools/corp-audit', function () use ($app, $renderPage, $corpContext, $renderCorpContext, $getCorpToolsSettings): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $settings = $getCorpToolsSettings();
        if (empty(array_filter($settings['corp_audit'] ?? [], fn($enabled) => !empty($enabled)))) {
            $body = "<h1>Corp Audit</h1><div class='alert alert-warning mt-3'>Corp audit is disabled by administrators.</div>";
            return Response::html($renderPage('Corp Audit', $body), 200);
        }

        $context = $corpContext();
        $corp = $context['selected'];
        $contextPanel = $renderCorpContext($context, '/corptools/corp-audit');
        if (!$corp) {
            $body = "<h1>Corp Audit</h1>{$contextPanel}";
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
                  {$contextPanel}
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
        if (empty($settings['pinger']['enabled'])) {
            return Response::text("Pinger disabled\n", 503);
        }
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
            'corp_scope_policies',
            'corp_scope_policy_overrides',
            'module_corptools_character_scope_status',
            'module_corptools_audit_events',
            'module_corptools_character_audit',
            'module_corptools_character_audit_snapshots',
            'module_corptools_pings',
            'module_corptools_industry_structures',
            'module_corptools_corp_audit',
            'module_corptools_corp_audit_snapshots',
            'module_corptools_jobs',
            'module_corptools_job_runs',
            'module_corptools_job_locks',
        ];

        $rowsHtml = '';
        foreach ($tables as $table) {
            $exists = $app->db->one("SHOW TABLES LIKE " . $app->db->pdo()->quote($table));
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

    $registry->route('GET', '/admin/corptools/status', function () use ($app, $renderPage, $moduleVersion): Response {
        $tables = [
            'module_corptools_settings',
            'corp_scope_policies',
            'corp_scope_policy_overrides',
            'module_corptools_character_scope_status',
            'module_corptools_audit_events',
            'module_corptools_character_audit',
            'module_corptools_character_audit_snapshots',
            'module_corptools_corp_audit',
            'module_corptools_corp_audit_snapshots',
            'module_corptools_jobs',
            'module_corptools_job_runs',
        ];

        $rowsHtml = '';
        foreach ($tables as $table) {
            $exists = $app->db->one("SHOW TABLES LIKE " . $app->db->pdo()->quote($table));
            $status = $exists ? "<span class='badge bg-success'>OK</span>" : "<span class='badge bg-danger'>Missing</span>";
            $rowsHtml .= "<tr><td>{$table}</td><td>{$status}</td></tr>";
        }

        $jobRows = $app->db->all(
            "SELECT job_key, last_run_at, last_status, last_duration_ms
             FROM module_corptools_jobs
             ORDER BY job_key ASC"
        );
        $jobCards = '';
        foreach ($jobRows as $job) {
            $jobKey = htmlspecialchars((string)($job['job_key'] ?? ''));
            $lastRun = htmlspecialchars((string)($job['last_run_at'] ?? '—'));
            $status = htmlspecialchars((string)($job['last_status'] ?? '—'));
            $duration = htmlspecialchars((string)($job['last_duration_ms'] ?? 0));
            $jobCards .= "<div class='col-md-4'>
                <div class='card card-body'>
                  <div class='fw-semibold'>{$jobKey}</div>
                  <div class='text-muted small'>Last run: {$lastRun}</div>
                  <div class='text-muted small'>Status: {$status}</div>
                  <div class='text-muted small'>Duration: {$duration} ms</div>
                </div>
              </div>";
        }
        if ($jobCards === '') {
            $jobCards = "<div class='col-12 text-muted'>No jobs registered yet. Run the scheduler once.</div>";
        }

        $successRows = $app->db->all(
            "SELECT job_key, MAX(started_at) AS last_success
             FROM module_corptools_job_runs
             WHERE status='success'
             GROUP BY job_key"
        );
        $successMap = [];
        foreach ($successRows as $row) {
            $successMap[(string)($row['job_key'] ?? '')] = (string)($row['last_success'] ?? '—');
        }
        $successTable = '';
        foreach ($jobRows as $job) {
            $jobKey = (string)($job['job_key'] ?? '');
            $lastSuccess = htmlspecialchars($successMap[$jobKey] ?? '—');
            $successTable .= "<tr><td>" . htmlspecialchars($jobKey) . "</td><td>{$lastSuccess}</td></tr>";
        }
        if ($successTable === '') {
            $successTable = "<tr><td colspan='2' class='text-muted'>No successful runs yet.</td></tr>";
        }

        $queueDepthRow = $app->db->one(
            "SELECT COUNT(*) AS total FROM module_corptools_pings WHERE processed_at IS NULL"
        );
        $queueDepth = (int)($queueDepthRow['total'] ?? 0);

        $failures24 = (int)($app->db->one(
            "SELECT COUNT(*) AS total
             FROM module_corptools_job_runs
             WHERE status='failed' AND started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )['total'] ?? 0);
        $total24 = (int)($app->db->one(
            "SELECT COUNT(*) AS total
             FROM module_corptools_job_runs
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )['total'] ?? 0);
        $errorRate = $total24 > 0 ? round(($failures24 / $total24) * 100, 1) : null;

        $recentFailures = $app->db->all(
            "SELECT job_key, started_at, message
             FROM module_corptools_job_runs
             WHERE status='failed'
             ORDER BY started_at DESC
             LIMIT 5"
        );
        $failureRows = '';
        foreach ($recentFailures as $failure) {
            $jobKey = htmlspecialchars((string)($failure['job_key'] ?? ''));
            $started = htmlspecialchars((string)($failure['started_at'] ?? ''));
            $message = htmlspecialchars((string)($failure['message'] ?? ''));
            $failureRows .= "<tr><td>{$jobKey}</td><td>{$started}</td><td>{$message}</td></tr>";
        }
        if ($failureRows === '') {
            $failureRows = "<tr><td colspan='3' class='text-muted'>No recent failures.</td></tr>";
        }

        $errorRateText = $errorRate !== null ? "{$errorRate}%" : '—';

        $auditLast = $app->db->one(
            "SELECT MAX(finished_at) AS last_finished
             FROM module_corptools_audit_runs"
        );
        $auditLastRun = htmlspecialchars((string)($auditLast['last_finished'] ?? '—'));
        $auditRuns24 = (int)($app->db->one(
            "SELECT COUNT(*) AS total
             FROM module_corptools_audit_runs
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )['total'] ?? 0);
        $auditFailures24 = (int)($app->db->one(
            "SELECT COUNT(*) AS total
             FROM module_corptools_audit_runs
             WHERE status IN ('partial', 'blocked') AND started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )['total'] ?? 0);

        $scopeStatusRows = $app->db->all(
            "SELECT status, COUNT(*) AS total
             FROM module_corptools_character_scope_status
             GROUP BY status
             ORDER BY total DESC"
        );
        $scopeStatusTable = '';
        foreach ($scopeStatusRows as $row) {
            $status = htmlspecialchars((string)($row['status'] ?? 'unknown'));
            $count = (int)($row['total'] ?? 0);
            $scopeStatusTable .= "<tr><td>{$status}</td><td>{$count}</td></tr>";
        }
        if ($scopeStatusTable === '') {
            $scopeStatusTable = "<tr><td colspan='2' class='text-muted'>No scope status data yet.</td></tr>";
        }

        $tokenRefreshFailures = (int)($app->db->one(
            "SELECT COUNT(*) AS total FROM module_corptools_character_scope_status WHERE status='TOKEN_REFRESH_FAILED'"
        )['total'] ?? 0);
        $missingScopesTotal = (int)($app->db->one(
            "SELECT COUNT(*) AS total FROM module_corptools_character_scope_status WHERE status='MISSING_SCOPES'"
        )['total'] ?? 0);
        $esiErrors24 = (int)($app->db->one(
            "SELECT COUNT(*) AS total
             FROM module_corptools_audit_events
             WHERE event='esi_error' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )['total'] ?? 0);
        $dbErrors24 = (int)($app->db->one(
            "SELECT COUNT(*) AS total
             FROM module_corptools_audit_events
             WHERE event='db_error' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )['total'] ?? 0);

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>CorpTools Status</h1>
                      <div class='text-muted'>Version {$moduleVersion} • Observability & health signals.</div>
                    </div>
                  </div>
                  <div class='row g-3 mt-3'>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Pinger queue depth</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars((string)$queueDepth) . "</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Failures (24h)</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars((string)$failures24) . "</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Run error rate (24h)</div>
                        <div class='fs-5 fw-semibold'>" . htmlspecialchars((string)$errorRateText) . "</div>
                      </div>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Schema status</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead><tr><th>Table</th><th>Status</th></tr></thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Last successful sync</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead><tr><th>Job</th><th>Last success</th></tr></thead>
                        <tbody>{$successTable}</tbody>
                      </table>
                    </div>
                  </div>
                  <div class='row g-3 mt-3'>{$jobCards}</div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Recent failures</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead><tr><th>Job</th><th>Started</th><th>Message</th></tr></thead>
                        <tbody>{$failureRows}</tbody>
                      </table>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Audit Health</div>
                    <div class='text-muted'>Last audit run: {$auditLastRun}</div>
                    <div class='text-muted'>Audit runs (24h): {$auditRuns24}</div>
                    <div class='text-muted'>Audit failures (24h): {$auditFailures24}</div>
                    <div class='row g-2 mt-2'>
                      <div class='col-md-3'>
                        <div class='card card-body'>
                          <div class='text-muted small'>Token refresh failures</div>
                          <div class='fw-semibold'>{$tokenRefreshFailures}</div>
                        </div>
                      </div>
                      <div class='col-md-3'>
                        <div class='card card-body'>
                          <div class='text-muted small'>Missing scopes</div>
                          <div class='fw-semibold'>{$missingScopesTotal}</div>
                        </div>
                      </div>
                      <div class='col-md-3'>
                        <div class='card card-body'>
                          <div class='text-muted small'>ESI errors (24h)</div>
                          <div class='fw-semibold'>{$esiErrors24}</div>
                        </div>
                      </div>
                      <div class='col-md-3'>
                        <div class='card card-body'>
                          <div class='text-muted small'>DB errors (24h)</div>
                          <div class='fw-semibold'>{$dbErrors24}</div>
                        </div>
                      </div>
                    </div>
                    <div class='table-responsive mt-3'>
                      <table class='table table-sm align-middle'>
                        <thead><tr><th>Status</th><th>Count</th></tr></thead>
                        <tbody>{$scopeStatusTable}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('CorpTools Status', $body), 200);
    }, ['right' => 'corptools.admin']);

    $registry->route('GET', '/admin/corptools/member-audit', function (Request $req) use ($app, $renderPage, $renderPagination): Response {
        $search = trim((string)($req->query['q'] ?? ''));
        $corpInput = trim((string)($req->query['corp_id'] ?? ''));
        $allianceInput = trim((string)($req->query['alliance_id'] ?? ''));
        $groupInput = trim((string)($req->query['group_id'] ?? ''));
        $statusFilter = trim((string)($req->query['status'] ?? ''));

        $corpId = ctype_digit($corpInput) ? (int)$corpInput : 0;
        $allianceId = ctype_digit($allianceInput) ? (int)$allianceInput : 0;
        $groupId = ctype_digit($groupInput) ? (int)$groupInput : 0;

        $params = [];
        $where = [];
        $where[] = "u.id > 0";
        if ($search !== '') {
            $where[] = "(u.character_name LIKE ? OR EXISTS (SELECT 1 FROM character_links cl WHERE cl.user_id=u.id AND cl.character_name LIKE ?))";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($corpId > 0) {
            $where[] = "ms.corp_id = ?";
            $params[] = $corpId;
        }
        if ($allianceId > 0) {
            $where[] = "ms.alliance_id = ?";
            $params[] = $allianceId;
        }
        if ($groupId > 0) {
            $where[] = "EXISTS (SELECT 1 FROM eve_user_groups ug WHERE ug.user_id=u.id AND ug.group_id=?)";
            $params[] = $groupId;
        }
        if ($statusFilter !== '') {
            $where[] = "EXISTS (SELECT 1 FROM module_corptools_character_scope_status css WHERE css.user_id=u.id AND css.status=?)";
            $params[] = $statusFilter;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $page = max(1, (int)($req->query['page'] ?? 1));
        $perPage = 25;

        $countRow = $app->db->one(
            "SELECT COUNT(*) AS total
             FROM eve_users u
             LEFT JOIN module_corptools_member_summary ms ON ms.user_id=u.id
             {$whereSql}",
            $params
        );
        $totalRows = (int)($countRow['total'] ?? 0);

        $rows = $app->db->all(
            "SELECT u.id AS user_id,
                    u.character_id AS main_character_id,
                    u.character_name AS main_character_name,
                    ms.corp_id,
                    ms.alliance_id,
                    ms.highest_sp,
                    ms.last_login_at,
                    ms.audit_loaded
             FROM eve_users u
             LEFT JOIN module_corptools_member_summary ms ON ms.user_id=u.id
             {$whereSql}
             ORDER BY u.character_name ASC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        );

        $userIds = array_values(array_filter(array_map(fn($row) => (int)($row['user_id'] ?? 0), $rows)));
        $placeholders = $userIds ? implode(',', array_fill(0, count($userIds), '?')) : '';

        $linkMap = [];
        if ($userIds) {
            $links = $app->db->all(
                "SELECT user_id, character_id, character_name
                 FROM character_links
                 WHERE status='linked' AND user_id IN ({$placeholders})",
                $userIds
            );
            foreach ($links as $link) {
                $uid = (int)($link['user_id'] ?? 0);
                if ($uid <= 0) continue;
                $linkMap[$uid][] = [
                    'character_id' => (int)($link['character_id'] ?? 0),
                    'character_name' => (string)($link['character_name'] ?? 'Unknown'),
                ];
            }
        }

        $scopeMap = [];
        if ($userIds) {
            $scopeRows = $app->db->all(
                "SELECT css.user_id, css.character_id, css.status, css.reason, css.missing_scopes_json, css.checked_at,
                        cs.character_name, cs.last_audit_at, cs.current_ship_name, cs.location_system_id
                 FROM module_corptools_character_scope_status css
                 LEFT JOIN module_corptools_character_summary cs ON cs.character_id=css.character_id
                 WHERE css.user_id IN ({$placeholders})",
                $userIds
            );
            foreach ($scopeRows as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                $cid = (int)($row['character_id'] ?? 0);
                if ($uid <= 0 || $cid <= 0) continue;
                $scopeMap[$uid][$cid] = $row;
            }
        }

        $corpIds = [];
        $allianceIds = [];
        foreach ($rows as $row) {
            $corp = (int)($row['corp_id'] ?? 0);
            $alliance = (int)($row['alliance_id'] ?? 0);
            if ($corp > 0) $corpIds[$corp] = true;
            if ($alliance > 0) $allianceIds[$alliance] = true;
        }

        $corpProfiles = [];
        $allianceProfiles = [];
        if (!empty($corpIds) || !empty($allianceIds)) {
            $client = new EsiClient(new HttpClient());
            $cache = new EsiCache($app->db, $client);
            if (!empty($corpIds)) {
                foreach (array_keys($corpIds) as $corp) {
                    $corpData = $cache->getCached(
                        "corp:{$corp}",
                        "GET /latest/corporations/{$corp}/",
                        3600,
                        fn() => $client->get("/latest/corporations/{$corp}/")
                    );
                    $name = (string)($corpData['name'] ?? 'Unknown');
                    $ticker = (string)($corpData['ticker'] ?? '');
                    $corpProfiles[$corp] = $ticker !== '' ? "{$name} [{$ticker}]" : $name;
                }
            }
            if (!empty($allianceIds)) {
                foreach (array_keys($allianceIds) as $alliance) {
                    $allianceData = $cache->getCached(
                        "alliance:{$alliance}",
                        "GET /latest/alliances/{$alliance}/",
                        3600,
                        fn() => $client->get("/latest/alliances/{$alliance}/")
                    );
                    $allianceProfiles[$alliance] = (string)($allianceData['name'] ?? 'Unknown');
                }
            }
        }

        $summaryRow = $app->db->one(
            "SELECT COUNT(*) AS total,
                    SUM(status='COMPLIANT') AS compliant,
                    SUM(status='MISSING_SCOPES') AS missing_scopes,
                    SUM(status='TOKEN_EXPIRED') AS token_expired,
                    SUM(status='TOKEN_INVALID') AS token_invalid,
                    SUM(status='TOKEN_REFRESH_FAILED') AS token_refresh_failed
             FROM module_corptools_character_scope_status"
        );
        $summaryTotal = (int)($summaryRow['total'] ?? 0);
        $summaryCompliant = (int)($summaryRow['compliant'] ?? 0);
        $summaryMissing = (int)($summaryRow['missing_scopes'] ?? 0);
        $summaryExpired = (int)($summaryRow['token_expired'] ?? 0);
        $summaryInvalid = (int)($summaryRow['token_invalid'] ?? 0);
        $summaryRefreshFailed = (int)($summaryRow['token_refresh_failed'] ?? 0);
        $summaryPct = $summaryTotal > 0 ? round(($summaryCompliant / $summaryTotal) * 100, 1) : 0;

        $pagination = $renderPagination(
            $totalRows,
            $page,
            $perPage,
            '/admin/corptools/member-audit',
            array_filter([
                'q' => $search,
                'corp_id' => $corpInput,
                'alliance_id' => $allianceInput,
                'group_id' => $groupInput,
                'status' => $statusFilter,
            ], fn($val) => $val !== '' && $val !== null)
        );

        $groupRows = $app->db->all("SELECT id, name FROM groups ORDER BY name ASC");
        $groupOptions = "<option value=''>All groups</option>";
        foreach ($groupRows as $group) {
            $gid = (int)($group['id'] ?? 0);
            $gname = htmlspecialchars((string)($group['name'] ?? 'Group'));
            $selected = $gid === $groupId ? 'selected' : '';
            $groupOptions .= "<option value='{$gid}' {$selected}>{$gname}</option>";
        }

        $statusOptions = [
            '' => 'Any status',
            'COMPLIANT' => 'Compliant',
            'MISSING_SCOPES' => 'Missing scopes',
            'TOKEN_EXPIRED' => 'Token expired',
            'TOKEN_INVALID' => 'Token invalid',
            'TOKEN_REFRESH_FAILED' => 'Token refresh failed',
        ];
        $statusSelect = '';
        foreach ($statusOptions as $value => $label) {
            $selected = $statusFilter === $value ? 'selected' : '';
            $statusSelect .= "<option value='" . htmlspecialchars($value) . "' {$selected}>" . htmlspecialchars($label) . "</option>";
        }

        $flashLinks = $_SESSION['member_audit_links'] ?? null;
        unset($_SESSION['member_audit_links']);
        $linkHtml = '';
        if (is_array($flashLinks) && !empty($flashLinks)) {
            $linksRows = '';
            foreach ($flashLinks as $entry) {
                $userLabel = htmlspecialchars((string)($entry['user'] ?? 'User'));
                $url = htmlspecialchars((string)($entry['url'] ?? '#'));
                $linksRows .= "<tr><td>{$userLabel}</td><td><a href='{$url}'>{$url}</a></td></tr>";
            }
            $linkHtml = "<div class='card card-body mt-3'>
                <div class='fw-semibold mb-2'>Generated re-auth links</div>
                <div class='table-responsive'>
                  <table class='table table-sm align-middle'>
                    <thead><tr><th>Member</th><th>Link</th></tr></thead>
                    <tbody>{$linksRows}</tbody>
                  </table>
                </div>
              </div>";
        }

        $rowsHtml = '';
        foreach ($rows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            if ($userId <= 0) continue;
            $mainName = htmlspecialchars((string)($row['main_character_name'] ?? 'Unknown'));
            $corpId = (int)($row['corp_id'] ?? 0);
            $allianceId = (int)($row['alliance_id'] ?? 0);
            $corpLabel = $corpId > 0 ? htmlspecialchars($corpProfiles[$corpId] ?? (string)$corpId) : '—';
            $allianceLabel = $allianceId > 0 ? htmlspecialchars($allianceProfiles[$allianceId] ?? (string)$allianceId) : '—';

            $characters = [];
            $mainCharacterId = (int)($row['main_character_id'] ?? 0);
            if ($mainCharacterId > 0) {
                $characters[] = ['character_id' => $mainCharacterId, 'character_name' => (string)($row['main_character_name'] ?? 'Unknown'), 'is_main' => true];
            }
            foreach ($linkMap[$userId] ?? [] as $link) {
                $characters[] = ['character_id' => (int)$link['character_id'], 'character_name' => (string)$link['character_name'], 'is_main' => false];
            }

            $detailRows = '';
            $compliantCount = 0;
            $totalCount = 0;
            foreach ($characters as $char) {
                $cid = (int)($char['character_id'] ?? 0);
                $name = htmlspecialchars((string)($char['character_name'] ?? 'Unknown'));
                $scope = $scopeMap[$userId][$cid] ?? null;
                $status = $scope ? (string)($scope['status'] ?? 'UNKNOWN') : 'UNKNOWN';
                $reason = htmlspecialchars((string)($scope['reason'] ?? 'No scope data'));
                $missing = [];
                if ($scope && !empty($scope['missing_scopes_json'])) {
                    $missing = json_decode((string)$scope['missing_scopes_json'], true);
                    if (!is_array($missing)) $missing = [];
                }
                $missingText = $missing ? htmlspecialchars(implode(', ', $missing)) : '—';
                $lastAudit = htmlspecialchars((string)($scope['last_audit_at'] ?? '—'));
                $systemId = (int)($scope['location_system_id'] ?? 0);
                $location = $systemId > 0 ? htmlspecialchars($universe->name('system', $systemId)) : '—';
                $ship = htmlspecialchars((string)($scope['current_ship_name'] ?? '—'));
                $badgeClass = match ($status) {
                    'COMPLIANT' => 'bg-success',
                    'MISSING_SCOPES' => 'bg-warning text-dark',
                    'TOKEN_EXPIRED' => 'bg-secondary',
                    'TOKEN_INVALID' => 'bg-danger',
                    'TOKEN_REFRESH_FAILED' => 'bg-danger',
                    default => 'bg-dark',
                };
                if ($status === 'COMPLIANT') {
                    $compliantCount++;
                }
                $totalCount++;
                $detailRows .= "<tr>
                    <td>" . ($char['is_main'] ? "<span class='badge bg-primary me-1'>Main</span>" : "<span class='badge bg-secondary me-1'>Alt</span>") . "{$name}</td>
                    <td><span class='badge {$badgeClass}'>" . htmlspecialchars($status) . "</span></td>
                    <td>{$reason}</td>
                    <td>{$missingText}</td>
                    <td>{$lastAudit}</td>
                    <td>{$location}</td>
                    <td>{$ship}</td>
                  </tr>";
            }
            if ($detailRows === '') {
                $detailRows = "<tr><td colspan='7' class='text-muted'>No linked characters.</td></tr>";
            }
            $compliance = $totalCount > 0 ? "{$compliantCount}/{$totalCount}" : '0/0';
            $collapseId = "member-{$userId}";

            $rowsHtml .= "<div class='card card-body mb-3'>
                <div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                  <div>
                    <div class='fw-semibold'>{$mainName}</div>
                    <div class='text-muted small'>Corp: {$corpLabel} • Alliance: {$allianceLabel}</div>
                  </div>
                  <div class='text-end'>
                    <div class='text-muted small'>Compliance {$compliance}</div>
                    <div class='form-check'>
                      <input class='form-check-input' type='checkbox' name='user_ids[]' value='{$userId}' id='member-select-{$userId}'>
                      <label class='form-check-label small' for='member-select-{$userId}'>Select</label>
                    </div>
                    <button class='btn btn-sm btn-outline-light mt-2' type='button' data-bs-toggle='collapse' data-bs-target='#{$collapseId}'>Details</button>
                  </div>
                </div>
                <div class='collapse mt-3' id='{$collapseId}'>
                  <div class='table-responsive'>
                    <table class='table table-sm align-middle'>
                      <thead><tr><th>Character</th><th>Status</th><th>Reason</th><th>Missing scopes</th><th>Last audit</th><th>Location</th><th>Ship</th></tr></thead>
                      <tbody>{$detailRows}</tbody>
                    </table>
                  </div>
                </div>
              </div>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<div class='text-muted'>No members matched the filters.</div>";
        }

        $exportUrl = '/admin/corptools/member-audit/export?' . http_build_query(array_filter([
            'q' => $search,
            'corp_id' => $corpInput,
            'alliance_id' => $allianceInput,
            'group_id' => $groupInput,
            'status' => $statusFilter,
        ], fn($val) => $val !== '' && $val !== null));

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Member Audit</h1>
                      <div class='text-muted'>Admin/HR compliance dashboard for scope policy.</div>
                    </div>
                  </div>
                  <div class='row g-3 mt-3'>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Characters tracked</div>
                        <div class='fs-5 fw-semibold'>{$summaryTotal}</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Compliance rate</div>
                        <div class='fs-5 fw-semibold'>{$summaryPct}%</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Missing scopes</div>
                        <div class='fs-5 fw-semibold'>{$summaryMissing}</div>
                      </div>
                    </div>
                    <div class='col-md-3'>
                      <div class='card card-body'>
                        <div class='text-muted small'>Token issues</div>
                        <div class='fs-6'>Expired: {$summaryExpired}</div>
                        <div class='fs-6'>Invalid: {$summaryInvalid}</div>
                        <div class='fs-6'>Refresh failed: {$summaryRefreshFailed}</div>
                      </div>
                    </div>
                  </div>
                  <form method='get' class='card card-body mt-3'>
                    <div class='row g-2'>
                      <div class='col-md-3'>
                        <label class='form-label'>Member name</label>
                        <input class='form-control' name='q' value='" . htmlspecialchars($search) . "' placeholder='Search main'>
                      </div>
                      <div class='col-md-2'>
                        <label class='form-label'>Corp ID</label>
                        <input class='form-control' name='corp_id' value='" . htmlspecialchars($corpInput) . "' placeholder='Corp ID'>
                      </div>
                      <div class='col-md-2'>
                        <label class='form-label'>Alliance ID</label>
                        <input class='form-control' name='alliance_id' value='" . htmlspecialchars($allianceInput) . "' placeholder='Alliance ID'>
                      </div>
                      <div class='col-md-2'>
                        <label class='form-label'>Group</label>
                        <select class='form-select' name='group_id'>{$groupOptions}</select>
                      </div>
                      <div class='col-md-2'>
                        <label class='form-label'>Status</label>
                        <select class='form-select' name='status'>{$statusSelect}</select>
                      </div>
                      <div class='col-md-1 d-flex align-items-end'>
                        <button class='btn btn-primary'>Filter</button>
                      </div>
                    </div>
                  </form>
                  <form method='post' action='/admin/corptools/member-audit/action' class='mt-3'>
                    <div class='d-flex flex-wrap gap-2 mb-2'>
                      <button class='btn btn-outline-primary' name='action' value='audit'>Trigger audit for selected</button>
                      <button class='btn btn-outline-warning' name='action' value='reauth'>Generate re-auth links</button>
                      <a class='btn btn-outline-secondary' href='{$exportUrl}'>Export CSV</a>
                    </div>
                    {$rowsHtml}
                  </form>
                  {$pagination}
                  {$linkHtml}";

        return Response::html($renderPage('Member Audit', $body), 200);
    }, ['right' => 'corptools.member_audit']);

    $registry->route('POST', '/admin/corptools/member-audit/action', function (Request $req) use ($app, $auditCollectors, $tokenData, $getEffectiveScopesForUser, $scopeAudit, $updateMemberSummary): Response {
        $action = (string)($req->post['action'] ?? '');
        $selected = $req->post['user_ids'] ?? [];
        if (!is_array($selected)) $selected = [];
        $userIds = array_values(array_filter(array_map('intval', $selected), fn(int $id) => $id > 0));

        if (empty($userIds)) {
            $_SESSION['member_audit_links'] = [];
            return Response::redirect('/admin/corptools/member-audit');
        }

        if ($action === 'reauth') {
            $links = [];
            foreach ($userIds as $userId) {
                $token = bin2hex(random_bytes(16));
                $tokenHash = hash('sha256', $token);
                $prefix = substr($token, 0, 8);
                $app->db->run(
                    "INSERT INTO module_charlink_states
                     (user_id, token_hash, token_prefix, purpose, created_at, expires_at)
                     VALUES (?, ?, ?, 'reauth', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))",
                    [$userId, $tokenHash, $prefix]
                );
                $userRow = $app->db->one("SELECT character_name FROM eve_users WHERE id=? LIMIT 1", [$userId]);
                $userName = (string)($userRow['character_name'] ?? "User {$userId}");
                $links[] = [
                    'user' => $userName,
                    'url' => "/corptools/reauth?token={$token}",
                ];
            }
            $_SESSION['member_audit_links'] = $links;
            return Response::redirect('/admin/corptools/member-audit');
        }

        $dispatcher = new AuditDispatcher($app->db);
        $collectors = $auditCollectors();
        $settings = (new CorpToolsSettings($app->db))->get();
        $enabled = array_keys(array_filter($settings['audit_scopes'] ?? [], fn($enabled) => !empty($enabled)));
        $universe = new Universe($app->db);

        foreach ($userIds as $userId) {
            $userRow = $app->db->one("SELECT character_id, character_name FROM eve_users WHERE id=? LIMIT 1", [$userId]);
            $mainId = (int)($userRow['character_id'] ?? 0);
            $mainName = (string)($userRow['character_name'] ?? 'Unknown');
            if ($mainId <= 0) continue;

            $characters = [
                ['character_id' => $mainId, 'character_name' => $mainName],
            ];
            $links = $app->db->all(
                "SELECT character_id, character_name FROM character_links WHERE user_id=? AND status='linked'",
                [$userId]
            );
            foreach ($links as $link) {
                $cid = (int)($link['character_id'] ?? 0);
                if ($cid <= 0) continue;
                $characters[] = ['character_id' => $cid, 'character_name' => (string)($link['character_name'] ?? 'Unknown')];
            }

            $scopeSet = $getEffectiveScopesForUser($userId);
            $requiredScopes = $scopeSet['required'] ?? [];
            $optionalScopes = $scopeSet['optional'] ?? [];
            $policyId = $scopeSet['policy']['id'] ?? null;

            foreach ($characters as $character) {
                $characterId = (int)($character['character_id'] ?? 0);
                if ($characterId <= 0) continue;
                $token = $tokenData($characterId);
                $profile = $universe->characterProfile($characterId);
                $baseSummary = [
                    'is_main' => $characterId === $mainId ? 1 : 0,
                    'corp_id' => (int)($profile['corporation']['id'] ?? 0),
                    'alliance_id' => (int)($profile['alliance']['id'] ?? 0),
                ];
                $dispatcher->run(
                    $userId,
                    $characterId,
                    (string)($profile['character']['name'] ?? $character['character_name']),
                    $token,
                    $collectors,
                    $enabled,
                    $baseSummary,
                    $requiredScopes,
                    $optionalScopes,
                    is_numeric($policyId) ? (int)$policyId : null,
                    $scopeAudit
                );
            }

            $updateMemberSummary($userId, $mainId, $mainName);
        }

        return Response::redirect('/admin/corptools/member-audit');
    }, ['right' => 'corptools.member_audit']);

    $registry->route('GET', '/admin/corptools/member-audit/export', function (Request $req) use ($app): Response {
        $search = trim((string)($req->query['q'] ?? ''));
        $corpInput = trim((string)($req->query['corp_id'] ?? ''));
        $allianceInput = trim((string)($req->query['alliance_id'] ?? ''));
        $groupInput = trim((string)($req->query['group_id'] ?? ''));
        $statusFilter = trim((string)($req->query['status'] ?? ''));

        $corpId = ctype_digit($corpInput) ? (int)$corpInput : 0;
        $allianceId = ctype_digit($allianceInput) ? (int)$allianceInput : 0;
        $groupId = ctype_digit($groupInput) ? (int)$groupInput : 0;

        $params = [];
        $where = [];
        $where[] = "u.id > 0";
        if ($search !== '') {
            $where[] = "(u.character_name LIKE ? OR EXISTS (SELECT 1 FROM character_links cl WHERE cl.user_id=u.id AND cl.character_name LIKE ?))";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($corpId > 0) {
            $where[] = "ms.corp_id = ?";
            $params[] = $corpId;
        }
        if ($allianceId > 0) {
            $where[] = "ms.alliance_id = ?";
            $params[] = $allianceId;
        }
        if ($groupId > 0) {
            $where[] = "EXISTS (SELECT 1 FROM eve_user_groups ug WHERE ug.user_id=u.id AND ug.group_id=?)";
            $params[] = $groupId;
        }
        if ($statusFilter !== '') {
            $where[] = "EXISTS (SELECT 1 FROM module_corptools_character_scope_status css WHERE css.user_id=u.id AND css.status=?)";
            $params[] = $statusFilter;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $rows = $app->db->all(
            "SELECT u.id AS user_id, u.character_name AS main_character_name, ms.corp_id, ms.alliance_id, ms.highest_sp
             FROM eve_users u
             LEFT JOIN module_corptools_member_summary ms ON ms.user_id=u.id
             {$whereSql}
             ORDER BY u.character_name ASC",
            $params
        );

        $lines = ["user_id,main_character,corp_id,alliance_id,highest_sp"];
        foreach ($rows as $row) {
            $lines[] = implode(',', [
                (int)($row['user_id'] ?? 0),
                '"' . str_replace('"', '""', (string)($row['main_character_name'] ?? '')) . '"',
                (int)($row['corp_id'] ?? 0),
                (int)($row['alliance_id'] ?? 0),
                (int)($row['highest_sp'] ?? 0),
            ]);
        }

        $body = implode("\n", $lines);
        return new Response($body, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=\"member_audit.csv\"',
        ]);
    }, ['right' => 'corptools.member_audit']);

    $registry->route('GET', '/corptools/reauth', function (Request $req) use ($app, $scopePolicy): Response {
        $token = (string)($req->query['token'] ?? '');
        if ($token === '') {
            return Response::text('Missing token', 400);
        }
        $tokenHash = hash('sha256', $token);
        $row = $app->db->one(
            "SELECT id, user_id, expires_at, used_at
             FROM module_charlink_states
             WHERE token_hash=? AND purpose='reauth' LIMIT 1",
            [$tokenHash]
        );
        if (!$row) {
            return Response::text('Invalid or expired token.', 403);
        }
        $expiresAt = $row['expires_at'] ? strtotime((string)$row['expires_at']) : null;
        if (!empty($row['used_at'])) {
            return Response::text('This re-auth link has already been used.', 403);
        }
        if ($expiresAt !== null && time() > $expiresAt) {
            return Response::text('This re-auth link has expired.', 403);
        }

        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0) {
            return Response::text('Invalid user for re-auth.', 403);
        }

        $scopeSet = $scopePolicy->getEffectiveScopesForUser($userId);
        $requiredScopes = $scopeSet['required'] ?? [];
        $_SESSION['sso_scopes_override'] = $requiredScopes;
        $_SESSION['charlink_redirect'] = '/corptools/characters';

        $app->db->run(
            "UPDATE module_charlink_states SET used_at=NOW() WHERE id=?",
            [(int)($row['id'] ?? 0)]
        );

        return Response::redirect('/auth/login');
    }, ['public' => true]);

    $registry->route('GET', '/admin/corptools/cron', function () use ($app, $renderPage): Response {
        JobRegistry::sync($app->db);
        $jobs = $app->db->all(
            "SELECT job_key, name, schedule_seconds, is_enabled, last_run_at, next_run_at, last_status, last_duration_ms
             FROM module_corptools_jobs
             ORDER BY job_key ASC"
        );

        $rowsHtml = '';
        foreach ($jobs as $job) {
            $jobKey = htmlspecialchars((string)($job['job_key'] ?? ''));
            $name = htmlspecialchars((string)($job['name'] ?? ''));
            $schedule = htmlspecialchars((string)($job['schedule_seconds'] ?? 0));
            $enabled = ((int)($job['is_enabled'] ?? 0) === 1) ? 'Enabled' : 'Disabled';
            $lastRun = htmlspecialchars((string)($job['last_run_at'] ?? '—'));
            $nextRun = htmlspecialchars((string)($job['next_run_at'] ?? '—'));
            $status = htmlspecialchars((string)($job['last_status'] ?? '—'));
            $duration = htmlspecialchars((string)($job['last_duration_ms'] ?? 0));

            $toggleLabel = ((int)($job['is_enabled'] ?? 0) === 1) ? 'Disable' : 'Enable';

            $rowsHtml .= "<tr>
                <td>{$jobKey}</td>
                <td>{$name}</td>
                <td>{$schedule}s</td>
                <td>{$enabled}</td>
                <td>{$lastRun}</td>
                <td>{$nextRun}</td>
                <td>{$status}</td>
                <td>{$duration} ms</td>
                <td class='text-end'>
                  <a class='btn btn-sm btn-outline-light' href='/admin/corptools/cron/job?job={$jobKey}'>Details</a>
                  <form method='post' action='/admin/corptools/cron/toggle' class='d-inline'>
                    <input type='hidden' name='job_key' value='{$jobKey}'>
                    <input type='hidden' name='enabled' value='" . (((int)($job['is_enabled'] ?? 0) === 1) ? '0' : '1') . "'>
                    <button class='btn btn-sm btn-outline-secondary'>{$toggleLabel}</button>
                  </form>
                </td>
              </tr>";
        }

        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='9' class='text-muted'>No jobs registered yet.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Cron Job Manager</h1>
                      <div class='text-muted'>View schedules, last runs, and manual controls.</div>
                    </div>
                    <div>
                      <a class='btn btn-outline-light' href='/admin/corptools/cron/runs'>View Runs</a>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Job</th>
                            <th>Name</th>
                            <th>Schedule</th>
                            <th>Enabled</th>
                            <th>Last Run</th>
                            <th>Next Run</th>
                            <th>Status</th>
                            <th>Duration</th>
                            <th></th>
                          </tr>
                        </thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Cron Job Manager', $body), 200);
    }, ['right' => 'corptools.cron.manage']);

    $registry->route('GET', '/admin/corptools/cron/job', function (Request $req) use ($app, $renderPage, $csrfToken): Response {
        $jobKey = (string)($req->query['job'] ?? '');
        if ($jobKey === '') {
            return Response::redirect('/admin/corptools/cron');
        }
        JobRegistry::sync($app->db);

        $job = $app->db->one(
            "SELECT job_key, name, description, schedule_seconds, is_enabled, last_run_at, next_run_at, last_status, last_duration_ms, last_message
             FROM module_corptools_jobs WHERE job_key=? LIMIT 1",
            [$jobKey]
        );
        if (!$job) {
            return Response::redirect('/admin/corptools/cron');
        }

        $runs = $app->db->all(
            "SELECT id, status, started_at, finished_at, duration_ms, message
             FROM module_corptools_job_runs
             WHERE job_key=?
             ORDER BY started_at DESC
             LIMIT 10",
            [$jobKey]
        );

        $flash = $_SESSION['corptools_cron_flash'] ?? null;
        unset($_SESSION['corptools_cron_flash']);
        $flashHtml = '';
        if (is_array($flash)) {
            $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
            $message = htmlspecialchars((string)($flash['message'] ?? ''));
            if ($message !== '') {
                $flashHtml = "<div class='alert alert-{$type} mt-3'>{$message}</div>";
            }
        }

        $runRows = '';
        foreach ($runs as $run) {
            $status = htmlspecialchars((string)($run['status'] ?? ''));
            $started = htmlspecialchars((string)($run['started_at'] ?? ''));
            $finished = htmlspecialchars((string)($run['finished_at'] ?? '—'));
            $duration = htmlspecialchars((string)($run['duration_ms'] ?? 0));
            $message = htmlspecialchars((string)($run['message'] ?? ''));
            $runRows .= "<tr><td>{$status}</td><td>{$started}</td><td>{$finished}</td><td>{$duration} ms</td><td>{$message}</td></tr>";
        }
        if ($runRows === '') {
            $runRows = "<tr><td colspan='5' class='text-muted'>No runs recorded.</td></tr>";
        }

        $enabled = ((int)($job['is_enabled'] ?? 0) === 1) ? 'Enabled' : 'Disabled';

        $csrf = $csrfToken('corptools_cron_run');
        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Job: " . htmlspecialchars((string)$job['name']) . "</h1>
                      <div class='text-muted'>" . htmlspecialchars((string)$job['job_key']) . "</div>
                    </div>
                    <div class='d-flex gap-2'>
                      <a class='btn btn-outline-light' href='/admin/corptools/cron'>Back to Jobs</a>
                    </div>
                  </div>
                  {$flashHtml}
                  <div class='card card-body mt-3'>
                    <div class='text-muted'>Description: " . htmlspecialchars((string)($job['description'] ?? '')) . "</div>
                    <div class='text-muted'>Schedule: " . htmlspecialchars((string)($job['schedule_seconds'] ?? 0)) . " seconds</div>
                    <div class='text-muted'>Status: {$enabled} • Last run: " . htmlspecialchars((string)($job['last_run_at'] ?? '—')) . "</div>
                    <div class='text-muted'>Last result: " . htmlspecialchars((string)($job['last_status'] ?? '—')) . "</div>
                    <div class='text-muted'>Next run: " . htmlspecialchars((string)($job['next_run_at'] ?? '—')) . "</div>
                  </div>
                  <form method='post' action='/admin/corptools/cron/run' class='card card-body mt-3'>
                    <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrf) . "'>
                    <input type='hidden' name='job_key' value='" . htmlspecialchars((string)$job['job_key']) . "'>
                    <div class='fw-semibold mb-2'>Run now</div>
                    <div class='form-check'>
                      <input class='form-check-input' type='checkbox' name='verbose' value='1' id='job-verbose'>
                      <label class='form-check-label' for='job-verbose'>Verbose output</label>
                    </div>
                    <div class='form-check'>
                      <input class='form-check-input' type='checkbox' name='dry_run' value='1' id='job-dry-run'>
                      <label class='form-check-label' for='job-dry-run'>Dry run (when supported)</label>
                    </div>
                    <button class='btn btn-outline-primary mt-3'>Run Job</button>
                  </form>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Recent runs</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead><tr><th>Status</th><th>Started</th><th>Finished</th><th>Duration</th><th>Message</th></tr></thead>
                        <tbody>{$runRows}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Cron Job Detail', $body), 200);
    }, ['right' => 'corptools.cron.manage']);

    $registry->route('GET', '/admin/corptools/cron/runs', function (Request $req) use ($app, $renderPage, $renderPagination): Response {
        $jobFilter = trim((string)($req->query['job'] ?? ''));
        $statusFilter = trim((string)($req->query['status'] ?? ''));

        $where = [];
        $params = [];
        if ($jobFilter !== '') {
            $where[] = 'job_key = ?';
            $params[] = $jobFilter;
        }
        if ($statusFilter !== '') {
            $where[] = 'status = ?';
            $params[] = $statusFilter;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $page = max(1, (int)($req->query['page'] ?? 1));
        $perPage = 50;

        $countRow = $app->db->one(
            "SELECT COUNT(*) AS total FROM module_corptools_job_runs {$whereSql}",
            $params
        );
        $totalRows = (int)($countRow['total'] ?? 0);

        $runs = $app->db->all(
            "SELECT id, job_key, status, started_at, finished_at, duration_ms, message
             FROM module_corptools_job_runs
             {$whereSql}
             ORDER BY started_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        );

        $rowsHtml = '';
        foreach ($runs as $run) {
            $jobKey = htmlspecialchars((string)($run['job_key'] ?? ''));
            $status = htmlspecialchars((string)($run['status'] ?? ''));
            $started = htmlspecialchars((string)($run['started_at'] ?? ''));
            $finished = htmlspecialchars((string)($run['finished_at'] ?? '—'));
            $duration = htmlspecialchars((string)($run['duration_ms'] ?? 0));
            $message = htmlspecialchars((string)($run['message'] ?? ''));
            $runId = (int)($run['id'] ?? 0);
            $details = $runId > 0 ? "<a class='btn btn-sm btn-outline-light' href='/admin/corptools/cron/run-detail?id={$runId}'>Details</a>" : '';
            $rowsHtml .= "<tr><td>{$jobKey}</td><td>{$status}</td><td>{$started}</td><td>{$finished}</td><td>{$duration} ms</td><td>{$message}</td><td>{$details}</td></tr>";
        }
        if ($rowsHtml === '') {
            $rowsHtml = "<tr><td colspan='7' class='text-muted'>No runs match the filters.</td></tr>";
        }

        $pagination = $renderPagination(
            $totalRows,
            $page,
            $perPage,
            '/admin/corptools/cron/runs',
            array_filter([
                'job' => $jobFilter,
                'status' => $statusFilter,
            ], fn($val) => $val !== '')
        );

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Cron Runs</h1>
                      <div class='text-muted'>Execution logs with duration and status.</div>
                    </div>
                    <div>
                      <a class='btn btn-outline-light' href='/admin/corptools/cron'>Back to Jobs</a>
                    </div>
                  </div>
                  <form method='get' class='card card-body mt-3'>
                    <div class='row g-2'>
                      <div class='col-md-4'>
                        <label class='form-label'>Job key</label>
                        <input class='form-control' name='job' value='" . htmlspecialchars($jobFilter) . "'>
                      </div>
                      <div class='col-md-4'>
                        <label class='form-label'>Status</label>
                        <input class='form-control' name='status' value='" . htmlspecialchars($statusFilter) . "'>
                      </div>
                      <div class='col-md-4 d-flex align-items-end'>
                        <button class='btn btn-primary me-2'>Filter</button>
                        <a class='btn btn-outline-secondary' href='/admin/corptools/cron/runs'>Reset</a>
                      </div>
                    </div>
                  </form>
                  <div class='card card-body mt-3'>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead><tr><th>Job</th><th>Status</th><th>Started</th><th>Finished</th><th>Duration</th><th>Message</th><th></th></tr></thead>
                        <tbody>{$rowsHtml}</tbody>
                      </table>
                    </div>
                  </div>
                  {$pagination}";

        return Response::html($renderPage('Cron Runs', $body), 200);
    }, ['right' => 'corptools.cron.manage']);

    $registry->route('GET', '/admin/corptools/cron/run-detail', function (Request $req) use ($app, $renderPage): Response {
        $runId = (int)($req->query['id'] ?? 0);
        if ($runId <= 0) {
            return Response::redirect('/admin/corptools/cron/runs');
        }

        $run = $app->db->one(
            "SELECT job_key, status, started_at, finished_at, duration_ms, message, error_trace, meta_json
             FROM module_corptools_job_runs WHERE id=? LIMIT 1",
            [$runId]
        );
        if (!$run) {
            return Response::redirect('/admin/corptools/cron/runs');
        }

        $meta = json_decode((string)($run['meta_json'] ?? '[]'), true);
        $metaText = htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $traceText = htmlspecialchars((string)($run['error_trace'] ?? ''));
        $logLines = [];
        if (is_array($meta) && isset($meta['log_lines']) && is_array($meta['log_lines'])) {
            $logLines = $meta['log_lines'];
        }
        $logText = $logLines ? htmlspecialchars(implode("\n", array_map('strval', $logLines))) : '—';

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Run Detail</h1>
                      <div class='text-muted'>" . htmlspecialchars((string)($run['job_key'] ?? '')) . "</div>
                    </div>
                    <div>
                      <a class='btn btn-outline-light' href='/admin/corptools/cron/runs'>Back to Runs</a>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='text-muted'>Status: " . htmlspecialchars((string)($run['status'] ?? '')) . "</div>
                    <div class='text-muted'>Started: " . htmlspecialchars((string)($run['started_at'] ?? '')) . "</div>
                    <div class='text-muted'>Finished: " . htmlspecialchars((string)($run['finished_at'] ?? '—')) . "</div>
                    <div class='text-muted'>Duration: " . htmlspecialchars((string)($run['duration_ms'] ?? 0)) . " ms</div>
                    <div class='text-muted'>Message: " . htmlspecialchars((string)($run['message'] ?? '')) . "</div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Meta</div>
                    <pre class='small mb-0'>{$metaText}</pre>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Output</div>
                    <pre class='small mb-0'>{$logText}</pre>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Error Trace</div>
                    <pre class='small mb-0'>" . ($traceText !== '' ? $traceText : '—') . "</pre>
                  </div>";

        return Response::html($renderPage('Run Detail', $body), 200);
    }, ['right' => 'corptools.cron.manage']);

    $registry->route('POST', '/admin/corptools/cron/run', function (Request $req) use ($app, $csrfCheck): Response {
        $jobKey = (string)($req->post['job_key'] ?? '');
        if ($jobKey === '') {
            return Response::redirect('/admin/corptools/cron');
        }
        if (!$csrfCheck('corptools_cron_run', (string)($req->post['csrf_token'] ?? ''))) {
            return Response::text('403 Forbidden', 403);
        }
        JobRegistry::sync($app->db);
        $runner = new JobRunner($app->db, JobRegistry::definitionsByKey());
        $result = $runner->runJob($app, $jobKey, [
            'trigger' => 'manual',
            'dry_run' => !empty($req->post['dry_run']),
            'verbose' => !empty($req->post['verbose']),
        ]);

        $_SESSION['corptools_cron_flash'] = [
            'type' => ($result['status'] ?? '') === 'failed' ? 'danger' : 'success',
            'message' => ($result['message'] ?? 'Job executed'),
        ];

        return Response::redirect('/admin/corptools/cron/job?job=' . urlencode($jobKey));
    }, ['right' => 'corptools.cron.manage']);

    $registry->route('POST', '/admin/corptools/cron/toggle', function (Request $req) use ($app): Response {
        $jobKey = (string)($req->post['job_key'] ?? '');
        $enabled = !empty($req->post['enabled']) ? 1 : 0;
        if ($jobKey !== '') {
            $app->db->run(
                "UPDATE module_corptools_jobs SET is_enabled=? WHERE job_key=?",
                [$enabled, $jobKey]
            );
        }
        return Response::redirect('/admin/corptools/cron');
    }, ['right' => 'corptools.cron.manage']);

    $registry->route('GET', '/admin/corptools', function () use ($app, $renderPage, $getCorpToolsSettings, $getIdentityCorpIds, $getCorpProfiles, $getAvailableCorpIds, $hasRight, $scopePolicy, $scopeCatalog): Response {
        $settings = $getCorpToolsSettings();
        $tab = (string)($_GET['tab'] ?? 'general');

        $tabs = [
            'general' => 'General',
            'integrations' => 'Integrations',
            'scope_policy' => 'Scope Policy',
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
            $corpContextId = (int)($settings['general']['corp_context_id'] ?? 0);
            $allowSwitch = !empty($settings['general']['allow_context_switch']) ? 'checked' : '';
            $allowedCorpIds = $settings['general']['corp_ids'] ?? [];
            if (!is_array($allowedCorpIds)) $allowedCorpIds = [];
            $allowedCorpIds = array_values(array_filter(array_map('intval', $allowedCorpIds), fn(int $id) => $id > 0));

            $identityProfiles = $getCorpProfiles($getIdentityCorpIds());
            $availableProfiles = $getCorpProfiles($getAvailableCorpIds());

            $contextOptions = "<option value='0'>Select corp context</option>";
            foreach ($availableProfiles as $profile) {
                $pid = (int)($profile['id'] ?? 0);
                $pLabel = htmlspecialchars((string)($profile['label'] ?? 'Corporation'));
                $selected = $pid === $corpContextId ? 'selected' : '';
                $contextOptions .= "<option value='{$pid}' {$selected}>{$pLabel}</option>";
            }

            $corpCheckboxes = '';
            if (empty($identityProfiles)) {
                $corpCheckboxes = "<div class='text-muted'>No corps found via site identity settings. Configure site identity to populate corp choices.</div>";
            } else {
                $useAll = empty($allowedCorpIds);
                foreach ($identityProfiles as $profile) {
                    $pid = (int)($profile['id'] ?? 0);
                    $pLabel = htmlspecialchars((string)($profile['label'] ?? 'Corporation'));
                    $checked = $useAll || in_array($pid, $allowedCorpIds, true) ? 'checked' : '';
                    $corpCheckboxes .= "<div class='form-check'>
                        <input class='form-check-input' type='checkbox' name='corp_ids[]' value='{$pid}' id='corp-id-{$pid}' {$checked}>
                        <label class='form-check-label' for='corp-id-{$pid}'>{$pLabel}</label>
                      </div>";
                }
                if ($useAll) {
                    $corpCheckboxes .= "<div class='text-muted small mt-1'>All identity corps are currently allowed. Uncheck to restrict.</div>";
                }
            }

            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='general'>
                <div class='fw-semibold mb-2'>Corp context</div>
                <label class='form-label'>Default corporation</label>
                <select class='form-select' name='corp_context_id'>{$contextOptions}</select>
                <div class='form-text'>CorpTools dashboards default to this corp context and never infer it from login activity.</div>
                <div class='form-check mt-2'>
                  <input class='form-check-input' type='checkbox' name='allow_context_switch' value='1' id='allow-context-switch' {$allowSwitch}>
                  <label class='form-check-label' for='allow-context-switch'>Allow directors to switch corp context (confirmation required)</label>
                </div>
                <div class='mt-3'>
                  <div class='fw-semibold'>Allowed corp contexts</div>
                  {$corpCheckboxes}
                </div>
                <label class='form-label mt-3'>Holding wallet divisions (comma-separated)</label>
                <input class='form-control' name='holding_wallet_divisions' value='{$divisions}'>
                <label class='form-label mt-3'>Holding wallet label</label>
                <input class='form-control' name='holding_wallet_label' value='{$label}'>
                <label class='form-label mt-3'>Retention days</label>
                <input class='form-control' name='retention_days' value='{$retention}'>
                <button class='btn btn-primary mt-3'>Save General Settings</button>
              </form>";
        } elseif ($tab === 'integrations') {
            $cfg = $app->config['eve_sso'] ?? [];
            $clientId = (string)($cfg['client_id'] ?? '');
            $clientSecret = (string)($cfg['client_secret'] ?? '');
            $mask = function (string $value): string {
                if ($value === '') return 'Not set';
                $tail = substr($value, -4);
                return str_repeat('•', max(0, strlen($value) - 4)) . $tail;
            };
            $sectionHtml = "<div class='card card-body mt-3'>
                <div class='fw-semibold mb-2'>ESI Integration</div>
                <div class='text-muted'>Client ID: " . htmlspecialchars($mask($clientId)) . "</div>
                <div class='text-muted'>Client Secret: " . htmlspecialchars($mask($clientSecret)) . "</div>
                <div class='text-muted small mt-2'>Credentials are managed in /var/www/config.php and reused by CorpTools.</div>
              </div>";
        } elseif ($tab === 'scope_policy') {
            $flash = $_SESSION['corptools_scope_flash'] ?? null;
            unset($_SESSION['corptools_scope_flash']);
            $flashHtml = '';
            if (is_array($flash)) {
                $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
                $message = htmlspecialchars((string)($flash['message'] ?? ''));
                if ($message !== '') {
                    $flashHtml = "<div class='alert alert-{$type} mt-3'>{$message}</div>";
                }
            }

            $policies = $scopePolicy->listPolicies();
            $policyId = (int)($_GET['policy_id'] ?? 0);
            $selectedPolicy = null;
            foreach ($policies as $policy) {
                if ((int)($policy['id'] ?? 0) === $policyId) {
                    $selectedPolicy = $policy;
                    break;
                }
            }
            if (!$selectedPolicy && !empty($policies)) {
                $selectedPolicy = $policies[0];
                $policyId = (int)($selectedPolicy['id'] ?? 0);
            }

            $required = $selectedPolicy ? $scopePolicy->normalizeScopes($selectedPolicy['required_scopes_json'] ?? null) : [];
            $optional = $selectedPolicy ? $scopePolicy->normalizeScopes($selectedPolicy['optional_scopes_json'] ?? null) : [];

            $policyRows = '';
            foreach ($policies as $policy) {
                $pid = (int)($policy['id'] ?? 0);
                $name = htmlspecialchars((string)($policy['name'] ?? 'Policy'));
                $applies = htmlspecialchars((string)($policy['applies_to'] ?? 'all_users'));
                $active = !empty($policy['is_active']) ? "<span class='badge bg-success'>Active</span>" : "<span class='badge bg-secondary'>Inactive</span>";
                $policyRows .= "<tr>
                    <td>{$name}</td>
                    <td>{$applies}</td>
                    <td>{$active}</td>
                    <td class='text-end'><a class='btn btn-sm btn-outline-light' href='/admin/corptools?tab=scope_policy&policy_id={$pid}'>Edit</a></td>
                  </tr>";
            }
            if ($policyRows === '') {
                $policyRows = "<tr><td colspan='4' class='text-muted'>No policies defined yet.</td></tr>";
            }

            $groupRows = $app->db->all("SELECT id, name FROM groups ORDER BY name ASC");
            $groupOptions = "<option value=''>Select group</option>";
            foreach ($groupRows as $group) {
                $gid = (int)($group['id'] ?? 0);
                $gname = htmlspecialchars((string)($group['name'] ?? 'Group'));
                if ($gid <= 0) continue;
                $groupOptions .= "<option value='{$gid}'>{$gname} (ID {$gid})</option>";
            }

            $overrideRows = '';
            if ($policyId > 0) {
                $overrides = $app->db->all(
                    "SELECT id, target_type, target_id, required_scopes_json, optional_scopes_json
                     FROM corp_scope_policy_overrides
                     WHERE policy_id=?
                     ORDER BY updated_at DESC, id DESC",
                    [$policyId]
                );

                foreach ($overrides as $override) {
                    $oid = (int)($override['id'] ?? 0);
                    $type = (string)($override['target_type'] ?? '');
                    $targetId = (int)($override['target_id'] ?? 0);
                    $targetLabel = '';
                    if ($type === 'user') {
                        $userRow = $app->db->one("SELECT character_name FROM eve_users WHERE id=? LIMIT 1", [$targetId]);
                        $userName = (string)($userRow['character_name'] ?? 'User');
                        $targetLabel = htmlspecialchars($userName) . " (User #{$targetId})";
                    } else {
                        $groupRow = $app->db->one("SELECT name FROM groups WHERE id=? LIMIT 1", [$targetId]);
                        $groupName = (string)($groupRow['name'] ?? 'Group');
                        $targetLabel = htmlspecialchars($groupName) . " (Group #{$targetId})";
                    }
                    $requiredLabel = htmlspecialchars(implode(', ', $scopePolicy->normalizeScopes($override['required_scopes_json'] ?? null)));
                    $optionalLabel = htmlspecialchars(implode(', ', $scopePolicy->normalizeScopes($override['optional_scopes_json'] ?? null)));
                    if ($requiredLabel === '') $requiredLabel = '—';
                    if ($optionalLabel === '') $optionalLabel = '—';
                    $overrideRows .= "<tr>
                        <td>{$type}</td>
                        <td>{$targetLabel}</td>
                        <td>{$requiredLabel}</td>
                        <td>{$optionalLabel}</td>
                        <td class='text-end'>
                          <form method='post' action='/admin/corptools/scope-policy/override/delete' onsubmit=\"return confirm('Delete this override?');\">
                            <input type='hidden' name='override_id' value='{$oid}'>
                            <input type='hidden' name='policy_id' value='{$policyId}'>
                            <button class='btn btn-sm btn-outline-danger'>Delete</button>
                          </form>
                        </td>
                      </tr>";
                }
            }
            if ($overrideRows === '') {
                $overrideRows = "<tr><td colspan='5' class='text-muted'>No overrides configured.</td></tr>";
            }

            $renderScopeOptions = function (array $selected, string $name, string $prefix) use ($scopeCatalog): string {
                $options = '';
                foreach ($scopeCatalog as $scope => $desc) {
                    $checked = in_array($scope, $selected, true) ? 'checked' : '';
                    $label = htmlspecialchars($scope);
                    $descText = htmlspecialchars($desc);
                    $options .= "<div class='form-check'>
                        <input class='form-check-input' type='checkbox' name='{$name}[]' value='{$label}' id='{$prefix}-{$label}' {$checked}>
                        <label class='form-check-label' for='{$prefix}-{$label}'>{$label} <span class='text-muted small'>— {$descText}</span></label>
                      </div>";
                }
                return $options;
            };

            $requiredOptions = $renderScopeOptions($required, 'required_scopes', 'req');
            $optionalOptions = $renderScopeOptions($optional, 'optional_scopes', 'opt');
            $overrideRequiredOptions = $renderScopeOptions([], 'override_required_scopes', 'ovr-req');
            $overrideOptionalOptions = $renderScopeOptions([], 'override_optional_scopes', 'ovr-opt');

            $impactRows = $app->db->one(
                "SELECT COUNT(*) AS total,
                        SUM(status='COMPLIANT') AS compliant,
                        SUM(status='MISSING_SCOPES') AS missing_scopes,
                        SUM(status='TOKEN_EXPIRED') AS token_expired,
                        SUM(status='TOKEN_INVALID') AS token_invalid
                 FROM module_corptools_character_scope_status"
            );
            $impactTotal = (int)($impactRows['total'] ?? 0);
            $impactMissing = (int)($impactRows['missing_scopes'] ?? 0);
            $impactExpired = (int)($impactRows['token_expired'] ?? 0);
            $impactInvalid = (int)($impactRows['token_invalid'] ?? 0);

            $nameValue = htmlspecialchars((string)($selectedPolicy['name'] ?? ''));
            $descValue = htmlspecialchars((string)($selectedPolicy['description'] ?? ''));
            $appliesTo = (string)($selectedPolicy['applies_to'] ?? 'all_users');
            $activeChecked = !empty($selectedPolicy['is_active']) ? 'checked' : '';

            $sectionHtml = "{$flashHtml}<div class='card card-body mt-3'>
                <div class='fw-semibold mb-2'>Impact analysis</div>
                <div class='text-muted small'>Based on the latest scope checks.</div>
                <div class='row g-2 mt-2'>
                  <div class='col-md-3'><div class='card card-body'><div class='text-muted small'>Characters tracked</div><div class='fw-semibold'>{$impactTotal}</div></div></div>
                  <div class='col-md-3'><div class='card card-body'><div class='text-muted small'>Missing scopes</div><div class='fw-semibold'>{$impactMissing}</div></div></div>
                  <div class='col-md-3'><div class='card card-body'><div class='text-muted small'>Token expired</div><div class='fw-semibold'>{$impactExpired}</div></div></div>
                  <div class='col-md-3'><div class='card card-body'><div class='text-muted small'>Token invalid</div><div class='fw-semibold'>{$impactInvalid}</div></div></div>
                </div>
              </div>
              <form method='post' action='/admin/corptools/scope-policy/save' class='card card-body mt-3'>
                <input type='hidden' name='policy_id' value='{$policyId}'>
                <div class='row g-2'>
                  <div class='col-md-6'>
                    <label class='form-label'>Policy name</label>
                    <input class='form-control' name='name' value='{$nameValue}' required>
                  </div>
                  <div class='col-md-6'>
                    <label class='form-label'>Applies to</label>
                    <select class='form-select' name='applies_to'>
                      <option value='all_users'" . ($appliesTo === 'all_users' ? ' selected' : '') . ">All users</option>
                      <option value='corp_members'" . ($appliesTo === 'corp_members' ? ' selected' : '') . ">Corp members</option>
                      <option value='alliance_members'" . ($appliesTo === 'alliance_members' ? ' selected' : '') . ">Alliance members</option>
                    </select>
                  </div>
                </div>
                <label class='form-label mt-3'>Description</label>
                <textarea class='form-control' name='description' rows='2'>{$descValue}</textarea>
                <div class='form-check mt-3'>
                  <input class='form-check-input' type='checkbox' name='is_active' value='1' id='policy-active' {$activeChecked}>
                  <label class='form-check-label' for='policy-active'>Set as active policy</label>
                </div>
                <div class='row g-3 mt-3'>
                  <div class='col-md-6'>
                    <div class='fw-semibold mb-1'>Required scopes</div>
                    {$requiredOptions}
                  </div>
                  <div class='col-md-6'>
                    <div class='fw-semibold mb-1'>Optional scopes</div>
                    {$optionalOptions}
                  </div>
                </div>
                <button class='btn btn-primary mt-3'>Save Scope Policy</button>
              </form>
              <div class='card card-body mt-3'>
                <div class='fw-semibold mb-2'>Existing policies</div>
                <div class='table-responsive'>
                  <table class='table table-sm align-middle'>
                    <thead><tr><th>Name</th><th>Applies to</th><th>Status</th><th></th></tr></thead>
                    <tbody>{$policyRows}</tbody>
                  </table>
                </div>
              </div>
              <div class='card card-body mt-3'>
                <div class='fw-semibold mb-2'>Policy overrides</div>
                <div class='text-muted small'>Overrides add scopes for specific users or groups without altering the base policy.</div>
                <form method='post' action='/admin/corptools/scope-policy/override' class='row g-2 mt-2'>
                  <input type='hidden' name='policy_id' value='{$policyId}'>
                  <div class='col-md-3'>
                    <label class='form-label'>Target type</label>
                    <select class='form-select' name='target_type'>
                      <option value='user'>User</option>
                      <option value='group'>Group</option>
                    </select>
                  </div>
                  <div class='col-md-4'>
                    <label class='form-label'>User ID</label>
                    <input class='form-control' name='target_user_id' placeholder='User ID'>
                    <div class='form-text'>Use for per-user overrides.</div>
                  </div>
                  <div class='col-md-5'>
                    <label class='form-label'>Group</label>
                    <select class='form-select' name='target_group_id'>{$groupOptions}</select>
                    <div class='form-text'>Use for HR/director groups.</div>
                  </div>
                  <div class='col-12'>
                    <div class='fw-semibold mt-2'>Override required scopes</div>
                    {$overrideRequiredOptions}
                  </div>
                  <div class='col-12'>
                    <div class='fw-semibold mt-2'>Override optional scopes</div>
                    {$overrideOptionalOptions}
                  </div>
                  <div class='col-12'>
                    <button class='btn btn-outline-light mt-2'>Add override</button>
                  </div>
                </form>
                <div class='table-responsive mt-3'>
                  <table class='table table-sm align-middle'>
                    <thead><tr><th>Type</th><th>Target</th><th>Required scopes</th><th>Optional scopes</th><th></th></tr></thead>
                    <tbody>{$overrideRows}</tbody>
                  </table>
                </div>
              </div>";
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
            $enabled = !empty($settings['invoices']['enabled']) ? 'checked' : '';
            $divisions = htmlspecialchars(implode(',', $settings['invoices']['wallet_divisions'] ?? [1]));
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='invoices'>
                <div class='form-check mb-2'>
                  <input class='form-check-input' type='checkbox' name='enabled' value='1' id='invoices-enabled' {$enabled}>
                  <label class='form-check-label' for='invoices-enabled'>Enable invoice tracking</label>
                </div>
                <label class='form-label'>Wallet divisions (comma-separated)</label>
                <input class='form-control' name='wallet_divisions' value='{$divisions}'>
                <button class='btn btn-primary mt-3'>Save Invoice Settings</button>
              </form>";
        } elseif ($tab === 'moons') {
            $enabled = !empty($settings['moons']['enabled']) ? 'checked' : '';
            $tax = htmlspecialchars((string)($settings['moons']['default_tax_rate'] ?? 0));
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='moons'>
                <div class='form-check mb-2'>
                  <input class='form-check-input' type='checkbox' name='enabled' value='1' id='moons-enabled' {$enabled}>
                  <label class='form-check-label' for='moons-enabled'>Enable moon tracking</label>
                </div>
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
            if (!$hasRight('corptools.pinger.manage')) {
                return Response::text('403 Forbidden', 403);
            }
            $enabled = !empty($settings['pinger']['enabled']) ? 'checked' : '';
            $webhook = htmlspecialchars((string)($settings['pinger']['webhook_url'] ?? ''));
            $secret = htmlspecialchars((string)($settings['pinger']['shared_secret'] ?? ''));
            $sectionHtml = "<form method='post' action='/admin/corptools/settings' class='card card-body mt-3'>
                <input type='hidden' name='section' value='pinger'>
                <div class='form-check mb-2'>
                  <input class='form-check-input' type='checkbox' name='enabled' value='1' id='pinger-enabled' {$enabled}>
                  <label class='form-check-label' for='pinger-enabled'>Enable pinger ingestion</label>
                </div>
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

    $registry->route('POST', '/admin/corptools/settings', function (Request $req) use ($app, $hasRight): Response {
        $section = (string)($req->post['section'] ?? 'general');
        $settings = new CorpToolsSettings($app->db);

        if ($section === 'general') {
            $divisions = array_values(array_filter(array_map('trim', explode(',', (string)($req->post['holding_wallet_divisions'] ?? '')))));
            $divisions = array_map('intval', $divisions);
            $corpIds = $req->post['corp_ids'] ?? [];
            if (!is_array($corpIds)) $corpIds = [];
            $corpIds = array_values(array_filter(array_map('intval', $corpIds), fn(int $id) => $id > 0));
            $corpContextId = (int)($req->post['corp_context_id'] ?? 0);
            if ($corpContextId > 0 && !empty($corpIds) && !in_array($corpContextId, $corpIds, true)) {
                $corpContextId = 0;
            }
            $settings->updateSection('general', [
                'corp_ids' => $corpIds,
                'corp_context_id' => $corpContextId,
                'allow_context_switch' => !empty($req->post['allow_context_switch']),
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
                'enabled' => !empty($req->post['enabled']),
                'wallet_divisions' => $divisions ?: [1],
            ]);
        } elseif ($section === 'moons') {
            $settings->updateSection('moons', [
                'enabled' => !empty($req->post['enabled']),
                'default_tax_rate' => (float)($req->post['default_tax_rate'] ?? 0),
            ]);
        } elseif ($section === 'indy') {
            $settings->updateSection('indy', [
                'enabled' => !empty($req->post['enabled']),
            ]);
        } elseif ($section === 'pinger') {
            if (!$hasRight('corptools.pinger.manage')) {
                return Response::text('403 Forbidden', 403);
            }
            $settings->updateSection('pinger', [
                'enabled' => !empty($req->post['enabled']),
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

    $registry->route('POST', '/admin/corptools/scope-policy/save', function (Request $req) use ($app, $scopePolicy): Response {
        $policyId = (int)($req->post['policy_id'] ?? 0);
        $name = trim((string)($req->post['name'] ?? ''));
        $description = trim((string)($req->post['description'] ?? ''));
        $appliesTo = (string)($req->post['applies_to'] ?? 'all_users');
        $isActive = !empty($req->post['is_active']) ? 1 : 0;
        $required = $req->post['required_scopes'] ?? [];
        $optional = $req->post['optional_scopes'] ?? [];
        if (!is_array($required)) $required = [];
        if (!is_array($optional)) $optional = [];
        $required = array_values(array_unique(array_filter($required, 'is_string')));
        $optional = array_values(array_unique(array_filter($optional, 'is_string')));

        if ($name === '') {
            $_SESSION['corptools_scope_flash'] = ['type' => 'warning', 'message' => 'Policy name is required.'];
            return Response::redirect('/admin/corptools?tab=scope_policy');
        }

        $payloadRequired = json_encode($required, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadOptional = json_encode($optional, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($policyId > 0) {
            $app->db->run(
                "UPDATE corp_scope_policies
                 SET name=?, description=?, applies_to=?, required_scopes_json=?, optional_scopes_json=?, is_active=?
                 WHERE id=?",
                [$name, $description, $appliesTo, $payloadRequired, $payloadOptional, $isActive, $policyId]
            );
        } else {
            $app->db->run(
                "INSERT INTO corp_scope_policies
                 (name, description, applies_to, required_scopes_json, optional_scopes_json, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [$name, $description, $appliesTo, $payloadRequired, $payloadOptional, $isActive]
            );
            $row = $app->db->one("SELECT LAST_INSERT_ID() AS id");
            $policyId = (int)($row['id'] ?? 0);
        }

        if ($isActive === 1 && $policyId > 0) {
            $app->db->run(
                "UPDATE corp_scope_policies SET is_active=0 WHERE applies_to=? AND id<>?",
                [$appliesTo, $policyId]
            );
        }

        $_SESSION['corptools_scope_flash'] = ['type' => 'success', 'message' => 'Scope policy saved.'];
        return Response::redirect('/admin/corptools?tab=scope_policy&policy_id=' . $policyId);
    }, ['right' => 'corptools.admin']);

    $registry->route('POST', '/admin/corptools/scope-policy/override', function (Request $req) use ($app): Response {
        $policyId = (int)($req->post['policy_id'] ?? 0);
        $targetType = (string)($req->post['target_type'] ?? 'user');
        $targetUserId = (int)($req->post['target_user_id'] ?? 0);
        $targetGroupId = (int)($req->post['target_group_id'] ?? 0);
        $required = $req->post['override_required_scopes'] ?? [];
        $optional = $req->post['override_optional_scopes'] ?? [];
        if (!is_array($required)) $required = [];
        if (!is_array($optional)) $optional = [];
        $required = array_values(array_unique(array_filter($required, 'is_string')));
        $optional = array_values(array_unique(array_filter($optional, 'is_string')));

        $targetId = $targetType === 'group' ? $targetGroupId : $targetUserId;
        if ($policyId <= 0 || $targetId <= 0) {
            $_SESSION['corptools_scope_flash'] = ['type' => 'warning', 'message' => 'Select a valid override target.'];
            return Response::redirect('/admin/corptools?tab=scope_policy&policy_id=' . $policyId);
        }

        $app->db->run(
            "INSERT INTO corp_scope_policy_overrides
             (policy_id, target_type, target_id, required_scopes_json, optional_scopes_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $policyId,
                $targetType === 'group' ? 'group' : 'user',
                $targetId,
                json_encode($required, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($optional, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );

        $_SESSION['corptools_scope_flash'] = ['type' => 'success', 'message' => 'Scope override added.'];
        return Response::redirect('/admin/corptools?tab=scope_policy&policy_id=' . $policyId);
    }, ['right' => 'corptools.admin']);

    $registry->route('POST', '/admin/corptools/scope-policy/override/delete', function (Request $req) use ($app): Response {
        $overrideId = (int)($req->post['override_id'] ?? 0);
        $policyId = (int)($req->post['policy_id'] ?? 0);
        if ($overrideId > 0) {
            $app->db->run("DELETE FROM corp_scope_policy_overrides WHERE id=?", [$overrideId]);
        }
        $_SESSION['corptools_scope_flash'] = ['type' => 'success', 'message' => 'Scope override removed.'];
        return Response::redirect('/admin/corptools?tab=scope_policy&policy_id=' . $policyId);
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
    }, ['right' => 'corptools.pinger.manage']);
};
