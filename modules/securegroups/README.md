# Secure Groups

Secure Groups is a lightweight, provider-driven rules engine for managing portal group membership. The core module only orchestrates providers (filters) and stores auditable decisions.

## What it is

* **Groups** define membership targets inside the portal.
* **Rules** are supplied by providers (CorpTools, CharLink, etc.).
* **Evaluation** runs on a schedule and stores evidence for every decision.
* **Apply** enforces evaluated results (unless the group is in dry-run mode).

## Configuring rules

1. Go to **Admin → Secure Groups → Groups**.
2. Create or edit a group.
3. Add rules using the provider and rule dropdowns.
4. Configure operators and values (rules are evaluated in logic groups; all rules in a group are ANDed, groups are ORed).
5. Choose how unknown data is handled:
   * **Fail**: unknown data fails the rule.
   * **Ignore**: unknown data is skipped.
   * **Defer**: membership stays pending.

## Cron enforcement

Secure Groups uses the shared cron runner (`bin/cron.php`) and registers two jobs:

* `securegroups.evaluate` — evaluates rules and stores evidence.
* `securegroups.apply` — applies changes based on the evaluated results.

Use **Admin → Cron Manager** for job status and a crontab snippet.

## Troubleshooting

* **Missing scopes or token expiry**: providers return `unknown` with evidence. Check CorpTools/CharLink scope status.
* **Stale data**: run CorpTools audit refresh jobs and re-run `securegroups.evaluate`.
* **Manual overrides**: sticky overrides are not overwritten by auto-apply. Use the Manual Overrides page to remove them.
