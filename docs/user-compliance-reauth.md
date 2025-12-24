# How Users Become Compliant (Re-auth Flow)

1. Admin/HR shares a re-auth link from **Admin → Member Audit**.
2. User opens the link and is redirected to EVE SSO.
3. Required corp scopes are requested automatically.
4. After successful login, the user becomes **COMPLIANT** (assuming the token includes all required scopes).

Users cannot remove required scopes. Optional scopes are only available if configured in policy.
