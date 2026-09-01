-- Aauth v2.x to v3.x: separate email verification and banning states.
-- Run this migration once on databases using the former `banned` column.

ALTER TABLE `aauth_users`
  ADD COLUMN `email_verified_at` datetime DEFAULT NULL AFTER `date_created`,
  ADD COLUMN `banned_at` datetime DEFAULT NULL AFTER `email_verified_at`,
  ADD COLUMN `ban_reason` text COLLATE utf8_general_ci AFTER `banned_at`;

-- Previously, an account awaiting verification was represented by banned = 1
-- together with a verification token. Preserve genuine bans separately.
UPDATE `aauth_users`
SET
  `email_verified_at` = CASE
    WHEN `banned` = 1 AND COALESCE(`verification_code`, '') <> '' THEN NULL
    ELSE COALESCE(`date_created`, NOW())
  END,
  `banned_at` = CASE
    WHEN `banned` = 1 AND COALESCE(`verification_code`, '') = '' THEN NOW()
    ELSE NULL
  END;

ALTER TABLE `aauth_users`
  DROP COLUMN `banned`;
