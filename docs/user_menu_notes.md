# User Menu / Layout Notes

Menu areas:

- `left` – non-admin navigation (dashboard + user-facing modules)
- `user_top` – user dropdown (profile, linked chars, login/logout)
- `admin_top` – admin dropdown (rendered only if at least one item is visible)

Rendering rules:

- Admin dropdown is shown ONLY if at least one `admin_top` item is visible for the user.
- Admin visibility depends on rights checks (admin override supported).
- Left menu should contain only non-admin items; admin functionality belongs under the Admin dropdown.

Operational:

- `/admin/cache` is an admin-only page gated by `admin.cache`.
