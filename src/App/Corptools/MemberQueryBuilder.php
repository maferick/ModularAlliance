<?php
declare(strict_types=1);

namespace App\Corptools;

final class MemberQueryBuilder
{
    /** @return array{sql:string, params:array} */
    public function build(array $filters): array
    {
        $sql = "SELECT ms.*, cs.character_name, cs.assets_count, cs.assets_value, cs.location_system_id, cs.location_region_id,
                       cs.current_ship_type_id, cs.corp_roles_json, cs.corp_title, cs.home_station_id, cs.death_clone_location_id,
                       cs.jump_clone_location_id, cs.total_sp, cs.audit_loaded
                FROM module_corptools_member_summary ms
                JOIN module_corptools_character_summary cs ON cs.character_id = ms.main_character_id";

        $joins = [];
        $where = [];
        $params = [];

        if (!empty($filters['asset_presence'])) {
            if ($filters['asset_presence'] === 'has') {
                $where[] = 'cs.assets_count > 0';
            }
            if ($filters['asset_presence'] === 'none') {
                $where[] = 'cs.assets_count = 0';
            }
        }

        if (!empty($filters['name'])) {
            $where[] = '(ms.main_character_name LIKE ? OR cs.character_name LIKE ?)';
            $params[] = '%' . $filters['name'] . '%';
            $params[] = '%' . $filters['name'] . '%';
        }

        if (!empty($filters['asset_value_min'])) {
            $where[] = 'cs.assets_value >= ?';
            $params[] = (float)$filters['asset_value_min'];
        }

        if (!empty($filters['location_region_id'])) {
            $where[] = 'cs.location_region_id = ?';
            $params[] = (int)$filters['location_region_id'];
        }

        if (!empty($filters['location_system_id'])) {
            $where[] = 'cs.location_system_id = ?';
            $params[] = (int)$filters['location_system_id'];
        }

        if (!empty($filters['ship_type_id'])) {
            $where[] = 'cs.current_ship_type_id = ?';
            $params[] = (int)$filters['ship_type_id'];
        }

        if (!empty($filters['home_station_id'])) {
            $where[] = 'cs.home_station_id = ?';
            $params[] = (int)$filters['home_station_id'];
        }

        if (!empty($filters['death_clone_location_id'])) {
            $where[] = 'cs.death_clone_location_id = ?';
            $params[] = (int)$filters['death_clone_location_id'];
        }

        if (!empty($filters['jump_clone_location_id'])) {
            $where[] = 'cs.jump_clone_location_id = ?';
            $params[] = (int)$filters['jump_clone_location_id'];
        }

        if (!empty($filters['corp_title'])) {
            $where[] = 'cs.corp_title LIKE ?';
            $params[] = '%' . $filters['corp_title'] . '%';
        }

        if (!empty($filters['corp_role'])) {
            $where[] = "JSON_CONTAINS(cs.corp_roles_json, ?, '$')";
            $params[] = json_encode((string)$filters['corp_role']);
        }

        if (!empty($filters['audit_loaded'])) {
            $where[] = 'cs.audit_loaded = 1';
        }

        if (!empty($filters['highest_sp_min'])) {
            $where[] = 'ms.highest_sp >= ?';
            $params[] = (int)$filters['highest_sp_min'];
        }

        if (!empty($filters['last_login_since'])) {
            $where[] = 'ms.last_login_at >= ?';
            $params[] = $filters['last_login_since'];
        }

        if (!empty($filters['corp_joined_since'])) {
            $where[] = 'ms.corp_joined_at >= ?';
            $params[] = $filters['corp_joined_since'];
        }

        if (!empty($filters['skill_id'])) {
            $joins[] = 'JOIN module_corptools_character_skills sk ON sk.character_id = cs.character_id';
            $where[] = 'sk.skill_id = ?';
            $params[] = (int)$filters['skill_id'];
        }

        if (!empty($filters['asset_type_id'])) {
            $joins[] = 'JOIN module_corptools_character_assets ca ON ca.character_id = cs.character_id';
            $where[] = 'ca.type_id = ?';
            $params[] = (int)$filters['asset_type_id'];
        }

        if (!empty($filters['asset_group_id'])) {
            $joins[] = 'JOIN module_corptools_character_assets ca ON ca.character_id = cs.character_id';
            $where[] = 'ca.group_id = ?';
            $params[] = (int)$filters['asset_group_id'];
        }

        if (!empty($filters['asset_category_id'])) {
            $joins[] = 'JOIN module_corptools_character_assets ca ON ca.character_id = cs.character_id';
            $where[] = 'ca.category_id = ?';
            $params[] = (int)$filters['asset_category_id'];
        }

        if ($joins) {
            $sql .= ' ' . implode(' ', array_unique($joins));
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY ms.main_character_name ASC';

        return ['sql' => $sql, 'params' => $params];
    }
}
