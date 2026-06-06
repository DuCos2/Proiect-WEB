CREATE TABLE `cities` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `name` varchar(255),
  `county` varchar(255)
);

CREATE TABLE `users` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `username` varchar(80) NOT NULL,
  `email` varchar(255) NOT NULL UNIQUE,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('user', 'moderator', 'admin') NOT NULL DEFAULT 'user',
  `is_banned` boolean NOT NULL DEFAULT false,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `email_verifications` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` varchar(64) NOT NULL UNIQUE,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `password_resets` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` varchar(64) NOT NULL UNIQUE,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `auth_tokens` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` varchar(64) NOT NULL UNIQUE,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `sports` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `name` varchar(255)
);

CREATE TABLE `locations` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `city_id` int,
  `name` varchar(255),
  `area` varchar(255),
  `latitude` decimal,
  `longitude` decimal,
  `address` varchar(255)
);

CREATE TABLE `location_sports` (
  `location_id` int,
  `sport_id` int,
  PRIMARY KEY (`location_id`, `sport_id`)
);

CREATE TABLE `user_subscriptions` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `user_id` int,
  `city_id` int,
  `sport_id` int,
  `location_id` int
);

CREATE TABLE `events` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `location_id` int,
  `sport_id` int,
  `organizer_id` int,
  `title` varchar(255),
  `description` text,
  `event_date` datetime,
  `end_date` datetime,
  `max_participants` int,
  `min_past_participations` int,
  `skill_level` varchar(255),
  `status` varchar(255),
  `created_at` timestamp
);

CREATE TABLE `event_participants` (
  `event_id` int,
  `user_id` int,
  `joined_at` timestamp,
  PRIMARY KEY (`event_id`, `user_id`)
);

CREATE TABLE `event_comments` (
  `id` int PRIMARY KEY AUTO_INCREMENT,
  `event_id` int,
  `user_id` int,
  `comment_text` text,
  `posted_at` timestamp
);

CREATE UNIQUE INDEX `user_subscriptions_index_0` ON `user_subscriptions` (`user_id`, `city_id`, `sport_id`, `location_id`);

ALTER TABLE `locations` ADD FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`);

ALTER TABLE `location_sports` ADD FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`);

ALTER TABLE `location_sports` ADD FOREIGN KEY (`sport_id`) REFERENCES `sports` (`id`);

ALTER TABLE `user_subscriptions` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

ALTER TABLE `user_subscriptions` ADD FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`);

ALTER TABLE `user_subscriptions` ADD FOREIGN KEY (`sport_id`) REFERENCES `sports` (`id`);

ALTER TABLE `user_subscriptions` ADD FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`);

ALTER TABLE `events` ADD FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`);

ALTER TABLE `events` ADD FOREIGN KEY (`sport_id`) REFERENCES `sports` (`id`);

ALTER TABLE `events` ADD FOREIGN KEY (`organizer_id`) REFERENCES `users` (`id`);

ALTER TABLE `event_participants` ADD FOREIGN KEY (`event_id`) REFERENCES `events` (`id`);

ALTER TABLE `event_participants` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

ALTER TABLE `event_comments` ADD FOREIGN KEY (`event_id`) REFERENCES `events` (`id`);

ALTER TABLE `event_comments` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

ALTER TABLE `email_verifications` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `password_resets` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `auth_tokens` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
