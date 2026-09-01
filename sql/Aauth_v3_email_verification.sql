-- Run once when upgrading an existing Aauth v3 database.
ALTER TABLE `aauth_users`
  ADD COLUMN `verification_exp` datetime DEFAULT NULL AFTER `verification_code`;
