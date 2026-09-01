-- Aauth v2.x to v3.x: add expiration for temporary email-verification links.
ALTER TABLE `aauth_users`
  ADD COLUMN `verification_exp` datetime DEFAULT NULL AFTER `verification_code`;
