<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/core/bootstrap.php';

use App\Core\Db;

$config = app_config();
$db = Db::fromConfig($config['db'] ?? []);

$db->exec("SET time_zone = '+00:00'");

$statements = [
    "CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(64) NOT NULL PRIMARY KEY,
        `value` TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS oauth_provider_cache (
        provider VARCHAR(32) NOT NULL PRIMARY KEY,
        payload_json MEDIUMTEXT NOT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ttl_seconds INT NOT NULL DEFAULT 86400
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sso_login_state (
        state CHAR(64) NOT NULL PRIMARY KEY,
        code_verifier VARCHAR(128) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS eve_users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        public_id CHAR(16) NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        character_name VARCHAR(128) NOT NULL,
        owner_hash VARCHAR(128) NULL,
        jwt_payload_json MEDIUMTEXT NULL,
        is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_eve_users_public_id (public_id),
        UNIQUE KEY uniq_character (character_id),
        KEY idx_eve_users_is_superadmin (is_superadmin)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS eve_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        access_token MEDIUMTEXT NOT NULL,
        refresh_token MEDIUMTEXT NULL,
        expires_at DATETIME NULL,
        scopes_json TEXT NULL,
        token_json MEDIUMTEXT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
        last_refresh_at DATETIME NULL,
        error_last TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_token_character (character_id),
        KEY idx_user (user_id),
        CONSTRAINT fk_eve_tokens_user_id FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS eve_token_buckets (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        bucket VARCHAR(32) NOT NULL,
        org_type VARCHAR(24) NOT NULL DEFAULT '',
        org_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        access_token MEDIUMTEXT NOT NULL,
        refresh_token MEDIUMTEXT NULL,
        expires_at DATETIME NULL,
        scopes_json TEXT NULL,
        token_json MEDIUMTEXT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
        last_refresh_at DATETIME NULL,
        error_last TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_bucket_character (character_id, bucket, org_type, org_id),
        KEY idx_bucket (bucket),
        KEY idx_org (org_type, org_id, bucket),
        KEY idx_user (user_id),
        CONSTRAINT fk_eve_token_buckets_user_id FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sso_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event VARCHAR(64) NOT NULL,
        payload_json MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_event (event),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS esi_cache (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        scope_key VARCHAR(128) NOT NULL,
        cache_key CHAR(64) NOT NULL,
        url TEXT NOT NULL,
        payload_json MEDIUMTEXT NOT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ttl_seconds INT NOT NULL DEFAULT 3600,
        status_code INT NOT NULL DEFAULT 200,
        etag VARCHAR(128) NULL,
        UNIQUE KEY uniq_scope_cache (scope_key, cache_key),
        KEY idx_scope (scope_key),
        KEY idx_fetched (fetched_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS menu_registry (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(128) NOT NULL UNIQUE,
        title VARCHAR(128) NOT NULL,
        url VARCHAR(255) NOT NULL,
        parent_slug VARCHAR(128) NULL,
        sort_order INT NOT NULL DEFAULT 10,
        area ENUM('left','admin_top','user_top') NOT NULL DEFAULT 'left',
        right_slug VARCHAR(128) NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS menu_overrides (
        slug VARCHAR(128) NOT NULL PRIMARY KEY,
        title VARCHAR(128) NULL,
        url VARCHAR(255) NULL,
        parent_slug VARCHAR(128) NULL,
        sort_order INT NULL,
        area ENUM('left','admin_top','user_top') NULL,
        right_slug VARCHAR(128) NULL,
        enabled TINYINT(1) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS groups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(64) NOT NULL,
        name VARCHAR(128) NOT NULL,
        is_admin TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_groups_slug (slug),
        KEY idx_groups_is_admin (is_admin)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS rights (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(120) NOT NULL,
        description VARCHAR(255) NOT NULL,
        module_slug VARCHAR(64) NOT NULL DEFAULT 'core',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_rights_slug (slug),
        KEY idx_rights_module (module_slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS group_rights (
        group_id BIGINT UNSIGNED NOT NULL,
        right_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (group_id, right_id),
        KEY idx_gr_right (right_id),
        CONSTRAINT fk_gr_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
        CONSTRAINT fk_gr_right FOREIGN KEY (right_id) REFERENCES rights(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS eve_user_groups (
        user_id BIGINT UNSIGNED NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, group_id),
        KEY idx_group (group_id),
        CONSTRAINT fk_eug_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE,
        CONSTRAINT fk_eug_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS universe_entities (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        entity_type ENUM(
          'character','corporation','alliance',
          'system','constellation','region',
          'type','group','category',
          'station','structure'
        ) NOT NULL,
        entity_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        extra_json MEDIUMTEXT NULL,
        icon_json MEDIUMTEXT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ttl_seconds INT NOT NULL DEFAULT 86400,
        UNIQUE KEY uniq_entity (entity_type, entity_id),
        KEY idx_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS authz_audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        route VARCHAR(190) NULL,
        right_slug VARCHAR(120) NULL,
        decision ENUM('allow','deny') NOT NULL,
        context_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_authz_user (user_id),
        KEY idx_authz_right (right_slug),
        KEY idx_authz_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS authz_state (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        version BIGINT UNSIGNED NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS access_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        character_id BIGINT UNSIGNED NULL,
        ip VARCHAR(64) NULL,
        method VARCHAR(10) NULL,
        path VARCHAR(255) NULL,
        status SMALLINT UNSIGNED NULL,
        decision VARCHAR(16) NULL,
        reason VARCHAR(64) NULL,
        context_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_access_created (created_at),
        KEY idx_access_user (user_id),
        KEY idx_access_path (path),
        KEY idx_access_decision (decision)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS character_links (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        character_name VARCHAR(128) NOT NULL,
        status ENUM('linked','revoked') NOT NULL DEFAULT 'linked',
        linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        linked_by_user_id BIGINT UNSIGNED NULL,
        revoked_at DATETIME NULL,
        revoked_by_user_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_character (character_id),
        UNIQUE KEY uniq_user_character (user_id, character_id),
        KEY idx_user (user_id),
        CONSTRAINT fk_char_links_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_charlink_targets (
        slug VARCHAR(64) NOT NULL PRIMARY KEY,
        name VARCHAR(128) NOT NULL,
        description VARCHAR(255) NOT NULL DEFAULT '',
        scopes_json TEXT NOT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        is_ignored TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_charlink_links (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        enabled_targets_json MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_charlink_user_character (user_id, character_id),
        KEY idx_charlink_character (character_id),
        CONSTRAINT fk_charlink_user_id FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_charlink_states (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        token_prefix CHAR(8) NOT NULL,
        purpose VARCHAR(32) NOT NULL DEFAULT 'link',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        used_at DATETIME NULL,
        used_character_id BIGINT UNSIGNED NULL,
        KEY idx_user (user_id),
        UNIQUE KEY uniq_charlink_token_hash (token_hash),
        CONSTRAINT fk_charlink_state_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_settings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        scope_type VARCHAR(32) NOT NULL,
        scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        settings_json MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_corptools_scope (scope_type, scope_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_invoice_payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        corp_id BIGINT UNSIGNED NOT NULL,
        wallet_division INT NOT NULL,
        journal_id BIGINT UNSIGNED NOT NULL,
        ref_type VARCHAR(64) NOT NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        balance DECIMAL(18,2) NOT NULL DEFAULT 0,
        entry_date DATETIME NOT NULL,
        first_party_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        second_party_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reason VARCHAR(255) NOT NULL DEFAULT '',
        raw_json MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_corptools_journal (corp_id, journal_id),
        KEY idx_corptools_entry_date (entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_moon_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        corp_id BIGINT UNSIGNED NOT NULL,
        event_date DATETIME NOT NULL,
        moon_name VARCHAR(128) NOT NULL,
        pilot_name VARCHAR(128) NOT NULL,
        ore_name VARCHAR(128) NOT NULL,
        quantity DECIMAL(18,2) NOT NULL DEFAULT 0,
        tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        created_by_user_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_corptools_moon_corp (corp_id),
        CONSTRAINT fk_corptools_moon_user FOREIGN KEY (created_by_user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_notification_rules (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        scope_type VARCHAR(32) NOT NULL,
        scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(128) NOT NULL,
        filters_json MEDIUMTEXT NOT NULL,
        webhook_url VARCHAR(255) NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_audit_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        scopes_json MEDIUMTEXT NULL,
        message VARCHAR(255) NOT NULL DEFAULT '',
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        KEY idx_corptools_audit_user (user_id),
        KEY idx_corptools_audit_character (character_id),
        KEY idx_corptools_audit_status (status),
        CONSTRAINT fk_corptools_audit_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_character_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        category VARCHAR(64) NOT NULL,
        data_json MEDIUMTEXT NOT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_corptools_character_category (character_id, category),
        KEY idx_corptools_char_user (user_id),
        KEY idx_corptools_char_category (category),
        CONSTRAINT fk_corptools_char_audit_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_character_summary (
        character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        character_name VARCHAR(128) NOT NULL,
        is_main TINYINT(1) NOT NULL DEFAULT 0,
        corp_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        alliance_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        home_station_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        death_clone_location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        jump_clone_location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        location_system_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        location_region_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        current_ship_type_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        current_ship_name VARCHAR(128) NOT NULL DEFAULT '',
        wallet_balance DECIMAL(18,2) NOT NULL DEFAULT 0,
        total_sp BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_login_at DATETIME NULL,
        assets_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        assets_value DECIMAL(18,2) NOT NULL DEFAULT 0,
        corp_roles_json MEDIUMTEXT NULL,
        corp_title VARCHAR(128) NOT NULL DEFAULT '',
        last_audit_at DATETIME NULL,
        audit_loaded TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_corptools_char_user (user_id),
        KEY idx_corptools_char_corp (corp_id),
        KEY idx_corptools_char_location_system (location_system_id),
        KEY idx_corptools_char_location_region (location_region_id),
        KEY idx_corptools_char_ship_type (current_ship_type_id),
        KEY idx_corptools_char_assets_count (assets_count),
        KEY idx_corptools_char_total_sp (total_sp),
        KEY idx_corptools_char_audit_loaded (audit_loaded),
        KEY idx_corptools_char_last_login (last_login_at),
        CONSTRAINT fk_corptools_char_summary_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_member_summary (
        user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        main_character_id BIGINT UNSIGNED NOT NULL,
        main_character_name VARCHAR(128) NOT NULL,
        corp_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        alliance_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        highest_sp BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_login_at DATETIME NULL,
        corp_joined_at DATETIME NULL,
        audit_loaded TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_corptools_member_corp (corp_id),
        KEY idx_corptools_member_sp (highest_sp),
        KEY idx_corptools_member_last_login (last_login_at),
        KEY idx_corptools_member_audit_loaded (audit_loaded),
        CONSTRAINT fk_corptools_member_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_character_assets (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        item_id BIGINT UNSIGNED NOT NULL,
        type_id BIGINT UNSIGNED NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        location_type VARCHAR(32) NOT NULL DEFAULT '',
        quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
        is_singleton TINYINT(1) NOT NULL DEFAULT 0,
        is_blueprint_copy TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_corptools_asset_item (character_id, item_id),
        KEY idx_corptools_asset_user (user_id),
        KEY idx_corptools_asset_type (type_id),
        KEY idx_corptools_asset_group (group_id),
        KEY idx_corptools_asset_category (category_id),
        KEY idx_corptools_asset_location (location_id),
        CONSTRAINT fk_corptools_asset_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_character_skills (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        skill_id BIGINT UNSIGNED NOT NULL,
        trained_level INT NOT NULL DEFAULT 0,
        active_level INT NOT NULL DEFAULT 0,
        skillpoints_in_skill INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_corptools_skill (character_id, skill_id),
        KEY idx_corptools_skill_user (user_id),
        KEY idx_corptools_skill_id (skill_id),
        CONSTRAINT fk_corptools_skill_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_pings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_hash CHAR(64) NOT NULL,
        source VARCHAR(64) NOT NULL DEFAULT 'webhook',
        payload_json MEDIUMTEXT NOT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        UNIQUE KEY uniq_corptools_ping_hash (event_hash),
        KEY idx_corptools_ping_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_industry_structures (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        corp_id BIGINT UNSIGNED NOT NULL,
        structure_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(128) NOT NULL DEFAULT '',
        system_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        region_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        rigs_json MEDIUMTEXT NULL,
        services_json MEDIUMTEXT NULL,
        fuel_expires_at DATETIME NULL,
        state VARCHAR(32) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_corptools_structure (corp_id, structure_id),
        KEY idx_corptools_structure_system (system_id),
        KEY idx_corptools_structure_region (region_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_corp_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        corp_id BIGINT UNSIGNED NOT NULL,
        category VARCHAR(64) NOT NULL,
        data_json MEDIUMTEXT NOT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_corptools_corp_category (corp_id, category),
        KEY idx_corptools_corp (corp_id),
        KEY idx_corptools_corp_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS corp_scope_policies (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        public_id CHAR(16) NOT NULL,
        name VARCHAR(128) NOT NULL,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        applies_to ENUM('corp_members', 'alliance_members', 'all_users') NOT NULL DEFAULT 'all_users',
        required_scopes_json MEDIUMTEXT NULL,
        optional_scopes_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_scope_policy_public_id (public_id),
        KEY idx_scope_policy_active (is_active),
        KEY idx_scope_policy_applies (applies_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS corp_scope_policy_overrides (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        public_id CHAR(16) NOT NULL,
        policy_id BIGINT UNSIGNED NOT NULL,
        target_type ENUM('role', 'group', 'user') NOT NULL,
        target_id BIGINT UNSIGNED NOT NULL,
        required_scopes_json MEDIUMTEXT NULL,
        optional_scopes_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_scope_override_public_id (public_id),
        KEY idx_scope_override_policy (policy_id),
        KEY idx_scope_override_target (target_type, target_id),
        CONSTRAINT fk_scope_override_policy FOREIGN KEY (policy_id) REFERENCES corp_scope_policies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_character_scope_status (
        character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        policy_id BIGINT UNSIGNED NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'unknown',
        reason VARCHAR(255) NOT NULL DEFAULT '',
        required_scopes_json MEDIUMTEXT NULL,
        optional_scopes_json MEDIUMTEXT NULL,
        granted_scopes_json MEDIUMTEXT NULL,
        missing_scopes_json MEDIUMTEXT NULL,
        token_expires_at DATETIME NULL,
        checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_scope_status_user (user_id),
        KEY idx_scope_status_status (status),
        CONSTRAINT fk_scope_status_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE,
        CONSTRAINT fk_scope_status_policy FOREIGN KEY (policy_id) REFERENCES corp_scope_policies(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_audit_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event VARCHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        character_id BIGINT UNSIGNED NULL,
        payload_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_audit_event (event),
        KEY idx_audit_event_user (user_id),
        KEY idx_audit_event_character (character_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_character_audit_snapshots (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        category VARCHAR(64) NOT NULL,
        data_json MEDIUMTEXT NOT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_corptools_char_audit_snap_user (user_id),
        KEY idx_corptools_char_audit_snap_char (character_id, fetched_at),
        KEY idx_corptools_char_audit_snap_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_corp_audit_snapshots (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        corp_id BIGINT UNSIGNED NOT NULL,
        category VARCHAR(64) NOT NULL,
        data_json MEDIUMTEXT NOT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_corptools_corp_audit_snap_corp (corp_id, fetched_at),
        KEY idx_corptools_corp_audit_snap_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_jobs (
        job_key VARCHAR(128) NOT NULL PRIMARY KEY,
        name VARCHAR(128) NOT NULL,
        description VARCHAR(255) NOT NULL DEFAULT '',
        schedule_seconds INT NOT NULL DEFAULT 60,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        last_run_at DATETIME NULL,
        next_run_at DATETIME NULL,
        last_status VARCHAR(32) NOT NULL DEFAULT 'never',
        last_duration_ms INT NOT NULL DEFAULT 0,
        last_message VARCHAR(255) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_job_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        job_key VARCHAR(128) NOT NULL,
        status VARCHAR(32) NOT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        duration_ms INT NOT NULL DEFAULT 0,
        message VARCHAR(255) NOT NULL DEFAULT '',
        error_trace MEDIUMTEXT NULL,
        meta_json MEDIUMTEXT NULL,
        KEY idx_corptools_job_runs_key (job_key, started_at),
        KEY idx_corptools_job_runs_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_corptools_job_locks (
        job_key VARCHAR(128) NOT NULL PRIMARY KEY,
        owner VARCHAR(64) NOT NULL,
        locked_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        KEY idx_corptools_job_locks_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_fittings_categories (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(128) NOT NULL,
        name VARCHAR(128) NOT NULL,
        description TEXT NULL,
        visibility_scope VARCHAR(16) NOT NULL DEFAULT 'all',
        visibility_org_id BIGINT UNSIGNED NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_fittings_category_slug (slug),
        KEY idx_fittings_categories_scope (visibility_scope, visibility_org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_fittings_category_groups (
        category_id BIGINT UNSIGNED NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (category_id, group_id),
        KEY idx_fittings_cat_groups_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_fittings_doctrines (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(128) NOT NULL,
        name VARCHAR(128) NOT NULL,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_fittings_doctrine_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_fittings_fits (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(128) NOT NULL,
        category_id BIGINT UNSIGNED NOT NULL,
        doctrine_id BIGINT UNSIGNED NULL,
        name VARCHAR(128) NOT NULL,
        ship_name VARCHAR(128) NOT NULL,
        eft_text MEDIUMTEXT NOT NULL,
        parsed_json MEDIUMTEXT NOT NULL,
        tags_json TEXT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        updated_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        has_renamed_items TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_fittings_fit_slug (slug),
        KEY idx_fittings_fits_category (category_id),
        KEY idx_fittings_fits_doctrine (doctrine_id),
        KEY idx_fittings_fits_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_fittings_fit_revisions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fit_id BIGINT UNSIGNED NOT NULL,
        revision INT NOT NULL,
        eft_text MEDIUMTEXT NOT NULL,
        parsed_json MEDIUMTEXT NOT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        change_summary VARCHAR(255) NOT NULL DEFAULT '',
        KEY idx_fittings_fit_revisions_fit (fit_id, revision)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_fittings_favorites (
        user_id BIGINT UNSIGNED NOT NULL,
        fit_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, fit_id),
        KEY idx_fittings_favorites_fit (fit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_fittings_saved_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fit_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(32) NOT NULL,
        message VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_fittings_saved_fit (fit_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_fittings_type_names (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        type_id BIGINT UNSIGNED NULL,
        original_name VARCHAR(128) NOT NULL,
        current_name VARCHAR(128) NOT NULL,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        renamed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_fittings_type_names_type (type_id),
        KEY idx_fittings_type_names_name (original_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_fittings_audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(64) NOT NULL,
        entity_type VARCHAR(32) NOT NULL,
        entity_id BIGINT UNSIGNED NOT NULL,
        message VARCHAR(255) NOT NULL,
        meta_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_fittings_audit_created (created_at),
        KEY idx_fittings_audit_entity (entity_type, entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_secgroups_groups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        key_slug VARCHAR(128) NOT NULL,
        display_name VARCHAR(128) NOT NULL,
        description TEXT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        auto_group TINYINT(1) NOT NULL DEFAULT 1,
        include_in_updates TINYINT(1) NOT NULL DEFAULT 1,
        can_grace TINYINT(1) NOT NULL DEFAULT 0,
        grace_default_days INT NOT NULL DEFAULT 0,
        allow_applications TINYINT(1) NOT NULL DEFAULT 0,
        notify_on_add TINYINT(1) NOT NULL DEFAULT 0,
        notify_on_remove TINYINT(1) NOT NULL DEFAULT 0,
        notify_on_grace TINYINT(1) NOT NULL DEFAULT 0,
        rights_group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_update_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_secgroups_key (key_slug),
        KEY idx_secgroups_enabled (enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_secgroups_filters (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        public_id CHAR(16) NOT NULL,
        type VARCHAR(64) NOT NULL,
        name VARCHAR(128) NOT NULL,
        description VARCHAR(255) NOT NULL DEFAULT '',
        config_json MEDIUMTEXT NOT NULL,
        grace_period_days INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_secgroups_filter_public_id (public_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_secgroups_group_filters (
        group_id BIGINT UNSIGNED NOT NULL,
        filter_id BIGINT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        last_evaluated_at DATETIME NULL,
        last_pass TINYINT(1) NULL,
        last_message VARCHAR(255) NULL,
        last_user_id BIGINT UNSIGNED NULL,
        PRIMARY KEY (group_id, filter_id),
        KEY idx_secgroups_group_filters (group_id, sort_order),
        CONSTRAINT fk_secgroups_group_filters_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
        CONSTRAINT fk_secgroups_group_filters_filter FOREIGN KEY (filter_id) REFERENCES module_secgroups_filters(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_secgroups_memberships (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
        source VARCHAR(16) NOT NULL DEFAULT 'AUTO',
        reason TEXT NULL,
        evidence_json MEDIUMTEXT NULL,
        last_evaluated_at DATETIME NULL,
        grace_expires_at DATETIME NULL,
        grace_filter_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_secgroups_membership (group_id, user_id),
        KEY idx_secgroups_memberships_status (status),
        CONSTRAINT fk_secgroups_memberships_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
        CONSTRAINT fk_secgroups_memberships_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_secgroups_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        public_id CHAR(16) NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at DATETIME NULL,
        note TEXT NULL,
        admin_note TEXT NULL,
        UNIQUE KEY uniq_secgroups_request_public_id (public_id),
        KEY idx_secgroups_requests_status (status),
        CONSTRAINT fk_secgroups_requests_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
        CONSTRAINT fk_secgroups_requests_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_secgroups_overrides (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        forced_state VARCHAR(16) NOT NULL,
        expires_at DATETIME NULL,
        reason TEXT NOT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_secgroups_overrides_expires (expires_at),
        CONSTRAINT fk_secgroups_overrides_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
        CONSTRAINT fk_secgroups_overrides_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_secgroups_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(32) NOT NULL,
        source VARCHAR(32) NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        meta_json MEDIUMTEXT NULL,
        KEY idx_secgroups_logs_created (created_at),
        CONSTRAINT fk_secgroups_logs_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
        CONSTRAINT fk_secgroups_logs_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS module_secgroups_notifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        KEY idx_secgroups_notifications_user (user_id, created_at),
        CONSTRAINT fk_secgroups_notifications_group FOREIGN KEY (group_id) REFERENCES module_secgroups_groups(id) ON DELETE CASCADE,
        CONSTRAINT fk_secgroups_notifications_user FOREIGN KEY (user_id) REFERENCES eve_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ($statements as $sql) {
    $db->exec($sql);
}

$db->run(
    "INSERT IGNORE INTO settings (`key`, `value`) VALUES
     ('site.brand.name', 'killsineve.online'),
     ('site.identity.type', 'corporation'),
     ('site.identity.id', '0')"
);

$db->run("INSERT IGNORE INTO authz_state (id, version) VALUES (1, 1)");

$db->run(
    "INSERT IGNORE INTO groups (slug, name, is_admin)
     VALUES ('admin', 'Administrator', 1)"
);

$db->run(
    "INSERT IGNORE INTO rights (slug, description) VALUES
     ('admin.access', 'Admin Access'),
     ('admin.cache', 'Manage ESI Cache'),
     ('admin.rights', 'Manage Rights (Groups & Permissions)'),
     ('admin.users', 'Manage Users & Groups'),
     ('admin.menu', 'Manage Menu')"
);

$db->run(
    "INSERT IGNORE INTO group_rights (group_id, right_id)
     SELECT g.id, r.id
     FROM groups g
     JOIN rights r ON r.slug IN ('admin.access','admin.cache','admin.rights','admin.users','admin.menu')
     WHERE g.slug='admin'"
);

$db->run(
    "INSERT IGNORE INTO menu_registry (slug,title,url,parent_slug,sort_order,area,right_slug,enabled) VALUES
     ('user.login','Login','/auth/login',NULL,10,'user_top',NULL,1),
     ('user.profile','Profile','/me',NULL,20,'user_top',NULL,1),
     ('user.alts','Linked Characters','/user/alts',NULL,30,'user_top',NULL,1),
     ('user.logout','Logout','/auth/logout',NULL,40,'user_top',NULL,1)"
);

echo "[OK] Install complete.\n";
