# Scope Policy Model

The Member Audit system relies on a **server-side scope policy** to control which ESI scopes are required and which are optional. Users can never remove required scopes; they can only request optional scopes when the policy allows them.

## Tables

### `corp_scope_policies`
Authoritative policy definitions.

- `name`, `description`
- `is_active`
- `applies_to`: `corp_members`, `alliance_members`, or `all_users`
- `required_scopes_json`
- `optional_scopes_json`

### `corp_scope_policy_overrides`
Additive overrides for specific users or groups.

- `policy_id`
- `target_type`: `user` or `group`
- `target_id`
- `required_scopes_json` (added to base policy)
- `optional_scopes_json` (added to base policy)

### `module_corptools_character_scope_status`
Latest compliance snapshot per character.

- `status`: `COMPLIANT`, `MISSING_SCOPES`, `TOKEN_EXPIRED`, `TOKEN_INVALID`
- `required_scopes_json`, `optional_scopes_json`
- `granted_scopes_json`, `missing_scopes_json`
- `checked_at`

## Resolution Logic

1. Resolve the active policy based on corp/alliance membership.
2. Start with policy `required_scopes` and `optional_scopes`.
3. Apply overrides for the user or any of their groups.
4. Required scopes are enforced on every login and audit run.

## Compliance States

- **COMPLIANT**: token has all required scopes and is valid.
- **MISSING_SCOPES**: token exists but lacks required scopes.
- **TOKEN_EXPIRED**: token exists but is expired.
- **TOKEN_INVALID**: no valid token on file.
