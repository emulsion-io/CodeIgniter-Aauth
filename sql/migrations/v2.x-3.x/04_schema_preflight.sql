-- Aauth v2.x to v3.x schema-hardening preflight.
-- Every query must return zero rows before running 05_schema_hardening.sql.
-- No data is modified by this file.

SELECT CONVERT(`email` USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `normalized_email`,
  COUNT(*) AS `duplicate_count`
FROM `aauth_users`
GROUP BY `normalized_email`
HAVING COUNT(*) > 1;

SELECT CONVERT(`name` USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `normalized_name`,
  COUNT(*) AS `duplicate_count`
FROM `aauth_groups`
GROUP BY `normalized_name`
HAVING `normalized_name` IS NULL OR COUNT(*) > 1;

SELECT CONVERT(`name` USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `normalized_name`,
  COUNT(*) AS `duplicate_count`
FROM `aauth_perms`
GROUP BY `normalized_name`
HAVING `normalized_name` IS NULL OR COUNT(*) > 1;

SELECT `user_id`,
  CONVERT(`data_key` USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `normalized_data_key`,
  COUNT(*) AS `duplicate_count`
FROM `aauth_user_variables`
GROUP BY `user_id`, `normalized_data_key`
HAVING COUNT(*) > 1;

SELECT `id`, CHAR_LENGTH(`verification_code`) AS `verification_code_length`
FROM `aauth_users`
WHERE CHAR_LENGTH(`verification_code`) > 255;

SELECT `id`, CHAR_LENGTH(`ip_address`) AS `ip_address_length`
FROM `aauth_users`
WHERE CHAR_LENGTH(`ip_address`) > 45;

SELECT `id`, CHAR_LENGTH(`ip_address`) AS `ip_address_length`
FROM `aauth_login_attempts`
WHERE CHAR_LENGTH(`ip_address`) > 45;

SELECT 'aauth_perm_to_group.perm_id' AS `relation`, ptg.`perm_id` AS `missing_id`
FROM `aauth_perm_to_group` ptg
LEFT JOIN `aauth_perms` p ON p.`id` = ptg.`perm_id`
WHERE p.`id` IS NULL
UNION ALL
SELECT 'aauth_perm_to_group.group_id', ptg.`group_id`
FROM `aauth_perm_to_group` ptg
LEFT JOIN `aauth_groups` g ON g.`id` = ptg.`group_id`
WHERE g.`id` IS NULL
UNION ALL
SELECT 'aauth_perm_to_user.perm_id', ptu.`perm_id`
FROM `aauth_perm_to_user` ptu
LEFT JOIN `aauth_perms` p ON p.`id` = ptu.`perm_id`
WHERE p.`id` IS NULL
UNION ALL
SELECT 'aauth_perm_to_user.user_id', ptu.`user_id`
FROM `aauth_perm_to_user` ptu
LEFT JOIN `aauth_users` u ON u.`id` = ptu.`user_id`
WHERE u.`id` IS NULL
UNION ALL
SELECT 'aauth_user_to_group.user_id', utg.`user_id`
FROM `aauth_user_to_group` utg
LEFT JOIN `aauth_users` u ON u.`id` = utg.`user_id`
WHERE u.`id` IS NULL
UNION ALL
SELECT 'aauth_user_to_group.group_id', utg.`group_id`
FROM `aauth_user_to_group` utg
LEFT JOIN `aauth_groups` g ON g.`id` = utg.`group_id`
WHERE g.`id` IS NULL
UNION ALL
SELECT 'aauth_user_variables.user_id', uv.`user_id`
FROM `aauth_user_variables` uv
LEFT JOIN `aauth_users` u ON u.`id` = uv.`user_id`
WHERE u.`id` IS NULL
UNION ALL
SELECT 'aauth_group_to_group.group_id', gtg.`group_id`
FROM `aauth_group_to_group` gtg
LEFT JOIN `aauth_groups` g ON g.`id` = gtg.`group_id`
WHERE g.`id` IS NULL
UNION ALL
SELECT 'aauth_group_to_group.subgroup_id', gtg.`subgroup_id`
FROM `aauth_group_to_group` gtg
LEFT JOIN `aauth_groups` g ON g.`id` = gtg.`subgroup_id`
WHERE g.`id` IS NULL
UNION ALL
SELECT 'aauth_pms.receiver_id', pms.`receiver_id`
FROM `aauth_pms` pms
LEFT JOIN `aauth_users` u ON u.`id` = pms.`receiver_id`
WHERE u.`id` IS NULL;
