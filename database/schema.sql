-- MistikAI Database Schema
-- Version: 1.0
-- Character Set: utf8mb4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET NAMES utf8mb4 */;

-- ========================================
-- Admin Users (Panel Yöneticileri)
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kullanici_adi` varchar(50) NOT NULL,
  `sifre` varchar(255) NOT NULL COMMENT 'bcrypt hashed',
  `ad_soyad` varchar(100) NOT NULL,
  `kayit_tarihi` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `kullanici_adi` (`kullanici_adi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ========================================
-- Fakülteler
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_fakulteler` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ad` varchar(255) NOT NULL,
  `kod` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kod` (`kod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ========================================
-- Bölümler
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_bolumler` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fakulte_id` int(11) NOT NULL,
  `ad` varchar(255) NOT NULL,
  `kod` varchar(50) NOT NULL,
  `program_link` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fakulte_id` (`fakulte_id`),
  CONSTRAINT `bot_bolumler_ibfk_1` FOREIGN KEY (`fakulte_id`) REFERENCES `bot_fakulteler` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ========================================
-- Modüller (Niyet Tanımları)
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_moduller` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `modul_kodu` varchar(50) NOT NULL COMMENT 'Benzersiz niyet kodu (örn: yemekhane)',
  `modul_adi` varchar(100) NOT NULL COMMENT 'Görünür isim',
  `cevap_metni` text DEFAULT NULL COMMENT 'Plugin yoksa kullanılan sabit cevap',
  `buton_metni` varchar(100) DEFAULT NULL,
  `link_url` text DEFAULT NULL,
  `aktif_mi` tinyint(1) DEFAULT 1,
  `olusturulma_tarihi` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `modul_kodu` (`modul_kodu`),
  KEY `idx_modul_aktif` (`aktif_mi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- Anahtar Kelimeler (Rule-Based Router'ın Kalbi)
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_anahtar_kelimeler` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `modul_id` int(11) NOT NULL COMMENT 'Hangi modüle yönlendirilecek',
  `kelime` varchar(100) NOT NULL COMMENT 'Eşleştirilecek kelime/cümle',
  `aktif_mi` tinyint(1) DEFAULT 1,
  `cevap_override` text DEFAULT NULL COMMENT 'Bu kelime eşleşince doğrudan verilecek özel cevap',
  `oncelik` int(11) DEFAULT 1 COMMENT 'Öncelik skoru (1-100). Yüksek = daha öncelikli',
  `zaman_kontrol` varchar(20) DEFAULT NULL COMMENT 'sabah|gun|aksam|gece - zaman bağlamı filtresi',
  PRIMARY KEY (`id`),
  KEY `modul_id` (`modul_id`),
  KEY `idx_kelime_modul` (`modul_id`),
  CONSTRAINT `fk_anahtar_modul` FOREIGN KEY (`modul_id`) REFERENCES `bot_moduller` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- Varlıklar (Entity - Bölüm/Sınıf gibi)
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_varliklar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kategori` varchar(50) NOT NULL COMMENT 'bolum, sinif gibi',
  `standart_deger` varchar(50) NOT NULL,
  `aktif_mi` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- Varlık Eş Anlamlıları (Synonym Resolution)
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_varlik_esanlamlilar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `varlik_id` int(11) NOT NULL,
  `kelime` varchar(50) NOT NULL COMMENT 'Eş anlamlı kelime',
  PRIMARY KEY (`id`),
  KEY `varlik_id` (`varlik_id`),
  CONSTRAINT `fk_esanlam_varlik` FOREIGN KEY (`varlik_id`) REFERENCES `bot_varliklar` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- Sohbet Logları (Deep Logger)
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_sohbet_loglari` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(100) NOT NULL,
  `kullanici_mesaji` text NOT NULL,
  `bot_cevabi` text DEFAULT NULL,
  `eslesen_modul_id` int(11) DEFAULT NULL,
  `algilanan_niyet` varchar(50) DEFAULT NULL,
  `guven_skoru` int(11) DEFAULT 0 COMMENT '0-100 güven skoru',
  `anlasildi_mi` tinyint(1) DEFAULT 0 COMMENT '1=anlaşıldı, 0=kör nokta',
  `islem_tarihi` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `eslesen_modul_id` (`eslesen_modul_id`),
  KEY `idx_log_anlasildi` (`anlasildi_mi`),
  CONSTRAINT `fk_log_modul` FOREIGN KEY (`eslesen_modul_id`) REFERENCES `bot_moduller` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- Akademik Takvim
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_akademik_takvim` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `yariyil` varchar(20) NOT NULL COMMENT 'Güz veya Bahar',
  `baslangic_tarihi` date NOT NULL,
  `bitis_tarihi` date DEFAULT NULL,
  `faaliyet` varchar(255) NOT NULL,
  `aktif` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_takvim_sorgu` (`bitis_tarihi`, `aktif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ========================================
-- Ders Programı
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_ders_programi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bolum_id` int(11) NOT NULL,
  `fakulte_id` int(11) NOT NULL,
  `sinif` tinyint(4) NOT NULL,
  `gun` varchar(15) NOT NULL COMMENT 'Pazartesi|Salı|...',
  `baslangic_saati` time NOT NULL,
  `bitis_saati` time NOT NULL,
  `ders_adi` varchar(255) NOT NULL,
  `ogretim_uyesi` varchar(255) DEFAULT NULL,
  `derslik` varchar(255) DEFAULT NULL,
  `aktif` tinyint(1) DEFAULT 1,
  `olusturma_tarihi` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fakulte_id` (`fakulte_id`),
  KEY `idx_ders_sorgu` (`bolum_id`, `sinif`, `gun`, `aktif`),
  CONSTRAINT `bot_ders_programi_ibfk_1` FOREIGN KEY (`bolum_id`) REFERENCES `bot_bolumler` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bot_ders_programi_ibfk_2` FOREIGN KEY (`fakulte_id`) REFERENCES `bot_fakulteler` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ========================================
-- Zaman Çizelgesi (Yemek Menüleri, Etkinlikler)
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_zaman_cizelgesi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tip` enum('yemek','ders','etkinlik','akademik_takvim') NOT NULL,
  `baslik` varchar(255) NOT NULL,
  `detay` text DEFAULT NULL,
  `bolum_kodu` varchar(50) DEFAULT NULL,
  `sinif` tinyint(4) DEFAULT NULL,
  `tarih` date DEFAULT NULL,
  `baslangic_saati` time DEFAULT NULL,
  `bitis_saati` time DEFAULT NULL,
  `gun` enum('Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar') DEFAULT NULL,
  `olusturma_tarihi` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_zaman_sorgu` (`tarih`, `tip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ========================================
-- İçerik Yönetimi
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_icerik_yonetimi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bolum_kodu` varchar(50) NOT NULL,
  `sinif` varchar(10) NOT NULL,
  `icerik_tipi` varchar(30) DEFAULT 'ders_programi',
  `link_url` text NOT NULL,
  `guncelleme_tarihi` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sorgu` (`bolum_kodu`, `sinif`, `icerik_tipi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- API Rate Limiting
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_api_rate_limit` (
  `ip_address` varchar(45) NOT NULL,
  `request_count` int(11) NOT NULL,
  `last_request` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ========================================
-- Sistem Ayarları
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_sistem_ayarlari` (
  `ayar_adi` varchar(50) NOT NULL,
  `ayar_degeri` varchar(255) NOT NULL,
  PRIMARY KEY (`ayar_adi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ========================================
-- Diyalog Akış Menüleri (Flow Builder)
-- ========================================
CREATE TABLE IF NOT EXISTS `bot_akis_menuleri` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_kodu` varchar(50) NOT NULL,
  `mesaj` text NOT NULL,
  `ust_menu_id` int(11) DEFAULT NULL,
  `aktif_mi` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `menu_kodu` (`menu_kodu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_akis_butonlari` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ust_menu_id` int(11) NOT NULL,
  `buton_metni` varchar(100) NOT NULL,
  `aksiyon_tipi` enum('alt_menu','modul','link') NOT NULL DEFAULT 'alt_menu',
  `hedef_veri` varchar(255) NOT NULL,
  `siralama` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ust_menu_id` (`ust_menu_id`),
  CONSTRAINT `fk_buton_menu` FOREIGN KEY (`ust_menu_id`) REFERENCES `bot_akis_menuleri` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
