/*
 * Add one-time TOTP recovery codes.
 *
 * Clear-text codes are never stored: Aauth persists only a SHA-256 digest
 * bound to the user ID. Adapt the table name when application/config/aauth.php
 * uses a custom one.
 */

CREATE TABLE `aauth_totp_recovery_codes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `code_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aauth_totp_recovery_user_code` (`user_id`,`code_hash`),
  CONSTRAINT `fk_aauth_totp_recovery_user`
    FOREIGN KEY (`user_id`) REFERENCES `aauth_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
