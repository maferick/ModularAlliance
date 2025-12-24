# Member Audit Operator Runbook

## Daily Routine

1. Open **Admin → Member Audit**.
2. Filter for **Missing scopes** and **Token expired**.
3. Generate re-auth links for affected members.
4. Trigger audits for compliant members if a fresh run is needed.

## Weekly Review

1. Export CSV for compliance reporting.
2. Review **Admin → Corp Tools → Status** for audit health.
3. Validate that scope policy and overrides still match HR needs.

## Troubleshooting

- **Audit blocked**: Member lacks required scopes or has expired token.
- **Missing scopes**: Issue re-auth link and confirm corp policy.
- **Token invalid**: User never linked a character or token was removed.
