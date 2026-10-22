-- MistikAI migration 2026-10: security hardening (schema 1.0 -> 1.1)
-- Run once on an existing installation, after taking a backup:
--   mysql -u USER -p DBNAME < database/migrations/2026-10-guvenlik.sql
-- Fresh installs do not need it (schema.sql already contains these changes).
--
-- Re-running is safe: every column/index/table is added only when missing
-- (checked via information_schema, so it works on both MySQL 5.7+ and MariaDB).
--
-- IMPORTANT: the admin account that still uses the password hash shipped in the
-- old seed.sql (it is the public hash of the word "password") is DELETED below.
-- If that was your only admin, open /admin/kurulum.php afterwards and create a
-- new one; the setup page works while bot_admin_users is empty.

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------
-- 1) bot_admin_users.yetki  ('tam' = full admin, 'kisitli' = restricted)
-- ----------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `bot_admin_users` ADD COLUMN `yetki` enum(''tam'',''kisitli'') NOT NULL DEFAULT ''kisitli'' COMMENT ''tam: kullanıcı ekleme, kod editörü, SQL terminali'' AFTER `ad_soyad`',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bot_admin_users' AND COLUMN_NAME = 'yetki');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Remove the account that still has the publicly known seed hash.
DELETE FROM `bot_admin_users`
  WHERE `sifre` = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

-- The old code treated admin id 1 as the root user; keep that meaning.
UPDATE `bot_admin_users` SET `yetki` = 'tam' WHERE `id` = 1;

-- If there is still no full admin, promote the oldest account.
UPDATE `bot_admin_users` SET `yetki` = 'tam'
  WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `bot_admin_users` WHERE `yetki` = 'tam') AS t)
  ORDER BY `id` ASC LIMIT 1;

-- ----------------------------------------------------------------------
-- 2) bot_sohbet_loglari: response layer, latency, date index (retention)
-- ----------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `bot_sohbet_loglari` ADD COLUMN `katman` varchar(10) DEFAULT NULL COMMENT ''Cevabı üreten katman: kural|bert|llm|akis|komut|sinir'' AFTER `anlasildi_mi`',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bot_sohbet_loglari' AND COLUMN_NAME = 'katman');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `bot_sohbet_loglari` ADD COLUMN `gecikme_ms` int(11) DEFAULT NULL COMMENT ''Cevap üretme süresi (milisaniye)'' AFTER `katman`',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bot_sohbet_loglari' AND COLUMN_NAME = 'gecikme_ms');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `bot_sohbet_loglari` ADD KEY `idx_log_tarih` (`islem_tarihi`)',
    'DO 0')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bot_sohbet_loglari' AND INDEX_NAME = 'idx_log_tarih');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- session_id now stores a 64-char salted IP hash; varchar(100) already fits.
ALTER TABLE `bot_sohbet_loglari`
  MODIFY COLUMN `session_id` varchar(100) NOT NULL COMMENT 'Ziyaretçinin tuzlu IP özeti (64 karakter hex), ham IP değil';

-- ----------------------------------------------------------------------
-- 3) bot_api_rate_limit.ip_address holds the IP hash (64 chars)
-- ----------------------------------------------------------------------
ALTER TABLE `bot_api_rate_limit`
  MODIFY COLUMN `ip_address` varchar(64) NOT NULL COMMENT 'Tuzlu IP özeti (64 karakter hex)';

-- ----------------------------------------------------------------------
-- 4) Admin login attempts (brute-force throttle)
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bot_giris_denemeleri` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ip_ozeti` char(64) NOT NULL COMMENT 'Tuzlu IP özeti',
  `kullanici_adi` varchar(50) NOT NULL COMMENT 'Denenen kullanıcı adı (küçük harf)',
  `deneme_tarihi` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_giris_ip_kullanici` (`ip_ozeti`, `kullanici_adi`, `deneme_tarihi`),
  KEY `idx_giris_tarih` (`deneme_tarihi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------
-- 5) Close panel registration. It now means "full admins may add users"
--    and has to be switched on deliberately in Sistem Ayarları.
-- ----------------------------------------------------------------------
INSERT INTO `bot_sistem_ayarlari` (`ayar_adi`, `ayar_degeri`) VALUES ('admin_kayit_acik', '0')
  ON DUPLICATE KEY UPDATE `ayar_degeri` = '0';

-- ----------------------------------------------------------------------
-- 6) Flow buttons saved by the old builder with 'modul_tetikle'/'url_git'
--    ended up as '' (invalid enum value). Map them back where possible.
-- ----------------------------------------------------------------------
UPDATE `bot_akis_butonlari` SET `aksiyon_tipi` = 'link'
  WHERE `aksiyon_tipi` = '' AND (`hedef_veri` LIKE 'http://%' OR `hedef_veri` LIKE 'https://%');

UPDATE `bot_akis_butonlari` b
  JOIN `bot_moduller` m ON m.`modul_kodu` = b.`hedef_veri`
  SET b.`aksiyon_tipi` = 'modul'
  WHERE b.`aksiyon_tipi` = '';
