ALTER TABLE `locations`
  ADD COLUMN IF NOT EXISTS `area` varchar(255) NULL AFTER `name`;

CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` varchar(64) NOT NULL UNIQUE,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `auth_tokens_user_id_fk`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);
