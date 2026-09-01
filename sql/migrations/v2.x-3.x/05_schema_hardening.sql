-- Aauth v2.x to v3.x schema hardening.
-- Run 04_schema_preflight.sql first and resolve every returned row.

ALTER TABLE `aauth_groups`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  MODIFY `name` varchar(100) NOT NULL,
  ADD UNIQUE KEY `uq_aauth_groups_name` (`name`);

ALTER TABLE `aauth_perms`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  MODIFY `name` varchar(100) NOT NULL,
  ADD UNIQUE KEY `uq_aauth_perms_name` (`name`);

ALTER TABLE `aauth_users`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  MODIFY `email` varchar(254) NOT NULL,
  MODIFY `verification_code` varchar(255) DEFAULT NULL,
  MODIFY `ip_address` varchar(45) DEFAULT NULL,
  ADD UNIQUE KEY `uq_aauth_users_email` (`email`),
  ADD KEY `idx_aauth_users_username` (`username`),
  ADD KEY `idx_aauth_users_verification_code` (`verification_code`(191)),
  ADD KEY `idx_aauth_users_banned_at` (`banned_at`);

ALTER TABLE `aauth_perm_to_group`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  ADD KEY `idx_aauth_perm_to_group_group` (`group_id`,`perm_id`),
  ADD CONSTRAINT `fk_aauth_ptg_perm` FOREIGN KEY (`perm_id`) REFERENCES `aauth_perms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_aauth_ptg_group` FOREIGN KEY (`group_id`) REFERENCES `aauth_groups` (`id`) ON DELETE CASCADE;

ALTER TABLE `aauth_perm_to_user`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  ADD KEY `idx_aauth_perm_to_user_user` (`user_id`,`perm_id`),
  ADD CONSTRAINT `fk_aauth_ptu_perm` FOREIGN KEY (`perm_id`) REFERENCES `aauth_perms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_aauth_ptu_user` FOREIGN KEY (`user_id`) REFERENCES `aauth_users` (`id`) ON DELETE CASCADE;

ALTER TABLE `aauth_user_to_group`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  ADD KEY `idx_aauth_user_to_group_group` (`group_id`,`user_id`),
  ADD CONSTRAINT `fk_aauth_utg_user` FOREIGN KEY (`user_id`) REFERENCES `aauth_users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_aauth_utg_group` FOREIGN KEY (`group_id`) REFERENCES `aauth_groups` (`id`) ON DELETE CASCADE;

ALTER TABLE `aauth_user_variables`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  DROP KEY `user_id_index`,
  ADD UNIQUE KEY `uq_aauth_user_variables_user_key` (`user_id`,`data_key`),
  ADD CONSTRAINT `fk_aauth_uv_user` FOREIGN KEY (`user_id`) REFERENCES `aauth_users` (`id`) ON DELETE CASCADE;

ALTER TABLE `aauth_group_to_group`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  ADD KEY `idx_aauth_group_to_group_subgroup` (`subgroup_id`,`group_id`),
  ADD CONSTRAINT `fk_aauth_gtg_group` FOREIGN KEY (`group_id`) REFERENCES `aauth_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_aauth_gtg_subgroup` FOREIGN KEY (`subgroup_id`) REFERENCES `aauth_groups` (`id`) ON DELETE CASCADE;

-- sender_id deliberately has no foreign key: value 0 represents a system PM.
ALTER TABLE `aauth_pms`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  MODIFY `pm_deleted_sender` tinyint(1) unsigned DEFAULT NULL,
  MODIFY `pm_deleted_receiver` tinyint(1) unsigned DEFAULT NULL,
  DROP KEY `full_index`,
  ADD KEY `idx_aauth_pms_receiver` (`receiver_id`,`pm_deleted_receiver`,`id`),
  ADD KEY `idx_aauth_pms_sender` (`sender_id`,`pm_deleted_sender`,`id`),
  ADD KEY `idx_aauth_pms_unread` (`receiver_id`,`date_read`,`pm_deleted_receiver`),
  ADD KEY `idx_aauth_pms_date_sent` (`date_sent`),
  ADD CONSTRAINT `fk_aauth_pms_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `aauth_users` (`id`) ON DELETE CASCADE;

ALTER TABLE `aauth_login_attempts`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  MODIFY `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  MODIFY `ip_address` varchar(45) DEFAULT '0',
  MODIFY `login_attempts` smallint unsigned NOT NULL DEFAULT '0',
  ADD KEY `idx_aauth_login_attempts_ip_time` (`ip_address`,`timestamp`);
