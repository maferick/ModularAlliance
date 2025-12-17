User vs Admin Menu Logic
=======================

- User dropdown (area=user_top) is always rendered.
- Admin dropdown (area=admin_top) is rendered ONLY if at least one item is visible.
- Admin visibility depends on Rights::userHasRight() (admin override supported).
- Left menu contains only non-admin items.
