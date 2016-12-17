CREATE TABLE `deals` ( 
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(40) NOT NULL,
  `changed` int NOT NULL COMMENT 'unixtime',
  `status` enum('new','processing','paid','dispute','complete','payout') CHARACTER SET utf8 COLLATE utf8_bin NOT NULL DEFAULT 'new',
  `description_pub` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL COMMENT 'base64',
  `description_sec` mediumtext CHARACTER SET utf8 COLLATE utf8_bin NOT NULL COMMENT 'base64',
  `files_pub` text CHARACTER SET utf8 COLLATE utf8_bin COMMENT 'json in base64',
  `files_sec` text CHARACTER SET utf8 COLLATE utf8_bin COMMENT 'json in base64',
  `price` float(20,8) NOT NULL,
  `store_days` tinyint NOT NULL DEFAULT '7', 
  `btc` varchar(35) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT NULL COMMENT 'payment address',
  `payout` varchar(35) CHARACTER SET utf8 COLLATE utf8_bin DEFAULT NULL COMMENT 'payout address',
  `code_seller` varchar(3) DEFAULT NULL,
  `code_buyer` varchar(3) DEFAULT NULL,
  `code_dispute` varchar(3) DEFAULT NULL,
  `url_seller` varchar(40) DEFAULT NULL,
  `url_buyer` varchar(40) DEFAULT NULL,
  `url_dispute` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `chat` (
  `id` int NOT NULL AUTO_INCREMENT,
  `deal_id` int NOT NULL,
  `author` enum('buyer','seller','dispute') NOT NULL,
  `unixtime` int NOT NULL,
  `message` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL COMMENT 'base64',
  PRIMARY KEY (`id`),
  CONSTRAINT `chat_key` FOREIGN KEY (`deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

/* TODO: вывод денег арбитру */
CREATE TABLE `payouts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created` int NOT NULL COMMENT 'unixtime',
  `paid` int DEFAULT NULL COMMENT 'unixtime',
  `owner` enum('service','dispute') NOT NULL,
  `amount` float(20,8) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

