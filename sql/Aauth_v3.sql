/*
	Aauth SQL Table Structure
*/

SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4;

-- ----------------------------
-- Table structure for `aauth_groups`
-- ----------------------------
DROP TABLE IF EXISTS `aauth_groups`;
CREATE TABLE `aauth_groups` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `definition` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aauth_groups_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of aauth_groups
-- ----------------------------
INSERT INTO `aauth_groups` VALUES ('1', 'Admin', 'Super Admin Group');
INSERT INTO `aauth_groups` VALUES ('2', 'Public', 'Public Access Group');
INSERT INTO `aauth_groups` VALUES ('3', 'Default', 'Default Access Group');

-- ----------------------------
-- Table structure for `aauth_perms`
-- ----------------------------
DROP TABLE IF EXISTS `aauth_perms`;
CREATE TABLE `aauth_perms` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `definition` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aauth_perms_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of aauth_perms
-- ----------------------------

-- ----------------------------
-- Table structure for `aauth_perm_to_group`
-- ----------------------------
DROP TABLE IF EXISTS `aauth_perm_to_group`;
CREATE TABLE `aauth_perm_to_group` (
  `perm_id` int(11) unsigned NOT NULL,
  `group_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`perm_id`,`group_id`),
  KEY `idx_aauth_perm_to_group_group` (`group_id`,`perm_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of aauth_perm_to_group
-- ----------------------------

-- ----------------------------
-- Table structure for `aauth_perm_to_user`
-- ----------------------------
DROP TABLE IF EXISTS `aauth_perm_to_user`;
CREATE TABLE `aauth_perm_to_user` (
  `perm_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`perm_id`,`user_id`),
  KEY `idx_aauth_perm_to_user_user` (`user_id`,`perm_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of aauth_perm_to_user
-- ----------------------------

-- ----------------------------
-- Table structure for `aauth_pms`
-- ----------------------------
DROP TABLE IF EXISTS `aauth_pms`;
CREATE TABLE `aauth_pms` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) unsigned NOT NULL,
  `receiver_id` int(11) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text,
  `date_sent` datetime DEFAULT NULL,
  `date_read` datetime DEFAULT NULL,
  `pm_deleted_sender` tinyint(1) unsigned DEFAULT NULL,
  `pm_deleted_receiver` tinyint(1) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aauth_pms_receiver` (`receiver_id`,`pm_deleted_receiver`,`id`),
  KEY `idx_aauth_pms_sender` (`sender_id`,`pm_deleted_sender`,`id`),
  KEY `idx_aauth_pms_unread` (`receiver_id`,`date_read`,`pm_deleted_receiver`),
  KEY `idx_aauth_pms_date_sent` (`date_sent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of aauth_pms
-- ----------------------------

-- ----------------------------
-- Table structure for `aauth_users`
-- ----------------------------
DROP TABLE IF EXISTS `aauth_users`;
CREATE TABLE `aauth_users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(254) NOT NULL,
  `pass` varchar(255) NOT NULL,
  `username` varchar(100),
  `last_login` datetime DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `date_created` datetime DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `banned_at` datetime DEFAULT NULL,
  `ban_reason` text,
  `forgot_exp` datetime DEFAULT NULL,
  `verification_code` varchar(255) DEFAULT NULL,
  `verification_exp` datetime DEFAULT NULL,
  `totp_secret` varchar(16) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aauth_users_email` (`email`),
  KEY `idx_aauth_users_username` (`username`),
  KEY `idx_aauth_users_verification_code` (`verification_code`(191)),
  KEY `idx_aauth_users_banned_at` (`banned_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of aauth_users
-- ----------------------------
INSERT INTO `aauth_users` (`id`, `email`, `pass`, `username`, `email_verified_at`, `ip_address`) VALUES
('1', 'admin@example.com', '$2y$10$h19Lblcr6amOIUL1TgYW2.VVZOhac/e1kHMgAwCubMTlYXZrL0wS2', 'Admin', NOW(), '0');

-- ----------------------------
-- Table structure for `aauth_user_to_group`
-- ----------------------------
DROP TABLE IF EXISTS `aauth_user_to_group`;
CREATE TABLE `aauth_user_to_group` (
  `user_id` int(11) unsigned NOT NULL,
  `group_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`group_id`),
  KEY `idx_aauth_user_to_group_group` (`group_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of aauth_user_to_group
-- ----------------------------
INSERT INTO `aauth_user_to_group` VALUES ('1', '1');
INSERT INTO `aauth_user_to_group` VALUES ('1', '3');

-- ----------------------------
-- Table structure for `aauth_user_variables`
-- ----------------------------
DROP TABLE IF EXISTS `aauth_user_variables`;
CREATE TABLE `aauth_user_variables` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `data_key` varchar(100) NOT NULL,
  `value` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aauth_user_variables_user_key` (`user_id`,`data_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of aauth_user_variables
-- ----------------------------

-- ----------------------------
-- Table structure for `aauth_group_to_group`
-- ----------------------------
DROP TABLE IF EXISTS `aauth_group_to_group`;
CREATE TABLE `aauth_group_to_group` (
  `group_id` int(11) unsigned NOT NULL,
  `subgroup_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`group_id`,`subgroup_id`),
  KEY `idx_aauth_group_to_group_subgroup` (`subgroup_id`,`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of aauth_group_to_group
-- ----------------------------

-- ----------------------------
-- Table structure for `aauth_login_attempts`
-- ----------------------------

DROP TABLE IF EXISTS `aauth_login_attempts`;
CREATE TABLE `aauth_login_attempts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) DEFAULT '0',
  `timestamp` datetime DEFAULT NULL,
  `login_attempts` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_aauth_login_attempts_ip_time` (`ip_address`,`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of aauth_login_attempts
-- ----------------------------

-- ----------------------------
-- Foreign keys
-- ----------------------------
ALTER TABLE `aauth_perm_to_group`
  ADD CONSTRAINT `fk_aauth_ptg_perm` FOREIGN KEY (`perm_id`) REFERENCES `aauth_perms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_aauth_ptg_group` FOREIGN KEY (`group_id`) REFERENCES `aauth_groups` (`id`) ON DELETE CASCADE;

ALTER TABLE `aauth_perm_to_user`
  ADD CONSTRAINT `fk_aauth_ptu_perm` FOREIGN KEY (`perm_id`) REFERENCES `aauth_perms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_aauth_ptu_user` FOREIGN KEY (`user_id`) REFERENCES `aauth_users` (`id`) ON DELETE CASCADE;

ALTER TABLE `aauth_user_to_group`
  ADD CONSTRAINT `fk_aauth_utg_user` FOREIGN KEY (`user_id`) REFERENCES `aauth_users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_aauth_utg_group` FOREIGN KEY (`group_id`) REFERENCES `aauth_groups` (`id`) ON DELETE CASCADE;

ALTER TABLE `aauth_user_variables`
  ADD CONSTRAINT `fk_aauth_uv_user` FOREIGN KEY (`user_id`) REFERENCES `aauth_users` (`id`) ON DELETE CASCADE;

ALTER TABLE `aauth_group_to_group`
  ADD CONSTRAINT `fk_aauth_gtg_group` FOREIGN KEY (`group_id`) REFERENCES `aauth_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_aauth_gtg_subgroup` FOREIGN KEY (`subgroup_id`) REFERENCES `aauth_groups` (`id`) ON DELETE CASCADE;

-- sender_id deliberately has no foreign key: value 0 represents a system PM.
ALTER TABLE `aauth_pms`
  ADD CONSTRAINT `fk_aauth_pms_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `aauth_users` (`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS=1;
