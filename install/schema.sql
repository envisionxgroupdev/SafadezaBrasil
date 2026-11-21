-- ============================================================
--  Esquema MySQL do site
--  1) Crie um banco (utf8mb4) na hospedagem
--  2) Importe este arquivo pelo phpMyAdmin
--  3) No config.php: DB_DRIVER = 'mysql' e preencha os dados
--  Já importou vídeos no modo JSON? Antes do passo 3, acesse
--  /admin/migrar.php para copiar tudo para o MySQL.
--  (Se for migrar, NÃO rode o INSERT de categorias no fim deste arquivo.)
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `videos` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(255) NOT NULL,
  `slug`        VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `provider`    VARCHAR(40)  NOT NULL DEFAULT 'custom',
  `source_url`  VARCHAR(500) NULL,
  `source_id`   VARCHAR(120) NULL,
  `embed_url`   VARCHAR(500) NOT NULL,
  `thumb`       VARCHAR(500) NULL,
  `duration`    INT UNSIGNED NOT NULL DEFAULT 0,
  `tags`        TEXT NULL,
  `categories`  VARCHAR(255) NULL,
  `models`      VARCHAR(255) NULL,
  `views`       INT UNSIGNED NOT NULL DEFAULT 0,
  `likes`       INT UNSIGNED NOT NULL DEFAULT 0,
  `dislikes`    INT UNSIGNED NOT NULL DEFAULT 0,
  `featured`    TINYINT(1)   NOT NULL DEFAULT 0,
  `status`      VARCHAR(20)  NOT NULL DEFAULT 'published',
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  UNIQUE KEY `uniq_source` (`provider`, `source_id`),
  KEY `idx_status_created` (`status`, `created_at`),
  KEY `idx_views` (`views`),
  FULLTEXT KEY `ft_search` (`title`, `tags`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `slug`        VARCHAR(120) NOT NULL,
  `description` TEXT NULL,
  `thumb`       VARCHAR(500) NULL,
  `sort`        INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reports` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `video_id`   INT UNSIGNED NOT NULL,
  `reason`     VARCHAR(60)  NOT NULL,
  `details`    TEXT NULL,
  `ip`         VARCHAR(64)  NULL,
  `status`     VARCHAR(20)  NOT NULL DEFAULT 'open',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(60)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey`   VARCHAR(80) NOT NULL,
  `svalue` MEDIUMTEXT  NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categorias iniciais
INSERT IGNORE INTO `categories` (`name`, `slug`, `sort`) VALUES
('Amadores','amadores',1),('Brasileiras','brasileiras',2),('Casais','casais',3),
('HD','hd',4),('Latinas','latinas',5),('Loiras','loiras',6),('Morenas','morenas',7),
('Ruivas','ruivas',8),('Lésbicas','lesbicas',9),('Maduras','maduras',10),
('POV','pov',11),('Asiáticas','asiaticas',12);
