<?php
declare(strict_types=1);

use App\Core\Universe;

function memberaudit_resolve_entity_name(Universe $universe, string $type, int $id, string $emptyFallback = '—'): string
{
    if ($id <= 0) {
        return $emptyFallback;
    }

    $name = $universe->nameOrUnknown($type, $id);
    return $name !== '' ? $name : 'Unknown';
}

function memberaudit_resolve_character_name(Universe $universe, int $characterId): string
{
    if ($characterId <= 0) {
        return 'Unknown';
    }

    $profile = $universe->characterProfile($characterId);
    return (string)($profile['character']['name'] ?? 'Unknown');
}

function memberaudit_render_audit_data(Universe $universe, string $category, array $payload): string
{
    if (empty($payload)) {
        return "<div class='card card-body text-muted'>No cached data yet.</div>";
    }

    $rows = '';
    if ($category === 'assets') {
        foreach ($payload as $row) {
            $typeName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'type', (int)($row['type_id'] ?? 0)));
            $qty = number_format((int)($row['quantity'] ?? 0));
            $locationType = (string)($row['location_type'] ?? '');
            $locationId = (int)($row['location_id'] ?? 0);
            $locEntity = match ($locationType) {
                'station' => 'station',
                'structure' => 'structure',
                'solar_system' => 'system',
                default => 'structure',
            };
            $locationName = htmlspecialchars(memberaudit_resolve_entity_name($universe, $locEntity, $locationId));
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
        $raceName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'race', $raceId));
        $bloodlineName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'bloodline', $bloodlineId));
        $corpName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'corporation', $corpId));
        $allianceName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'alliance', $allianceId));
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
            $contactId = (int)($row['contact_id'] ?? 0);
            $contactType = (string)($row['contact_type'] ?? 'character');
            $name = htmlspecialchars(memberaudit_resolve_entity_name($universe, $contactType, $contactId));
            $standing = htmlspecialchars((string)($row['standing'] ?? '0'));
            $rows .= "<tr><td>{$name}</td><td>{$contactType}</td><td class='text-end'>{$standing}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='3' class='text-muted'>No contacts cached.</td></tr>";
        }
        return "<table class='table table-sm'>
                <thead><tr><th>Name</th><th>Type</th><th class='text-end'>Standing</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
    }

    if ($category === 'contracts') {
        foreach ($payload as $row) {
            $issuer = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'character', (int)($row['issuer_id'] ?? 0)));
            $assignee = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'character', (int)($row['assignee_id'] ?? 0)));
            $type = htmlspecialchars((string)($row['type'] ?? ''));
            $status = htmlspecialchars((string)($row['status'] ?? ''));
            $title = htmlspecialchars((string)($row['title'] ?? ''));
            $rows .= "<tr><td>{$issuer}</td><td>{$assignee}</td><td>{$type}</td><td>{$status}</td><td>{$title}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='5' class='text-muted'>No contracts cached.</td></tr>";
        }
        return "<table class='table table-sm'>
                <thead><tr><th>Issuer</th><th>Assignee</th><th>Type</th><th>Status</th><th>Title</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
    }

    if ($category === 'corp_history') {
        foreach ($payload as $row) {
            $corp = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'corporation', (int)($row['corporation_id'] ?? 0)));
            $start = htmlspecialchars((string)($row['start_date'] ?? ''));
            $rows .= "<tr><td>{$corp}</td><td>{$start}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='2' class='text-muted'>No corp history cached.</td></tr>";
        }
        return "<table class='table table-sm'>
                <thead><tr><th>Corporation</th><th>Start</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
    }

    if ($category === 'fw') {
        $factionName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'faction', (int)($payload['faction_id'] ?? 0)));
        $killsYday = number_format((int)($payload['kills']['yesterday'] ?? 0));
        $killsTotal = number_format((int)($payload['kills']['total'] ?? 0));
        $victoriesYday = number_format((int)($payload['victory_points']['yesterday'] ?? 0));
        $victoriesTotal = number_format((int)($payload['victory_points']['total'] ?? 0));
        return "<div class='row g-3'>
                <div class='col-md-3'><div class='card card-body'><div class='text-muted small'>Faction</div><div>{$factionName}</div></div></div>
                <div class='col-md-3'><div class='card card-body'><div class='text-muted small'>Kills (Yesterday)</div><div>{$killsYday}</div></div></div>
                <div class='col-md-3'><div class='card card-body'><div class='text-muted small'>Kills (Total)</div><div>{$killsTotal}</div></div></div>
                <div class='col-md-3'><div class='card card-body'><div class='text-muted small'>Victory Points (Yesterday)</div><div>{$victoriesYday}</div></div></div>
                <div class='col-md-3'><div class='card card-body'><div class='text-muted small'>Victory Points (Total)</div><div>{$victoriesTotal}</div></div></div>
              </div>";
    }

    if ($category === 'implants') {
        $names = [];
        foreach ($payload as $row) {
            $typeId = (int)($row['type_id'] ?? 0);
            if ($typeId <= 0) {
                continue;
            }
            $names[] = memberaudit_resolve_entity_name($universe, 'type', $typeId);
        }
        $rows = $names ? '<li>' . implode('</li><li>', array_map('htmlspecialchars', $names)) . '</li>' : '<li class="text-muted">No implants cached.</li>';
        return "<ul>{$rows}</ul>";
    }

    if ($category === 'clones') {
        $rows = '';
        foreach ($payload as $row) {
            $locationId = (int)($row['location_id'] ?? 0);
            $locationType = (string)($row['location_type'] ?? 'structure');
            $locationName = htmlspecialchars(memberaudit_resolve_entity_name($universe, $locationType, $locationId));
            $rows .= "<tr><td>{$locationName}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td class='text-muted'>No clones cached.</td></tr>";
        }
        return "<table class='table table-sm'><tbody>{$rows}</tbody></table>";
    }

    if ($category === 'mail') {
        foreach ($payload as $row) {
            $from = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'character', (int)($row['from'] ?? 0)));
            $subject = htmlspecialchars((string)($row['subject'] ?? ''));
            $timestamp = htmlspecialchars((string)($row['timestamp'] ?? ''));
            $rows .= "<tr><td>{$from}</td><td>{$subject}</td><td>{$timestamp}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='3' class='text-muted'>No mail cached.</td></tr>";
        }
        return "<table class='table table-sm'>
                <thead><tr><th>From</th><th>Subject</th><th>When</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
    }

    if ($category === 'mining') {
        foreach ($payload as $row) {
            $typeName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'type', (int)($row['type_id'] ?? 0)));
            $systemName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'system', (int)($row['solar_system_id'] ?? 0)));
            $qty = number_format((int)($row['quantity'] ?? 0));
            $date = htmlspecialchars((string)($row['date'] ?? ''));
            $rows .= "<tr><td>{$typeName}</td><td>{$systemName}</td><td class='text-end'>{$qty}</td><td>{$date}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='4' class='text-muted'>No mining cached.</td></tr>";
        }
        return "<table class='table table-sm'>
                <thead><tr><th>Ore</th><th>System</th><th class='text-end'>Quantity</th><th>Date</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
    }

    if ($category === 'loyalty') {
        foreach ($payload as $row) {
            $corpName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'corporation', (int)($row['corporation_id'] ?? 0)));
            $points = number_format((int)($row['loyalty_points'] ?? 0));
            $rows .= "<tr><td>{$corpName}</td><td class='text-end'>{$points}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='2' class='text-muted'>No loyalty points cached.</td></tr>";
        }
        return "<table class='table table-sm'>
                <thead><tr><th>Corporation</th><th class='text-end'>Points</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
    }

    if ($category === 'skills') {
        foreach ($payload as $row) {
            $skillName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'type', (int)($row['skill_id'] ?? 0)));
            $level = htmlspecialchars((string)($row['trained_skill_level'] ?? ''));
            $rows .= "<tr><td>{$skillName}</td><td class='text-end'>{$level}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='2' class='text-muted'>No skills cached.</td></tr>";
        }
        return "<table class='table table-sm'>
                <thead><tr><th>Skill</th><th class='text-end'>Level</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
    }

    if ($category === 'skillqueue') {
        foreach ($payload as $row) {
            $skillName = htmlspecialchars(memberaudit_resolve_entity_name($universe, 'type', (int)($row['skill_id'] ?? 0)));
            $finish = htmlspecialchars((string)($row['finish_date'] ?? ''));
            $rows .= "<tr><td>{$skillName}</td><td>{$finish}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='2' class='text-muted'>No skill queue cached.</td></tr>";
        }
        return "<table class='table table-sm'>
                <thead><tr><th>Skill</th><th>Finishes</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
    }

    if ($category === 'wallet') {
        foreach ($payload as $row) {
            $amount = number_format((float)($row['amount'] ?? 0), 2);
            $balance = number_format((float)($row['balance'] ?? 0), 2);
            $reason = htmlspecialchars((string)($row['reason'] ?? ''));
            $rows .= "<tr><td>{$amount}</td><td>{$balance}</td><td>{$reason}</td></tr>";
        }
        if ($rows === '') {
            $rows = "<tr><td colspan='3' class='text-muted'>No wallet journal cached.</td></tr>";
        }
        return "<table class='table table-sm'>
                <thead><tr><th>Amount</th><th>Balance</th><th>Reason</th></tr></thead>
                <tbody>{$rows}</tbody></table>";
    }

    return "<div class='card card-body text-muted'>Unsupported audit category.</div>";
}
