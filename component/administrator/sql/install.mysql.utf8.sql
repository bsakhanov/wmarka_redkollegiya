CREATE TABLE IF NOT EXISTS `#__wmarka_redkollegiya_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime NOT NULL,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `item_id` int unsigned NOT NULL DEFAULT 0,
  `context` varchar(100) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT '',
  `lang` varchar(8) NOT NULL DEFAULT '',
  `changes` int unsigned NOT NULL DEFAULT 0,
  `stats` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created`),
  KEY `idx_item` (`context`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
