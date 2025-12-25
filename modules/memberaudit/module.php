<?php
declare(strict_types=1);

/*
Module Name: Member Audit
Description: AllianceAuth-style member audit with self-service and leadership lanes.
Version: 1.0.0
Module Slug: memberaudit
*/

use App\Core\App;
use App\Core\EsiCache;
use App\Core\EsiClient;
use App\Core\EveSso;
use App\Core\HttpClient;
use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Universe;
use App\Corptools\Audit\ScopeAuditService;
use App\Corptools\Cron\JobRegistry;
use App\Corptools\ScopePolicy;
use App\Corptools\Settings as CorpToolsSettings;
use App\Http\Request;
use App\Http\Response;

return function (ModuleRegistry $registry): void {
    $app = $registry->app();
    $moduleVersion = '1.0.0';

    $registry->right('memberaudit.view', 'Access the Member Audit self-service tools.');
    $registry->right('memberaudit.leadership', 'Access Member Audit leadership dashboards.');
    $registry->right('memberaudit.skillsets.manage', 'Manage Member Audit skill sets.');
    $registry->right('memberaudit.recruiter', 'View applicant share audit links.');

    $registry->menu([
        'slug' => 'memberaudit.tools',
        'title' => 'Member Audit',
        'url' => '/memberaudit',
        'sort_order' => 46,
        'area' => 'left',
        'right_slug' => 'memberaudit.view',
    ]);
    $registry->menu([
        'slug' => 'memberaudit.self',
        'title' => 'My Audit',
        'url' => '/memberaudit',
        'sort_order' => 47,
        'area' => 'left',
        'parent_slug' => 'memberaudit.tools',
        'right_slug' => 'memberaudit.view',
    ]);
    $registry->menu([
        'slug' => 'memberaudit.authorize',
        'title' => 'Authorize/Upgrade Scopes',
        'url' => '/memberaudit/authorize',
        'sort_order' => 48,
        'area' => 'left',
        'parent_slug' => 'memberaudit.tools',
        'right_slug' => 'memberaudit.view',
    ]);
    $registry->menu([
        'slug' => 'memberaudit.share',
        'title' => 'Applicant Share',
        'url' => '/memberaudit/share',
        'sort_order' => 49,
        'area' => 'left',
        'parent_slug' => 'memberaudit.tools',
        'right_slug' => 'memberaudit.view',
    ]);

    $registry->menu([
        'slug' => 'admin.memberaudit',
        'title' => 'Member Audit',
        'url' => '/admin/memberaudit',
        'sort_order' => 55,
        'area' => 'admin_top',
        'right_slug' => 'memberaudit.leadership',
    ]);
    $registry->menu([
        'slug' => 'admin.memberaudit.members',
        'title' => 'Members',
        'url' => '/admin/memberaudit',
        'sort_order' => 56,
        'area' => 'admin_top',
        'parent_slug' => 'admin.memberaudit',
        'right_slug' => 'memberaudit.leadership',
    ]);
    $registry->menu([
        'slug' => 'admin.memberaudit.skill_sets',
        'title' => 'Skill Sets',
        'url' => '/admin/memberaudit/skill-sets',
        'sort_order' => 57,
        'area' => 'admin_top',
        'parent_slug' => 'admin.memberaudit',
        'right_slug' => 'memberaudit.skillsets.manage',
    ]);
    $registry->menu([
        'slug' => 'admin.memberaudit.reports',
        'title' => 'Reports',
        'url' => '/admin/memberaudit/reports',
        'sort_order' => 58,
        'area' => 'admin_top',
        'parent_slug' => 'admin.memberaudit',
        'right_slug' => 'memberaudit.leadership',
    ]);

    $renderPage = function (string $title, string $bodyHtml) use ($app): string {
        $rights = new Rights($app->db);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $hasRight = function (string $right) use ($rights, $uid): bool {
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

    $scopePolicy = new ScopePolicy($app->db, new Universe($app->db));
    $scopeAudit = new ScopeAuditService($app->db);

    $memberAuditScopes = [
        'assets' => ['esi-assets.read_assets.v1'],
        'bio' => [],
        'contacts' => ['esi-characters.read_contacts.v1'],
        'contracts' => ['esi-contracts.read_character_contracts.v1'],
        'corp_history' => [],
        'corp_roles' => ['esi-characters.read_corporation_roles.v1'],
        'fw_stats' => ['esi-characters.read_fw_stats.v1'],
        'implants' => ['esi-clones.read_implants.v1'],
        'jump_clones' => ['esi-clones.read_clones.v1'],
        'mails' => ['esi-mail.read_mail.v1'],
        'mining' => ['esi-industry.read_character_mining.v1'],
        'loyalty' => ['esi-characters.read_loyalty.v1'],
        'skills' => ['esi-skills.read_skills.v1'],
        'skill_queue' => ['esi-skills.read_skillqueue.v1'],
        'wallet_journal' => ['esi-wallet.read_character_wallet.v1'],
        'wallet_transactions' => ['esi-wallet.read_character_wallet.v1'],
    ];

    $memberAuditCategories = [
        'assets' => [
            'label' => 'Assets',
            'endpoint' => '/latest/characters/{character_id}/assets/',
            'ttl' => 3600,
        ],
        'bio' => [
            'label' => 'Bio',
            'endpoint' => '/latest/characters/{character_id}/',
            'ttl' => 86400,
        ],
        'contacts' => [
            'label' => 'Contacts',
            'endpoint' => '/latest/characters/{character_id}/contacts/',
            'ttl' => 7200,
        ],
        'contracts' => [
            'label' => 'Contracts',
            'endpoint' => '/latest/characters/{character_id}/contracts/',
            'ttl' => 7200,
        ],
        'corp_history' => [
            'label' => 'Corp History',
            'endpoint' => '/latest/characters/{character_id}/corporationhistory/',
            'ttl' => 86400,
        ],
        'corp_roles' => [
            'label' => 'Corp Roles',
            'endpoint' => '/latest/characters/{character_id}/roles/',
            'ttl' => 7200,
        ],
        'fw_stats' => [
            'label' => 'FW Stats',
            'endpoint' => '/latest/characters/{character_id}/fw/stats/',
            'ttl' => 10800,
        ],
        'implants' => [
            'label' => 'Implants',
            'endpoint' => '/latest/characters/{character_id}/implants/',
            'ttl' => 10800,
        ],
        'jump_clones' => [
            'label' => 'Jump Clones',
            'endpoint' => '/latest/characters/{character_id}/clones/',
            'ttl' => 10800,
        ],
        'mails' => [
            'label' => 'Mails',
            'endpoint' => '/latest/characters/{character_id}/mail/',
            'ttl' => 3600,
        ],
        'mining' => [
            'label' => 'Mining Ledger',
            'endpoint' => '/latest/characters/{character_id}/mining/',
            'ttl' => 7200,
        ],
        'loyalty' => [
            'label' => 'Loyalty Points',
            'endpoint' => '/latest/characters/{character_id}/loyalty/points/',
            'ttl' => 10800,
        ],
        'skills' => [
            'label' => 'Skills',
            'endpoint' => '/latest/characters/{character_id}/skills/',
            'ttl' => 10800,
        ],
        'skill_queue' => [
            'label' => 'Skill Queue',
            'endpoint' => '/latest/characters/{character_id}/skillqueue/',
            'ttl' => 3600,
        ],
        'wallet_journal' => [
            'label' => 'Wallet Journal',
            'endpoint' => '/latest/characters/{character_id}/wallet/journal/',
            'ttl' => 3600,
        ],
        'wallet_transactions' => [
            'label' => 'Wallet Transactions',
            'endpoint' => '/latest/characters/{character_id}/wallet/transactions/',
            'ttl' => 3600,
        ],
    ];

    $logAccess = function (
        string $accessType,
        ?int $viewerUserId,
        ?int $characterId,
        ?int $tokenId,
        string $scope
    ) use ($app): void {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $app->db->run(
            "INSERT INTO module_memberaudit_access_log\n"
            . " (access_type, viewer_user_id, character_id, token_id, scope, ip_address, user_agent, accessed_at)\n"
            . " VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [$accessType, $viewerUserId, $characterId, $tokenId, $scope, $ip, $ua]
        );
    };

    $resolveEntityName = function (Universe $universe, string $type, int $id): string {
        if ($id <= 0) return '—';
        $name = $universe->name($type, $id);
        $idMarker = '#' . $id;
        if ($name === '' || str_contains($name, $idMarker)) {
            return 'Unknown';
        }
        return $name;
    };
    $resolveCharacterName = function (Universe $universe, int $characterId): string {
        if ($characterId <= 0) return 'Unknown';
        $profile = $universe->characterProfile($characterId);
        return (string)($profile['character']['name'] ?? 'Unknown');
    };

    $renderAuditData = function (Universe $universe, string $category, array $payload) use ($resolveEntityName): string {
        if (empty($payload)) {
            return "<div class='card card-body text-muted'>No cached data yet.</div>";
        }

        $rows = '';
        if ($category === 'assets') {
            foreach ($payload as $row) {
                $typeName = htmlspecialchars($resolveEntityName($universe, 'type', (int)($row['type_id'] ?? 0)));
                $qty = number_format((int)($row['quantity'] ?? 0));
                $locationType = (string)($row['location_type'] ?? '');
                $locationId = (int)($row['location_id'] ?? 0);
                $locEntity = match ($locationType) {
                    'station' => 'station',
                    'structure' => 'structure',
                    'solar_system' => 'system',
                    default => 'structure',
                };
                $locationName = htmlspecialchars($resolveEntityName($universe, $locEntity, $locationId));
                $rows .= "<tr><td>{$typeName}</td><td class='text-end'>{$qty}</td><td>{$locationName}</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='3' class='text-muted'>No assets cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Item</th><th class='text-end'>Qty</th><th>Location</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        if ($category === 'bio') {
            $bio = htmlspecialchars((string)($payload['description'] ?? ''));
            $birthday = htmlspecialchars((string)($payload['birthday'] ?? '—'));
            $gender = htmlspecialchars((string)($payload['gender'] ?? '—'));
            $raceId = (int)($payload['race_id'] ?? 0);
            $bloodlineId = (int)($payload['bloodline_id'] ?? 0);
            $corpId = (int)($payload['corporation_id'] ?? 0);
            $allianceId = (int)($payload['alliance_id'] ?? 0);
            $raceName = htmlspecialchars($resolveEntityName($universe, 'race', $raceId));
            $bloodlineName = htmlspecialchars($resolveEntityName($universe, 'bloodline', $bloodlineId));
            $corpName = htmlspecialchars($resolveEntityName($universe, 'corporation', $corpId));
            $allianceName = htmlspecialchars($resolveEntityName($universe, 'alliance', $allianceId));
            return "<div class='row g-3'>
                <div class='col-md-6'><div class='card card-body'><div class='fw-semibold'>Bio</div><div class='text-muted small mt-2'>" . nl2br($bio) . "</div></div></div>
                <div class='col-md-6'>
                  <div class='card card-body'>
                    <div class='row'>
                      <div class='col-6'><div class='text-muted small'>Birthday</div><div>{$birthday}</div></div>
                      <div class='col-6'><div class='text-muted small'>Gender</div><div>{$gender}</div></div>
                    </div>
                    <div class='row mt-2'>
                      <div class='col-6'><div class='text-muted small'>Race</div><div>{$raceName}</div></div>
                      <div class='col-6'><div class='text-muted small'>Bloodline</div><div>{$bloodlineName}</div></div>
                    </div>
                    <div class='row mt-2'>
                      <div class='col-6'><div class='text-muted small'>Corporation</div><div>{$corpName}</div></div>
                      <div class='col-6'><div class='text-muted small'>Alliance</div><div>{$allianceName}</div></div>
                    </div>
                  </div>
                </div>
              </div>";
        }

        if ($category === 'contacts') {
            foreach ($payload as $row) {
                $contactType = (string)($row['contact_type'] ?? 'character');
                $contactId = (int)($row['contact_id'] ?? 0);
                $name = htmlspecialchars($resolveEntityName($universe, $contactType, $contactId));
                $standing = htmlspecialchars((string)($row['standing'] ?? '0'));
                $rows .= "<tr><td>{$name}</td><td>{$contactType}</td><td class='text-end'>{$standing}</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='3' class='text-muted'>No contacts cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Contact</th><th>Type</th><th class='text-end'>Standing</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        if ($category === 'contracts') {
            foreach ($payload as $row) {
                $issuer = htmlspecialchars($resolveEntityName($universe, 'character', (int)($row['issuer_id'] ?? 0)));
                $assignee = htmlspecialchars($resolveEntityName($universe, 'character', (int)($row['assignee_id'] ?? 0)));
                $status = htmlspecialchars((string)($row['status'] ?? ''));
                $title = htmlspecialchars((string)($row['title'] ?? ''));
                $rows .= "<tr><td>{$title}</td><td>{$issuer}</td><td>{$assignee}</td><td>{$status}</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='4' class='text-muted'>No contracts cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Title</th><th>Issuer</th><th>Assignee</th><th>Status</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        if ($category === 'corp_history') {
            foreach ($payload as $row) {
                $corp = htmlspecialchars($resolveEntityName($universe, 'corporation', (int)($row['corporation_id'] ?? 0)));
                $start = htmlspecialchars((string)($row['start_date'] ?? ''));
                $rows .= "<tr><td>{$corp}</td><td>{$start}</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='2' class='text-muted'>No corp history cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Corporation</th><th>Start Date</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        if ($category === 'corp_roles') {
            $roles = [];
            foreach (['roles', 'roles_at_hq', 'roles_at_base', 'roles_at_other'] as $key) {
                $list = $payload[$key] ?? [];
                if (!is_array($list) || empty($list)) continue;
                $roles[$key] = array_map('strval', $list);
            }
            if (empty($roles)) {
                return "<div class='card card-body text-muted'>No corp roles cached.</div>";
            }
            $blocks = '';
            foreach ($roles as $bucket => $list) {
                $items = '';
                foreach ($list as $role) {
                    $items .= "<li>" . htmlspecialchars(str_replace('_', ' ', $role)) . "</li>";
                }
                $label = htmlspecialchars(ucwords(str_replace('_', ' ', $bucket)));
                $blocks .= "<div class='col-md-6'><div class='card card-body'>
                    <div class='fw-semibold'>{$label}</div><ul class='mb-0 mt-2'>{$items}</ul></div></div>";
            }
            return "<div class='row g-3'>{$blocks}</div>";
        }

        if ($category === 'fw_stats') {
            $factionName = htmlspecialchars($resolveEntityName($universe, 'faction', (int)($payload['faction_id'] ?? 0)));
            $kills = (int)($payload['kills']['yesterday'] ?? 0);
            return "<div class='card card-body'>
                <div class='fw-semibold'>Faction Warfare</div>
                <div class='text-muted'>Faction: {$factionName}</div>
                <div class='mt-2'>Kills yesterday: {$kills}</div>
              </div>";
        }

        if ($category === 'implants') {
            foreach ($payload as $typeId) {
                $rows .= "<li>" . htmlspecialchars($resolveEntityName($universe, 'type', (int)$typeId)) . "</li>";
            }
            if ($rows === '') {
                return "<div class='card card-body text-muted'>No implants cached.</div>";
            }
            return "<ul class='mb-0'>{$rows}</ul>";
        }

        if ($category === 'jump_clones') {
            $rows = '';
            foreach (($payload['jump_clones'] ?? []) as $row) {
                $locationId = (int)($row['location_id'] ?? 0);
                $locationName = htmlspecialchars($resolveEntityName($universe, 'structure', $locationId));
                $implants = $row['implants'] ?? [];
                $implantsText = '';
                if (is_array($implants) && !empty($implants)) {
                    $names = [];
                    foreach ($implants as $typeId) {
                        $names[] = $resolveEntityName($universe, 'type', (int)$typeId);
                    }
                    $implantsText = htmlspecialchars(implode(', ', $names));
                }
                $rows .= "<tr><td>{$locationName}</td><td>" . htmlspecialchars($implantsText ?: '—') . "</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='2' class='text-muted'>No jump clones cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Location</th><th>Implants</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        if ($category === 'mails') {
            foreach ($payload as $row) {
                $from = htmlspecialchars($resolveEntityName($universe, 'character', (int)($row['from'] ?? 0)));
                $subject = htmlspecialchars((string)($row['subject'] ?? ''));
                $timestamp = htmlspecialchars((string)($row['timestamp'] ?? ''));
                $rows .= "<tr><td>{$subject}</td><td>{$from}</td><td>{$timestamp}</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='3' class='text-muted'>No mail headers cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Subject</th><th>From</th><th>Date</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        if ($category === 'mining') {
            foreach ($payload as $row) {
                $typeName = htmlspecialchars($resolveEntityName($universe, 'type', (int)($row['type_id'] ?? 0)));
                $systemName = htmlspecialchars($resolveEntityName($universe, 'system', (int)($row['solar_system_id'] ?? 0)));
                $qty = number_format((int)($row['quantity'] ?? 0));
                $rows .= "<tr><td>{$typeName}</td><td>{$systemName}</td><td class='text-end'>{$qty}</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='3' class='text-muted'>No mining ledger cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Ore</th><th>System</th><th class='text-end'>Quantity</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        if ($category === 'loyalty') {
            foreach ($payload as $row) {
                $corpName = htmlspecialchars($resolveEntityName($universe, 'corporation', (int)($row['corporation_id'] ?? 0)));
                $points = number_format((int)($row['loyalty_points'] ?? 0));
                $rows .= "<tr><td>{$corpName}</td><td class='text-end'>{$points}</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='2' class='text-muted'>No loyalty points cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Corporation</th><th class='text-end'>LP</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        if ($category === 'skills') {
            foreach (($payload['skills'] ?? []) as $row) {
                $skillName = htmlspecialchars($resolveEntityName($universe, 'type', (int)($row['skill_id'] ?? 0)));
                $level = (int)($row['active_skill_level'] ?? 0);
                $sp = number_format((int)($row['skillpoints_in_skill'] ?? 0));
                $rows .= "<tr><td>{$skillName}</td><td class='text-end'>{$level}</td><td class='text-end'>{$sp}</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='3' class='text-muted'>No skills cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Skill</th><th class='text-end'>Level</th><th class='text-end'>SP</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        if ($category === 'skill_queue') {
            foreach ($payload as $row) {
                $skillName = htmlspecialchars($resolveEntityName($universe, 'type', (int)($row['skill_id'] ?? 0)));
                $level = (int)($row['finished_level'] ?? 0);
                $end = htmlspecialchars((string)($row['finish_date'] ?? ''));
                $rows .= "<tr><td>{$skillName}</td><td class='text-end'>{$level}</td><td>{$end}</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='3' class='text-muted'>No skill queue cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Skill</th><th class='text-end'>Level</th><th>Finish</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        if ($category === 'wallet_journal' || $category === 'wallet_transactions') {
            foreach ($payload as $row) {
                $amount = htmlspecialchars(number_format((float)($row['amount'] ?? 0), 2));
                $balance = htmlspecialchars(number_format((float)($row['balance'] ?? 0), 2));
                $refType = htmlspecialchars((string)($row['ref_type'] ?? $row['transaction_type'] ?? ''));
                $first = (int)($row['first_party_id'] ?? $row['client_id'] ?? 0);
                $second = (int)($row['second_party_id'] ?? $row['character_id'] ?? 0);
                $firstName = htmlspecialchars($resolveEntityName($universe, 'character', $first));
                $secondName = htmlspecialchars($resolveEntityName($universe, 'character', $second));
                $date = htmlspecialchars((string)($row['date'] ?? ''));
                $rows .= "<tr><td>{$date}</td><td>{$refType}</td><td>{$firstName}</td><td>{$secondName}</td><td class='text-end'>{$amount}</td><td class='text-end'>{$balance}</td></tr>";
            }
            if ($rows === '') {
                $rows = "<tr><td colspan='6' class='text-muted'>No wallet entries cached.</td></tr>";
            }
            return "<table class='table table-sm table-striped'>
                <thead><tr><th>Date</th><th>Type</th><th>First Party</th><th>Second Party</th><th class='text-end'>Amount</th><th class='text-end'>Balance</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
        }

        return "<pre class='small text-muted'>" . htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT)) . "</pre>";
    };

    $fetchMemberCharacters = function (int $userId) use ($app): array {
        $rows = [];
        $main = $app->db->one(
            "SELECT character_id, character_name FROM eve_users WHERE id=? LIMIT 1",
            [$userId]
        );
        $mainId = (int)($main['character_id'] ?? 0);
        if ($mainId > 0) {
            $rows[] = [
                'character_id' => $mainId,
                'character_name' => (string)($main['character_name'] ?? 'Unknown'),
                'is_main' => true,
            ];
        }

        $links = $app->db->all(
            "SELECT character_id, character_name, linked_at
             FROM character_links
             WHERE user_id=? AND status='linked'
             ORDER BY linked_at ASC",
            [$userId]
        );
        foreach ($links as $link) {
            $cid = (int)($link['character_id'] ?? 0);
            if ($cid <= 0 || $cid === $mainId) continue;
            $rows[] = [
                'character_id' => $cid,
                'character_name' => (string)($link['character_name'] ?? 'Unknown'),
                'is_main' => false,
                'linked_at' => (string)($link['linked_at'] ?? ''),
            ];
        }

        return $rows;
    };

    $fetchAuditSnapshot = function (int $characterId) use ($app, $memberAuditCategories): array {
        $categories = array_keys($memberAuditCategories);
        if (empty($categories)) return [];
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $params = array_merge([$characterId], $categories);
        $rows = $app->db->all(
            "SELECT category, data_json, fetched_at
             FROM module_corptools_character_audit
             WHERE character_id=? AND category IN ({$placeholders})",
            $params
        );
        $map = [];
        foreach ($rows as $row) {
            $category = (string)($row['category'] ?? '');
            if ($category === '') continue;
            $decoded = json_decode((string)($row['data_json'] ?? '[]'), true);
            if (!is_array($decoded)) $decoded = [];
            $map[$category] = [
                'data' => $decoded,
                'fetched_at' => (string)($row['fetched_at'] ?? ''),
            ];
        }
        return $map;
    };

    $tokenBucketForCharacter = function (int $characterId) use ($app): ?array {
        return $app->db->one(
            "SELECT user_id, access_token, refresh_token, expires_at, scopes_json, status, error_last
             FROM eve_token_buckets
             WHERE character_id=? AND bucket='member_audit' AND org_type='character' AND org_id=0
             LIMIT 1",
            [$characterId]
        );
    };

    $renderAuthorizeFlow = function (int $userId, array $characters, array $scopeSet) use ($app, $tokenBucketForCharacter): string {
        $required = $scopeSet['required'] ?? [];
        $optional = $scopeSet['optional'] ?? [];
        $required = array_values(array_unique(array_filter($required, 'is_string')));
        $optional = array_values(array_unique(array_filter($optional, 'is_string')));
        $requiredBadge = "<span class='badge bg-primary'>Required</span>";

        $rows = '';
        $missingCount = 0;
        foreach ($characters as $character) {
            $characterId = (int)($character['character_id'] ?? 0);
            $token = $tokenBucketForCharacter($characterId);
            $scopes = json_decode((string)($token['scopes_json'] ?? '[]'), true);
            if (!is_array($scopes)) $scopes = [];
            $missing = array_values(array_diff($required, $scopes));
            $status = empty($token) ? 'Missing token' : (empty($missing) ? 'OK' : 'Missing scopes');
            if (!empty($missing)) $missingCount++;
            $badge = empty($missing) ? "<span class='badge bg-success'>OK</span>" : "<span class='badge bg-warning text-dark'>Missing</span>";
            $missingText = empty($missing) ? '—' : htmlspecialchars(implode(', ', $missing));
            $rows .= "<tr>
                <td>" . htmlspecialchars((string)($character['character_name'] ?? 'Unknown')) . "</td>
                <td>{$badge}</td>
                <td class='small text-muted'>{$status}</td>
                <td class='small text-muted'>{$missingText}</td>
                <td class='text-end'>
                  <a class='btn btn-sm btn-outline-primary' href='/memberaudit/authorize/start'>Authorize</a>
                </td>
              </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='5' class='text-muted'>No linked characters found.</td></tr>";
        }

        $requiredList = '';
        foreach ($required as $scope) {
            $requiredList .= '<li><code>' . htmlspecialchars($scope) . '</code></li>';
        }
        if ($requiredList === '') {
            $requiredList = "<li class='text-muted'>No required scopes set.</li>";
        }
        $optionalList = '';
        foreach ($optional as $scope) {
            $optionalList .= '<li><code>' . htmlspecialchars($scope) . '</code></li>';
        }
        if ($optionalList === '') {
            $optionalList = "<li class='text-muted'>No optional scopes set.</li>";
        }

        $flowNote = $missingCount > 0
            ? "<div class='alert alert-info mt-3'>
                 <div class='fw-semibold'>Authorize all characters</div>
                 <div class='text-muted'>Click authorize, select each character in SSO, and return here to continue the flow. Missing scopes cannot be downgraded.</div>
               </div>"
            : "<div class='alert alert-success mt-3'>All linked characters have the required scopes.</div>";

        return "<div class='card card-body mb-3'>
            <div class='fw-semibold mb-2'>Member Audit Scopes {$requiredBadge}</div>
            <div class='row g-3'>
              <div class='col-md-6'><div class='text-muted small'>Required</div><ul>{$requiredList}</ul></div>
              <div class='col-md-6'><div class='text-muted small'>Optional</div><ul>{$optionalList}</ul></div>
            </div>
          </div>
          <div class='card card-body'>
            <div class='fw-semibold mb-2'>Linked Characters</div>
            <table class='table table-sm'>
              <thead><tr><th>Character</th><th>Status</th><th>Details</th><th>Missing scopes</th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>
          {$flowNote}";
    };

    $registry->route('GET', '/memberaudit', function (Request $req) use ($app, $renderPage, $fetchMemberCharacters, $scopePolicy, $logAccess): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return Response::redirect('/auth/login');

        $characters = $fetchMemberCharacters($uid);
        $universe = new Universe($app->db);
        $rows = '';
        foreach ($characters as $character) {
            $characterId = (int)($character['character_id'] ?? 0);
            if ($characterId <= 0) continue;
            $profile = $universe->characterProfile($characterId);
            $name = htmlspecialchars((string)($profile['character']['name'] ?? $character['character_name'] ?? 'Unknown'));
            $corpName = htmlspecialchars((string)($profile['corporation']['name'] ?? '—'));
            $allianceName = htmlspecialchars((string)($profile['alliance']['name'] ?? '—'));
            $rows .= "<tr>
                <td>{$name}</td>
                <td>{$corpName}</td>
                <td>{$allianceName}</td>
                <td class='text-end'><a class='btn btn-sm btn-outline-primary' href='/memberaudit/character/{$characterId}'>View Audit</a></td>
              </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='4' class='text-muted'>No linked characters found.</td></tr>";
        }

        $scopeSet = $scopePolicy->getEffectiveScopesForUser($uid);
        $scopeBadge = $scopeSet['policy'] ? "<span class='badge bg-success'>Policy Active</span>" : "<span class='badge bg-secondary'>No Policy</span>";

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
            <div>
              <h1 class='mb-1'>My Member Audit</h1>
              <div class='text-muted'>Review cached audit data for your linked characters.</div>
            </div>
            <div>{$scopeBadge}</div>
          </div>
          <div class='card card-body mt-3'>
            <div class='fw-semibold mb-2'>Characters</div>
            <table class='table table-sm'>
              <thead><tr><th>Character</th><th>Corporation</th><th>Alliance</th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>";

        $logAccess('self', $uid, null, null, 'memberaudit.self');
        return Response::html($renderPage('Member Audit', $body), 200);
    }, ['right' => 'memberaudit.view']);

    $registry->route('GET', '/memberaudit/authorize', function () use ($app, $renderPage, $fetchMemberCharacters, $scopePolicy, $renderAuthorizeFlow, $logAccess): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return Response::redirect('/auth/login');
        $characters = $fetchMemberCharacters($uid);
        $scopeSet = $scopePolicy->getEffectiveScopesForUser($uid);
        $body = "<h1 class='mb-2'>Authorize / Upgrade Scopes</h1>
            <div class='text-muted mb-3'>Admin-defined scope policy drives the required permissions. You cannot downgrade scopes.</div>"
            . $renderAuthorizeFlow($uid, $characters, $scopeSet);
        $logAccess('self', $uid, null, null, 'memberaudit.authorize');
        return Response::html($renderPage('Authorize Scopes', $body), 200);
    }, ['right' => 'memberaudit.view']);

    $registry->route('GET', '/memberaudit/authorize/start', function () use ($app, $scopePolicy): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return Response::redirect('/auth/login');
        $scopeSet = $scopePolicy->getEffectiveScopesForUser($uid);
        $requiredScopes = $scopeSet['required'] ?? [];
        $optionalScopes = $scopeSet['optional'] ?? [];
        $_SESSION['sso_scopes_override'] = array_values(array_unique(array_merge($requiredScopes, $optionalScopes)));
        $_SESSION['sso_token_bucket'] = 'member_audit';
        $_SESSION['sso_org_context'] = ['org_type' => 'character', 'org_id' => 0];
        $_SESSION['charlink_redirect'] = '/memberaudit/authorize';
        return Response::redirect('/auth/login');
    }, ['right' => 'memberaudit.view']);

    $registry->route('GET', '/memberaudit/character/{id}', function (Request $req) use ($app, $renderPage, $fetchMemberCharacters, $fetchAuditSnapshot, $memberAuditCategories, $renderAuditData, $logAccess): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return Response::redirect('/auth/login');
        $characterId = (int)($req->params['id'] ?? 0);
        $allowed = false;
        $characters = $fetchMemberCharacters($uid);
        foreach ($characters as $character) {
            if ((int)($character['character_id'] ?? 0) === $characterId) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return Response::html($renderPage('Member Audit', "<div class='alert alert-warning'>Character not found.</div>"), 404);
        }

        $universe = new Universe($app->db);
        $profile = $universe->characterProfile($characterId);
        $name = htmlspecialchars((string)($profile['character']['name'] ?? 'Unknown'));
        $tabs = '';
        $active = (string)($req->query['tab'] ?? 'assets');
        if (!isset($memberAuditCategories[$active])) {
            $active = array_key_first($memberAuditCategories);
        }
        foreach ($memberAuditCategories as $key => $meta) {
            $label = htmlspecialchars((string)($meta['label'] ?? $key));
            $isActive = $key === $active ? 'active' : '';
            $tabs .= "<li class='nav-item'><a class='nav-link {$isActive}' href='/memberaudit/character/{$characterId}?tab={$key}'>{$label}</a></li>";
        }

        $snapshots = $fetchAuditSnapshot($characterId);
        $payload = $snapshots[$active]['data'] ?? [];
        $fetchedAt = htmlspecialchars((string)($snapshots[$active]['fetched_at'] ?? '—'));
        $content = $renderAuditData($universe, $active, $payload);

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
            <div>
              <h1 class='mb-1'>Audit: {$name}</h1>
              <div class='text-muted'>Cached audit data. Last fetched: {$fetchedAt}</div>
            </div>
            <div>
              <a class='btn btn-outline-secondary' href='/memberaudit'>Back</a>
            </div>
          </div>
          <ul class='nav nav-tabs mt-3'>{$tabs}</ul>
          <div class='mt-3'>{$content}</div>";
        $logAccess('self', $uid, $characterId, null, 'memberaudit.detail');
        return Response::html($renderPage('Member Audit', $body), 200);
    }, ['right' => 'memberaudit.view']);

    $registry->route('GET', '/memberaudit/share', function () use ($app, $renderPage, $fetchMemberCharacters, $logAccess): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return Response::redirect('/auth/login');
        $characters = $fetchMemberCharacters($uid);
        $options = '';
        foreach ($characters as $character) {
            $cid = (int)($character['character_id'] ?? 0);
            $name = htmlspecialchars((string)($character['character_name'] ?? 'Unknown'));
            $options .= "<option value='{$cid}'>{$name}</option>";
        }

        $rows = '';
        $tokens = $app->db->all(
            "SELECT id, token_prefix, expires_at, created_at
             FROM module_memberaudit_share_tokens
             WHERE user_id=?
             ORDER BY created_at DESC",
            [$uid]
        );
        foreach ($tokens as $token) {
            $prefix = htmlspecialchars((string)($token['token_prefix'] ?? ''));
            $expiresAt = htmlspecialchars((string)($token['expires_at'] ?? '—'));
            $shareUrl = "/memberaudit/share/" . rawurlencode($prefix);
            $rows .= "<tr><td>{$prefix}</td><td>{$expiresAt}</td><td><a href='{$shareUrl}' class='btn btn-sm btn-outline-primary'>Open</a></td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='3' class='text-muted'>No share tokens created yet.</td></tr>";
        }

        $body = "<h1 class='mb-2'>Applicant Share</h1>
          <div class='text-muted mb-3'>Generate time-limited audit links for recruiters without granting permanent rights.</div>
          <div class='card card-body mb-3'>
            <form method='post' action='/memberaudit/share/create'>
              <label class='form-label'>Select characters to share</label>
              <select class='form-select' name='characters[]' multiple size='5'>{$options}</select>
              <div class='row mt-3'>
                <div class='col-md-4'>
                  <label class='form-label'>Expires in (hours)</label>
                  <input class='form-control' name='expires' type='number' value='72' min='1' max='720'>
                </div>
              </div>
              <button class='btn btn-primary mt-3'>Generate Share Link</button>
            </form>
          </div>
          <div class='card card-body'>
            <div class='fw-semibold mb-2'>Existing Links</div>
            <table class='table table-sm'>
              <thead><tr><th>Token</th><th>Expires</th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>";

        $logAccess('self', $uid, null, null, 'memberaudit.share');
        return Response::html($renderPage('Applicant Share', $body), 200);
    }, ['right' => 'memberaudit.view']);

    $registry->route('POST', '/memberaudit/share/create', function (Request $req) use ($app): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return Response::redirect('/auth/login');
        $characters = $req->post['characters'] ?? [];
        if (!is_array($characters)) $characters = [];
        $characters = array_values(array_unique(array_filter(array_map('intval', $characters))));
        if (empty($characters)) {
            return Response::redirect('/memberaudit/share');
        }

        $expiresHours = max(1, min(720, (int)($req->post['expires'] ?? 72)));
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresHours * 3600));

        $tokenPrefix = bin2hex(random_bytes(8));
        $tokenHash = hash('sha256', $tokenPrefix);

        $app->db->run(
            "INSERT INTO module_memberaudit_share_tokens (token_hash, token_prefix, user_id, expires_at, created_at)
             VALUES (?, ?, ?, ?, NOW())",
            [$tokenHash, $tokenPrefix, $uid, $expiresAt]
        );
        $tokenId = (int)$app->db->lastInsertId();
        foreach ($characters as $characterId) {
            $app->db->run(
                "INSERT INTO module_memberaudit_share_token_characters (token_id, character_id)
                 VALUES (?, ?)",
                [$tokenId, $characterId]
            );
        }

        return Response::redirect('/memberaudit/share');
    }, ['right' => 'memberaudit.view']);

    $registry->route('GET', '/memberaudit/share/{token}', function (Request $req) use ($app, $renderPage, $fetchAuditSnapshot, $memberAuditCategories, $renderAuditData, $resolveCharacterName, $logAccess): Response {
        $tokenValue = (string)($req->params['token'] ?? '');
        if ($tokenValue === '') {
            return Response::text('Missing token', 400);
        }
        $tokenHash = hash('sha256', $tokenValue);
        $token = $app->db->one(
            "SELECT id, user_id, expires_at
             FROM module_memberaudit_share_tokens
             WHERE token_hash=? LIMIT 1",
            [$tokenHash]
        );
        if (!$token) {
            return Response::html($renderPage('Applicant Share', "<div class='alert alert-warning'>Invalid or expired share token.</div>"), 403);
        }
        $expiresAt = $token['expires_at'] ? strtotime((string)$token['expires_at']) : null;
        if ($expiresAt !== null && time() > $expiresAt) {
            return Response::html($renderPage('Applicant Share', "<div class='alert alert-warning'>Share token expired.</div>"), 403);
        }

        $tokenId = (int)($token['id'] ?? 0);
        $characterRows = $app->db->all(
            "SELECT character_id FROM module_memberaudit_share_token_characters WHERE token_id=?",
            [$tokenId]
        );
        $characterIds = array_values(array_unique(array_map(fn($row) => (int)($row['character_id'] ?? 0), $characterRows)));
        if (empty($characterIds)) {
            return Response::html($renderPage('Applicant Share', "<div class='alert alert-warning'>No characters linked to this token.</div>"), 404);
        }
        $selectedId = (int)($req->query['character_id'] ?? $characterIds[0]);
        if (!in_array($selectedId, $characterIds, true)) {
            $selectedId = $characterIds[0];
        }

        $universe = new Universe($app->db);
        $profile = $universe->characterProfile($selectedId);
        $name = htmlspecialchars((string)($profile['character']['name'] ?? 'Unknown'));

        $active = (string)($req->query['tab'] ?? 'assets');
        if (!isset($memberAuditCategories[$active])) {
            $active = array_key_first($memberAuditCategories);
        }
        $tabs = '';
        foreach ($memberAuditCategories as $key => $meta) {
            $label = htmlspecialchars((string)($meta['label'] ?? $key));
            $isActive = $key === $active ? 'active' : '';
            $tabs .= "<li class='nav-item'><a class='nav-link {$isActive}' href='/memberaudit/share/" . rawurlencode($tokenValue) . "?character_id={$selectedId}&tab={$key}'>{$label}</a></li>";
        }

        $snapshots = $fetchAuditSnapshot($selectedId);
        $payload = $snapshots[$active]['data'] ?? [];
        $fetchedAt = htmlspecialchars((string)($snapshots[$active]['fetched_at'] ?? '—'));
        $content = $renderAuditData($universe, $active, $payload);

        $characterOptions = '';
        foreach ($characterIds as $characterId) {
            $characterName = htmlspecialchars($resolveCharacterName($universe, $characterId));
            $selected = $characterId === $selectedId ? 'selected' : '';
            $characterOptions .= "<option value='{$characterId}' {$selected}>{$characterName}</option>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
            <div>
              <h1 class='mb-1'>Applicant Share</h1>
              <div class='text-muted'>Viewing cached audit data for {$name}. Last fetched: {$fetchedAt}</div>
            </div>
          </div>
          <form method='get' class='mt-3'>
            <input type='hidden' name='tab' value='{$active}'>
            <label class='form-label'>Character</label>
            <select class='form-select' name='character_id' onchange='this.form.submit()'>
              {$characterOptions}
            </select>
          </form>
          <ul class='nav nav-tabs mt-3'>{$tabs}</ul>
          <div class='mt-3'>{$content}</div>";

        $logAccess('share', null, $selectedId, $tokenId, 'memberaudit.share_view');
        return Response::html($renderPage('Applicant Share', $body), 200);
    }, ['public' => true]);

    $registry->route('GET', '/admin/memberaudit', function (Request $req) use ($app, $renderPage, $resolveEntityName, $logAccess): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $search = trim((string)($req->query['q'] ?? ''));
        $params = [];
        $whereParts = [];
        if ($search !== '') {
            $whereParts[] = "u.character_name LIKE ?";
            $params[] = '%' . $search . '%';
        }
        $corpSettings = new CorpToolsSettings($app->db);
        $settings = $corpSettings->get();
        $corpIds = array_values(array_filter(array_map('intval', $settings['general']['corp_ids'] ?? [])));
        if (!empty($corpIds)) {
            $placeholders = implode(',', array_fill(0, count($corpIds), '?'));
            $whereParts[] = "ms.corp_id IN ({$placeholders})";
            $params = array_merge($params, $corpIds);
        }
        $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

        $rows = $app->db->all(
            "SELECT u.id AS user_id, u.character_name, u.character_id, ms.corp_id, ms.alliance_id, ms.audit_loaded, ms.last_login_at
             FROM eve_users u
             LEFT JOIN module_corptools_member_summary ms ON ms.user_id=u.id
             {$where}
             ORDER BY u.character_name ASC",
            $params
        );

        $universe = new Universe($app->db);
        $bodyRows = '';
        foreach ($rows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            $characterId = (int)($row['character_id'] ?? 0);
            $characterName = htmlspecialchars((string)($row['character_name'] ?? 'Unknown'));
            $corpName = htmlspecialchars($resolveEntityName($universe, 'corporation', (int)($row['corp_id'] ?? 0)));
            $allianceName = htmlspecialchars($resolveEntityName($universe, 'alliance', (int)($row['alliance_id'] ?? 0)));
            $auditLoaded = (int)($row['audit_loaded'] ?? 0) === 1 ? "<span class='badge bg-success'>Loaded</span>" : "<span class='badge bg-warning text-dark'>Missing</span>";
            $lastLogin = htmlspecialchars((string)($row['last_login_at'] ?? '—'));
            $bodyRows .= "<tr>
                <td>{$characterName}</td>
                <td>{$corpName}</td>
                <td>{$allianceName}</td>
                <td>{$auditLoaded}</td>
                <td>{$lastLogin}</td>
                <td class='text-end'><a class='btn btn-sm btn-outline-primary' href='/admin/memberaudit/member/{$userId}'>Review</a></td>
              </tr>";
        }
        if ($bodyRows === '') {
            $bodyRows = "<tr><td colspan='6' class='text-muted'>No members found.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
            <div>
              <h1 class='mb-1'>Member Audit - Leadership</h1>
              <div class='text-muted'>Review cached audits for corp/alliance members.</div>
            </div>
            <form method='get' class='d-flex gap-2'>
              <input class='form-control' name='q' placeholder='Search members' value='" . htmlspecialchars($search) . "'>
              <button class='btn btn-outline-secondary'>Search</button>
            </form>
          </div>
          <div class='card card-body mt-3'>
            <table class='table table-sm'>
              <thead><tr><th>Main</th><th>Corporation</th><th>Alliance</th><th>Audit</th><th>Last login</th><th></th></tr></thead>
              <tbody>{$bodyRows}</tbody>
            </table>
          </div>";

        $logAccess('leadership', $uid, null, null, 'memberaudit.list');
        return Response::html($renderPage('Member Audit', $body), 200);
    }, ['right' => 'memberaudit.leadership']);

    $registry->route('GET', '/admin/memberaudit/member/{id}', function (Request $req) use ($app, $renderPage, $fetchAuditSnapshot, $memberAuditCategories, $renderAuditData, $resolveCharacterName, $logAccess): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $memberId = (int)($req->params['id'] ?? 0);
        if ($memberId <= 0) {
            return Response::redirect('/admin/memberaudit');
        }
        $main = $app->db->one("SELECT character_id, character_name FROM eve_users WHERE id=? LIMIT 1", [$memberId]);
        if (!$main) {
            return Response::html($renderPage('Member Audit', "<div class='alert alert-warning'>Member not found.</div>"), 404);
        }
        $corpSettings = new CorpToolsSettings($app->db);
        $settings = $corpSettings->get();
        $corpIds = array_values(array_filter(array_map('intval', $settings['general']['corp_ids'] ?? [])));
        if (!empty($corpIds)) {
            $memberCorp = $app->db->one(
                "SELECT corp_id FROM module_corptools_member_summary WHERE user_id=? LIMIT 1",
                [$memberId]
            );
            $corpId = (int)($memberCorp['corp_id'] ?? 0);
            if ($corpId > 0 && !in_array($corpId, $corpIds, true)) {
                return Response::html($renderPage('Member Audit', "<div class='alert alert-warning'>Member not in scoped corp/alliance.</div>"), 403);
            }
        }
        $mainId = (int)($main['character_id'] ?? 0);
        $characters = $app->db->all(
            "SELECT character_id, character_name FROM character_links WHERE user_id=? AND status='linked'
             UNION
             SELECT character_id, character_name FROM eve_users WHERE id=?",
            [$memberId, $memberId]
        );
        $characterIds = array_values(array_unique(array_map(fn($row) => (int)($row['character_id'] ?? 0), $characters)));
        if (empty($characterIds)) {
            return Response::html($renderPage('Member Audit', "<div class='alert alert-warning'>No characters linked.</div>"), 404);
        }
        $selectedId = (int)($req->query['character_id'] ?? $mainId);
        if (!in_array($selectedId, $characterIds, true)) {
            $selectedId = $characterIds[0];
        }

        $universe = new Universe($app->db);
        $profile = $universe->characterProfile($selectedId);
        $name = htmlspecialchars((string)($profile['character']['name'] ?? 'Unknown'));
        $tabs = '';
        $active = (string)($req->query['tab'] ?? 'assets');
        if (!isset($memberAuditCategories[$active])) {
            $active = array_key_first($memberAuditCategories);
        }
        foreach ($memberAuditCategories as $key => $meta) {
            $label = htmlspecialchars((string)($meta['label'] ?? $key));
            $isActive = $key === $active ? 'active' : '';
            $tabs .= "<li class='nav-item'><a class='nav-link {$isActive}' href='/admin/memberaudit/member/{$memberId}?character_id={$selectedId}&tab={$key}'>{$label}</a></li>";
        }

        $snapshots = $fetchAuditSnapshot($selectedId);
        $payload = $snapshots[$active]['data'] ?? [];
        $fetchedAt = htmlspecialchars((string)($snapshots[$active]['fetched_at'] ?? '—'));
        $content = $renderAuditData($universe, $active, $payload);

        $characterOptions = '';
        foreach ($characterIds as $characterId) {
            $characterName = htmlspecialchars($resolveCharacterName($universe, $characterId));
            $selected = $characterId === $selectedId ? 'selected' : '';
            $characterOptions .= "<option value='{$characterId}' {$selected}>{$characterName}</option>";
        }

        $skillSets = $app->db->all(
            "SELECT id, name FROM module_memberaudit_skill_sets ORDER BY name ASC"
        );
        $assignedRows = $app->db->all(
            "SELECT skill_set_id FROM module_memberaudit_skill_set_assignments WHERE character_id=?",
            [$selectedId]
        );
        $assignedIds = array_values(array_unique(array_map(fn($row) => (int)($row['skill_set_id'] ?? 0), $assignedRows)));
        $assignOptions = '';
        foreach ($skillSets as $set) {
            $setId = (int)($set['id'] ?? 0);
            $name = htmlspecialchars((string)($set['name'] ?? 'Skill Set'));
            $selected = in_array($setId, $assignedIds, true) ? 'selected' : '';
            $assignOptions .= "<option value='{$setId}' {$selected}>{$name}</option>";
        }

        $skillsRows = $app->db->all(
            "SELECT skill_id, trained_level FROM module_corptools_character_skills WHERE character_id=?",
            [$selectedId]
        );
        $skillMap = [];
        foreach ($skillsRows as $row) {
            $skillMap[(int)($row['skill_id'] ?? 0)] = (int)($row['trained_level'] ?? 0);
        }
        $complianceRows = '';
        foreach ($skillSets as $set) {
            $setId = (int)($set['id'] ?? 0);
            if (!in_array($setId, $assignedIds, true)) continue;
            $reqRows = $app->db->all(
                "SELECT skill_id, required_level FROM module_memberaudit_skill_set_skills WHERE skill_set_id=?",
                [$setId]
            );
            $missing = [];
            foreach ($reqRows as $req) {
                $skillId = (int)($req['skill_id'] ?? 0);
                $level = (int)($req['required_level'] ?? 0);
                $trained = (int)($skillMap[$skillId] ?? 0);
                if ($trained < $level) {
                    $missing[] = $universe->name('type', $skillId);
                }
            }
            $status = empty($missing)
                ? "<span class='badge bg-success'>Compliant</span>"
                : "<span class='badge bg-warning text-dark'>Missing</span>";
            $missingText = empty($missing) ? '—' : htmlspecialchars(implode(', ', $missing));
            $complianceRows .= "<tr><td>" . htmlspecialchars((string)($set['name'] ?? '')) . "</td><td>{$status}</td><td class='small text-muted'>{$missingText}</td></tr>";
        }
        if ($complianceRows === '') {
            $complianceRows = "<tr><td colspan='3' class='text-muted'>No skill sets assigned.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
            <div>
              <h1 class='mb-1'>Member Audit: {$name}</h1>
              <div class='text-muted'>Cached audit data. Last fetched: {$fetchedAt}</div>
            </div>
            <div><a class='btn btn-outline-secondary' href='/admin/memberaudit'>Back</a></div>
          </div>
          <form method='get' class='mt-3'>
            <input type='hidden' name='tab' value='{$active}'>
            <label class='form-label'>Character</label>
            <select class='form-select' name='character_id' onchange='this.form.submit()'>
              {$characterOptions}
            </select>
          </form>
          <div class='row g-3 mt-2'>
            <div class='col-lg-6'>
              <div class='card card-body'>
                <div class='fw-semibold mb-2'>Assign Skill Sets</div>
                <form method='post' action='/admin/memberaudit/member/{$memberId}/assign'>
                  <input type='hidden' name='character_id' value='{$selectedId}'>
                  <select class='form-select' name='skill_sets[]' multiple size='5'>{$assignOptions}</select>
                  <button class='btn btn-outline-primary mt-3'>Save Assignments</button>
                </form>
              </div>
            </div>
            <div class='col-lg-6'>
              <div class='card card-body'>
                <div class='fw-semibold mb-2'>Skill Set Compliance</div>
                <table class='table table-sm'>
                  <thead><tr><th>Skill Set</th><th>Status</th><th>Missing Skills</th></tr></thead>
                  <tbody>{$complianceRows}</tbody>
                </table>
              </div>
            </div>
          </div>
          <ul class='nav nav-tabs mt-3'>{$tabs}</ul>
          <div class='mt-3'>{$content}</div>";

        $logAccess('leadership', $uid, $selectedId, null, 'memberaudit.member');
        return Response::html($renderPage('Member Audit', $body), 200);
    }, ['right' => 'memberaudit.leadership']);

    $registry->route('POST', '/admin/memberaudit/member/{id}/assign', function (Request $req) use ($app): Response {
        $memberId = (int)($req->params['id'] ?? 0);
        $characterId = (int)($req->post['character_id'] ?? 0);
        if ($memberId <= 0 || $characterId <= 0) {
            return Response::redirect('/admin/memberaudit');
        }
        $skillSets = $req->post['skill_sets'] ?? [];
        if (!is_array($skillSets)) $skillSets = [];
        $skillSets = array_values(array_unique(array_filter(array_map('intval', $skillSets))));

        $app->db->run("DELETE FROM module_memberaudit_skill_set_assignments WHERE character_id=?", [$characterId]);
        foreach ($skillSets as $setId) {
            $app->db->run(
                "INSERT INTO module_memberaudit_skill_set_assignments (skill_set_id, character_id, assigned_by, assigned_at)
                 VALUES (?, ?, ?, NOW())",
                [$setId, $characterId, (int)($_SESSION['user_id'] ?? 0)]
            );
        }
        return Response::redirect("/admin/memberaudit/member/{$memberId}?character_id={$characterId}");
    }, ['right' => 'memberaudit.skillsets.manage']);

    $registry->route('GET', '/admin/memberaudit/skill-sets', function () use ($app, $renderPage, $logAccess): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $sets = $app->db->all(
            "SELECT id, name, description, source_type, source_id, created_at
             FROM module_memberaudit_skill_sets
             ORDER BY created_at DESC"
        );
        $rows = '';
        foreach ($sets as $set) {
            $setId = (int)($set['id'] ?? 0);
            $name = htmlspecialchars((string)($set['name'] ?? ''));
            $description = htmlspecialchars((string)($set['description'] ?? ''));
            $sourceType = htmlspecialchars((string)($set['source_type'] ?? 'manual'));
            $rows .= "<tr>
                <td>{$name}</td>
                <td>{$description}</td>
                <td>{$sourceType}</td>
                <td class='text-end'>
                  <form method='post' action='/admin/memberaudit/skill-sets/delete' onsubmit=\"return confirm('Delete skill set?')\">
                    <input type='hidden' name='id' value='{$setId}'>
                    <button class='btn btn-sm btn-outline-danger'>Delete</button>
                  </form>
                </td>
              </tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='4' class='text-muted'>No skill sets created yet.</td></tr>";
        }

        $fits = $app->db->all("SELECT id, name FROM module_fittings_fits ORDER BY updated_at DESC LIMIT 100");
        $fitOptions = '';
        foreach ($fits as $fit) {
            $fitId = (int)($fit['id'] ?? 0);
            $fitName = htmlspecialchars((string)($fit['name'] ?? 'Fit'));
            $fitOptions .= "<option value='{$fitId}'>{$fitName}</option>";
        }

        $body = "<h1 class='mb-2'>Skill Sets</h1>
          <div class='text-muted mb-3'>Create or import skill sets and assign them to members.</div>
          <div class='row g-3'>
            <div class='col-lg-6'>
              <div class='card card-body'>
                <div class='fw-semibold mb-2'>Create Skill Set</div>
                <form method='post' action='/admin/memberaudit/skill-sets/create'>
                  <label class='form-label'>Name</label>
                  <input class='form-control' name='name' required>
                  <label class='form-label mt-2'>Description</label>
                  <textarea class='form-control' name='description' rows='3'></textarea>
                  <button class='btn btn-primary mt-3'>Create</button>
                </form>
              </div>
            </div>
            <div class='col-lg-6'>
              <div class='card card-body'>
                <div class='fw-semibold mb-2'>Import from Fittings</div>
                <form method='post' action='/admin/memberaudit/skill-sets/import'>
                  <label class='form-label'>Fit</label>
                  <select class='form-select' name='fit_id'>{$fitOptions}</select>
                  <button class='btn btn-outline-primary mt-3'>Generate Skill Set</button>
                </form>
              </div>
            </div>
          </div>
          <div class='card card-body mt-3'>
            <div class='fw-semibold mb-2'>Existing Skill Sets</div>
            <table class='table table-sm'>
              <thead><tr><th>Name</th><th>Description</th><th>Source</th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>";

        $logAccess('leadership', $uid, null, null, 'memberaudit.skillsets');
        return Response::html($renderPage('Skill Sets', $body), 200);
    }, ['right' => 'memberaudit.skillsets.manage']);

    $registry->route('POST', '/admin/memberaudit/skill-sets/create', function (Request $req) use ($app): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $name = trim((string)($req->post['name'] ?? ''));
        if ($uid <= 0 || $name === '') {
            return Response::redirect('/admin/memberaudit/skill-sets');
        }
        $desc = trim((string)($req->post['description'] ?? ''));
        $app->db->run(
            "INSERT INTO module_memberaudit_skill_sets (name, description, source_type, source_id, created_by, created_at, updated_at)
             VALUES (?, ?, 'manual', 0, ?, NOW(), NOW())",
            [$name, $desc, $uid]
        );
        return Response::redirect('/admin/memberaudit/skill-sets');
    }, ['right' => 'memberaudit.skillsets.manage']);

    $registry->route('POST', '/admin/memberaudit/skill-sets/delete', function (Request $req) use ($app): Response {
        $setId = (int)($req->post['id'] ?? 0);
        if ($setId > 0) {
            $app->db->run("DELETE FROM module_memberaudit_skill_sets WHERE id=?", [$setId]);
            $app->db->run("DELETE FROM module_memberaudit_skill_set_skills WHERE skill_set_id=?", [$setId]);
            $app->db->run("DELETE FROM module_memberaudit_skill_set_assignments WHERE skill_set_id=?", [$setId]);
        }
        return Response::redirect('/admin/memberaudit/skill-sets');
    }, ['right' => 'memberaudit.skillsets.manage']);

    $registry->route('POST', '/admin/memberaudit/skill-sets/import', function (Request $req) use ($app): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $fitId = (int)($req->post['fit_id'] ?? 0);
        if ($uid <= 0 || $fitId <= 0) {
            return Response::redirect('/admin/memberaudit/skill-sets');
        }
        $fit = $app->db->one("SELECT id, name, parsed_json FROM module_fittings_fits WHERE id=? LIMIT 1", [$fitId]);
        if (!$fit) {
            return Response::redirect('/admin/memberaudit/skill-sets');
        }
        $parsed = json_decode((string)($fit['parsed_json'] ?? '[]'), true);
        if (!is_array($parsed)) $parsed = [];
        $items = $parsed['items'] ?? [];
        if (!is_array($items)) $items = [];

        $app->db->run(
            "INSERT INTO module_memberaudit_skill_sets (name, description, source_type, source_id, created_by, created_at, updated_at)
             VALUES (?, ?, 'fitting', ?, ?, NOW(), NOW())",
            [(string)($fit['name'] ?? 'Fit Skill Set'), 'Generated from fittings module.', $fitId, $uid]
        );
        $setId = (int)$app->db->lastInsertId();

        $universe = new Universe($app->db);
        $skills = [];
        foreach ($items as $item) {
            $typeId = (int)($item['type_id'] ?? 0);
            if ($typeId <= 0) continue;
            $type = $universe->entity('type', $typeId);
            $extra = json_decode((string)($type['extra_json'] ?? '[]'), true);
            if (!is_array($extra)) $extra = [];
            $dogma = $extra['dogma_attributes'] ?? [];
            if (!is_array($dogma)) $dogma = [];
            $map = [];
            foreach ($dogma as $attr) {
                $attrId = (int)($attr['attribute_id'] ?? 0);
                $value = (int)($attr['value'] ?? 0);
                $map[$attrId] = $value;
            }
            $pairs = [
                ['skill' => 182, 'level' => 277],
                ['skill' => 183, 'level' => 278],
                ['skill' => 184, 'level' => 279],
            ];
            foreach ($pairs as $pair) {
                $skillId = (int)($map[$pair['skill']] ?? 0);
                $level = (int)($map[$pair['level']] ?? 0);
                if ($skillId <= 0 || $level <= 0) continue;
                $skills[$skillId] = max($skills[$skillId] ?? 0, $level);
            }
        }
        foreach ($skills as $skillId => $level) {
            $app->db->run(
                "INSERT INTO module_memberaudit_skill_set_skills (skill_set_id, skill_id, required_level)
                 VALUES (?, ?, ?)",
                [$setId, $skillId, $level]
            );
        }
        return Response::redirect('/admin/memberaudit/skill-sets');
    }, ['right' => 'memberaudit.skillsets.manage']);

    $registry->route('GET', '/admin/memberaudit/reports', function () use ($app, $renderPage, $logAccess): Response {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $compliance = $app->db->one(
            "SELECT COUNT(*) AS total, SUM(audit_loaded) AS loaded
             FROM module_corptools_member_summary"
        );
        $total = (int)($compliance['total'] ?? 0);
        $loaded = (int)($compliance['loaded'] ?? 0);
        $missing = max(0, $total - $loaded);

        $skillSets = $app->db->all(
            "SELECT s.id, s.name, COUNT(a.character_id) AS assigned
             FROM module_memberaudit_skill_sets s
             LEFT JOIN module_memberaudit_skill_set_assignments a ON a.skill_set_id=s.id
             GROUP BY s.id, s.name
             ORDER BY s.name ASC"
        );
        $rows = '';
        foreach ($skillSets as $set) {
            $setId = (int)($set['id'] ?? 0);
            $assigned = (int)($set['assigned'] ?? 0);
            $compliant = 0;
            if ($assigned > 0) {
                $required = $app->db->all(
                    "SELECT skill_id, required_level FROM module_memberaudit_skill_set_skills WHERE skill_set_id=?",
                    [$setId]
                );
                $requiredMap = [];
                foreach ($required as $req) {
                    $requiredMap[(int)($req['skill_id'] ?? 0)] = (int)($req['required_level'] ?? 0);
                }
                $assignments = $app->db->all(
                    "SELECT character_id FROM module_memberaudit_skill_set_assignments WHERE skill_set_id=?",
                    [$setId]
                );
                foreach ($assignments as $assignment) {
                    $characterId = (int)($assignment['character_id'] ?? 0);
                    if ($characterId <= 0) continue;
                    $skillsRows = $app->db->all(
                        "SELECT skill_id, trained_level FROM module_corptools_character_skills WHERE character_id=?",
                        [$characterId]
                    );
                    $skillMap = [];
                    foreach ($skillsRows as $row) {
                        $skillMap[(int)($row['skill_id'] ?? 0)] = (int)($row['trained_level'] ?? 0);
                    }
                    $missing = false;
                    foreach ($requiredMap as $skillId => $level) {
                        if (($skillMap[$skillId] ?? 0) < $level) {
                            $missing = true;
                            break;
                        }
                    }
                    if (!$missing) {
                        $compliant++;
                    }
                }
            }
            $rows .= "<tr><td>" . htmlspecialchars((string)($set['name'] ?? '')) . "</td><td class='text-end'>{$assigned}</td><td class='text-end'>{$compliant}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='3' class='text-muted'>No skill sets defined.</td></tr>";
        }

        $body = "<h1 class='mb-2'>Member Audit Reports</h1>
          <div class='text-muted mb-3'>Compliance and skill set coverage reporting.</div>
          <div class='row g-3'>
            <div class='col-md-4'>
              <div class='card card-body'>
                <div class='fw-semibold'>Compliance</div>
                <div class='text-muted small'>All characters registered</div>
                <div class='display-6 mt-2'>{$loaded} / {$total}</div>
                <div class='text-muted small'>Missing audits: {$missing}</div>
              </div>
            </div>
            <div class='col-md-8'>
              <div class='card card-body'>
                <div class='fw-semibold mb-2'>Skill Set Coverage</div>
                <table class='table table-sm'>
                  <thead><tr><th>Skill Set</th><th class='text-end'>Assigned</th><th class='text-end'>Compliant</th></tr></thead>
                  <tbody>{$rows}</tbody>
                </table>
              </div>
            </div>
          </div>";

        $logAccess('leadership', $uid, null, null, 'memberaudit.reports');
        return Response::html($renderPage('Member Audit Reports', $body), 200);
    }, ['right' => 'memberaudit.leadership']);

    $refreshAuditCache = function (App $app, array $context = []) use (
        $memberAuditCategories,
        $memberAuditScopes,
        $scopePolicy,
        $scopeAudit
    ): array {
        $cfg = $app->config['eve_sso'] ?? [];
        $sso = new EveSso($app->db, $cfg);
        $esi = new EsiCache($app->db, new EsiClient(new HttpClient()));

        $rows = $app->db->all(
            "SELECT b.user_id, b.character_id, u.character_name
             FROM eve_token_buckets b
             LEFT JOIN eve_users u ON u.id=b.user_id
             WHERE b.bucket='member_audit' AND b.org_type='character' AND b.org_id=0"
        );
        if (empty($rows)) {
            return ['message' => 'No member audit tokens found.'];
        }

        $metrics = ['audits_run' => 0, 'audits_failed' => 0];
        foreach ($rows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            $characterId = (int)($row['character_id'] ?? 0);
            if ($userId <= 0 || $characterId <= 0) continue;

            $scopeSet = $scopePolicy->getEffectiveScopesForUser($userId);
            $requiredScopes = $scopeSet['required'] ?? [];
            $optionalScopes = $scopeSet['optional'] ?? [];
            $policyId = $scopeSet['policy']['id'] ?? null;

            $token = $sso->getAccessTokenForCharacter($characterId, 'member_audit', ['org_type' => 'character', 'org_id' => 0]);
            $scopeAudit->evaluate(
                $userId,
                $characterId,
                $token,
                $requiredScopes,
                $optionalScopes,
                is_numeric($policyId) ? (int)$policyId : null
            );

            if (!empty($token['expired']) || empty($token['access_token'])) {
                $metrics['audits_failed']++;
                continue;
            }

            $granted = $token['scopes'] ?? [];
            if (!is_array($granted)) $granted = [];
            $missingRequired = array_values(array_diff($requiredScopes, $granted));
            if (!empty($missingRequired)) {
                $scopeAudit->logEvent('missing_required_scopes', $userId, $characterId, [
                    'missing' => $missingRequired,
                ]);
                $metrics['audits_failed']++;
                continue;
            }

            $accessToken = (string)($token['access_token'] ?? '');
            $refreshCallback = function () use ($sso, $userId, $characterId, $token): ?string {
                $refreshToken = (string)($token['refresh_token'] ?? '');
                if ($refreshToken === '') return null;
                $refresh = $sso->refreshTokenForCharacter($userId, $characterId, $refreshToken, 'member_audit', [
                    'org_type' => 'character',
                    'org_id' => 0,
                ]);
                if (($refresh['status'] ?? '') === 'success') {
                    return (string)($refresh['token']['access_token'] ?? '');
                }
                return null;
            };

            foreach ($memberAuditCategories as $key => $meta) {
                $scopesNeeded = $memberAuditScopes[$key] ?? [];
                $missing = array_values(array_diff($scopesNeeded, $granted));
                if (!empty($missing)) {
                    $scopeAudit->logEvent('missing_scopes', $userId, $characterId, [
                        'category' => $key,
                        'missing' => $missing,
                    ]);
                    continue;
                }
                $endpoint = str_replace('{character_id}', (string)$characterId, (string)($meta['endpoint'] ?? ''));
                $ttl = (int)($meta['ttl'] ?? 3600);
                try {
                    $result = $esi->getCachedAuthWithStatus("memberaudit:{$key}:{$characterId}", $endpoint, $ttl, $accessToken, [404], $refreshCallback);
                    if (($result['status'] ?? 200) < 200 || ($result['status'] ?? 200) >= 300) {
                        continue;
                    }
                    $payload = $result['data'];
                    if (!is_array($payload)) $payload = [];

                    $app->db->run(
                        "INSERT INTO module_corptools_character_audit\n"
                        . " (user_id, character_id, category, data_json, fetched_at)\n"
                        . " VALUES (?, ?, ?, ?, NOW())\n"
                        . " ON DUPLICATE KEY UPDATE data_json=VALUES(data_json), fetched_at=VALUES(fetched_at)",
                        [
                            $userId,
                            $characterId,
                            $key,
                            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ]
                    );
                    $app->db->run(
                        "INSERT INTO module_corptools_character_audit_snapshots (user_id, character_id, category, data_json, fetched_at)
                         VALUES (?, ?, ?, ?, NOW())",
                        [
                            $userId,
                            $characterId,
                            $key,
                            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ]
                    );

                    if ($key === 'assets') {
                        $app->db->run("DELETE FROM module_corptools_character_assets WHERE character_id=?", [$characterId]);
                        foreach ($payload as $asset) {
                            $app->db->run(
                                "INSERT INTO module_corptools_character_assets
                                 (user_id, character_id, item_id, type_id, location_id, location_type, quantity, is_singleton, is_blueprint_copy)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                [
                                    $userId,
                                    $characterId,
                                    (int)($asset['item_id'] ?? 0),
                                    (int)($asset['type_id'] ?? 0),
                                    (int)($asset['location_id'] ?? 0),
                                    (string)($asset['location_type'] ?? ''),
                                    (int)($asset['quantity'] ?? 0),
                                    (int)($asset['is_singleton'] ?? 0),
                                    (int)($asset['is_blueprint_copy'] ?? 0),
                                ]
                            );
                        }
                    }
                    if ($key === 'skills') {
                        $skills = $payload['skills'] ?? [];
                        if (is_array($skills)) {
                            $app->db->run("DELETE FROM module_corptools_character_skills WHERE character_id=?", [$characterId]);
                            foreach ($skills as $skill) {
                                $app->db->run(
                                    "INSERT INTO module_corptools_character_skills
                                     (user_id, character_id, skill_id, trained_level, active_level, skillpoints_in_skill)
                                     VALUES (?, ?, ?, ?, ?, ?)",
                                    [
                                        $userId,
                                        $characterId,
                                        (int)($skill['skill_id'] ?? 0),
                                        (int)($skill['trained_skill_level'] ?? 0),
                                        (int)($skill['active_skill_level'] ?? 0),
                                        (int)($skill['skillpoints_in_skill'] ?? 0),
                                    ]
                                );
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $metrics['audits_failed']++;
                    $scopeAudit->logEvent('esi_error', $userId, $characterId, [
                        'category' => $key,
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            $app->db->run(
                "INSERT INTO module_corptools_character_summary (character_id, user_id, character_name, audit_loaded, last_audit_at, updated_at)
                 VALUES (?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE audit_loaded=1, last_audit_at=NOW(), updated_at=NOW()",
                [$characterId, $userId, (string)($row['character_name'] ?? 'Unknown')]
            );
            $metrics['audits_run']++;
        }

        return [
            'message' => "Member audit refresh complete. Audits run: {$metrics['audits_run']}, failed: {$metrics['audits_failed']}.",
        ];
    };

    $cleanupAuditCache = function (App $app, array $context = []) use ($memberAuditCategories): array {
        $settings = new CorpToolsSettings($app->db);
        $cfg = $settings->get();
        $retentionDays = (int)($cfg['general']['retention_days'] ?? 30);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $categories = array_keys($memberAuditCategories);
        if (!empty($categories)) {
            $placeholders = implode(',', array_fill(0, count($categories), '?'));
            $app->db->run(
                "DELETE FROM module_corptools_character_audit_snapshots
                 WHERE fetched_at < ? AND category IN ({$placeholders})",
                array_merge([$cutoff], $categories)
            );
            $app->db->run(
                "DELETE FROM module_corptools_character_audit
                 WHERE fetched_at < ? AND category IN ({$placeholders})",
                array_merge([$cutoff], $categories)
            );
        }

        $app->db->run("DELETE FROM module_memberaudit_share_tokens WHERE expires_at IS NOT NULL AND expires_at < NOW()");
        $app->db->run(
            "DELETE FROM module_memberaudit_share_token_characters
             WHERE token_id NOT IN (SELECT id FROM module_memberaudit_share_tokens)"
        );
        $app->db->run("DELETE FROM module_memberaudit_access_log WHERE accessed_at < ?", [$cutoff]);

        return ['message' => 'Member audit cleanup complete.'];
    };

    JobRegistry::register([
        'key' => 'memberaudit.refresh',
        'name' => 'Member Audit Refresh',
        'description' => 'Refresh member audit caches for authorized characters.',
        'schedule' => 3600,
        'handler' => $refreshAuditCache,
    ]);
    JobRegistry::register([
        'key' => 'memberaudit.cleanup',
        'name' => 'Member Audit Cleanup',
        'description' => 'Purge old member audit cache rows and expired share tokens.',
        'schedule' => 86400,
        'handler' => $cleanupAuditCache,
    ]);
};
