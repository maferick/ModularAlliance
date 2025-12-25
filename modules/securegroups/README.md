# Secure Groups

Secure Groups provides AllianceAuth-style secure group automation with smart filters and audit trails.

## Install

1. Apply migrations:
   ```bash
   php bin/migrate.php
   ```
2. Verify cron jobs appear in **Admin → Cron Manager**.

## Configure groups

1. Go to **Admin → Secure Groups → Groups** and create a group.
2. Edit group settings (applications, grace period, notifications).
3. Add rules in **Rules**:
   * **Alt Corp Filter** (corp ID + optional exemptions)
   * **Alt Alliance Filter** (alliance ID + optional exemptions)
   * **User In Group Filter** (existing rights groups)
   * **Filter Expression** (AND/OR/XOR of two filters)
4. Test filters against a user from the Rules page.

## Member experience

Members can see their group statuses at **/securegroups** and view per-rule explanations in each group detail page.

## Cron jobs

Secure Groups registers these jobs with the shared cron runner:

* `securegroups.evaluate_all` — evaluate all groups/users (hourly).
* `securegroups.evaluate_group` — evaluate one group on demand.
* `securegroups.evaluate_user` — evaluate one user on demand.

Run due jobs manually:
```bash
php bin/cron.php run --due --verbose
```

Run a single job:
```bash
php bin/cron.php run --job=securegroups.evaluate_all --verbose
```

Ubuntu crontab example:
```
* * * * * cd /var/www/ModularAlliance && /usr/bin/flock -n /tmp/modularalliance-cron.lock /usr/bin/php -d detect_unicode=0 bin/cron.php run --due --verbose >> /var/log/modularalliance/cron.log 2>&1
```

## Troubleshooting

* **Missing corp/alliance data:** Ensure CorpTools summary tables are populated.
* **Applications not visible:** Confirm `allow_applications` is enabled for the group.
* **Access not updated:** Verify the secure group created a rights group with slug `securegroup:<key>` and that cron has run.
* **Check failures:** See **Admin → Secure Groups → Logs** and **Admin → Cron Manager**.
