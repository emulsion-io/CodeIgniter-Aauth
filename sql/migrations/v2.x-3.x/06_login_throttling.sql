/*
 * Replace the legacy IP-only attempt counter with independent, HMAC-keyed
 * IP and identifier buckets. Login-attempt rows are temporary security state,
 * so preserving legacy counters would provide no useful migration value.
 *
 * Adapt the table name when application/config/aauth.php uses a custom one.
 */

DROP TABLE IF EXISTS `aauth_login_attempts`;

CREATE TABLE `aauth_login_attempts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(32) NOT NULL,
  `key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_started_at` datetime NOT NULL,
  `attempts` smallint unsigned NOT NULL DEFAULT '0',
  `blocked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aauth_login_attempts_scope_key` (`scope`,`key_hash`),
  KEY `idx_aauth_login_attempts_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
