
# Rights System Notes

- Admin group has a hard override: admin users always pass rights checks.
- Rights are resolved via Db joins; no IDs exposed to UI.
- Menu gating must call Rights::userHasRight().
