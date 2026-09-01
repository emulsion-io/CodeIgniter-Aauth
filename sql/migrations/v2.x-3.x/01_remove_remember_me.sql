-- Aauth v2.x to v3.x: remove the obsolete persistent remember-me state.
-- Run this migration once on databases created before remember-me was removed.

ALTER TABLE `aauth_users`
  DROP COLUMN `remember_time`,
  DROP COLUMN `remember_exp`;
