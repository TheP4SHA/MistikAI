-- MistikAI Sample Data (Demo)
-- Import after schema.sql
--
-- No admin user is created here. After importing, open /admin/kurulum.php
-- (first-run setup) to create the first full admin with your own password.

SET NAMES utf8mb4;
START TRANSACTION;

-- System Settings (adding admins from the panel is closed by default)
INSERT INTO `bot_sistem_ayarlari` (`ayar_adi`, `ayar_degeri`) VALUES
('admin_kayit_acik', '0'),
('yemekhane_url', 'https://www.osmaniye.edu.tr/yemekmenu'),
('takvim_url', 'https://www.osmaniye.edu.tr/tr/lisansustu/menuler/ogrenci/genel/47321-akademik-takvim')
ON DUPLICATE KEY UPDATE `ayar_degeri` = VALUES(`ayar_degeri`);

-- Faculty
INSERT INTO `bot_fakulteler` (`id`, `ad`, `kod`) VALUES
(1, 'Osmaniye Meslek Yüksekokulu', 'OMYO');

-- Departments
INSERT INTO `bot_bolumler` (`id`, `fakulte_id`, `ad`, `kod`) VALUES
(1, 1, 'Bilgisayar Teknolojileri Bölümü', 'bilgisayar'),
(2, 1, 'Çevre Koruma Teknolojileri Bölümü', 'cevre'),
(3, 1, 'Elektrik ve Enerji Bölümü', 'elektrik'),
(4, 1, 'Elektronik ve Otomasyon Bölümü', 'elektronik'),
(5, 1, 'İnşaat Bölümü', 'insaat'),
(6, 1, 'Makine ve Metal Teknolojileri Bölümü', 'makine');

-- Modules (Intents)
INSERT INTO `bot_moduller` (`id`, `modul_kodu`, `modul_adi`, `cevap_metni`, `aktif_mi`) VALUES
(1, 'selamlama', 'Gelişmiş Selamlama ve Sohbet', 'Merhaba! Ben MistikAI, OMYO dijital asistanın.', 1),
(5, 'argo_filtresi', 'Argo & Küfür Filtresi', 'Lütfen iletişimimizde saygı çerçevesini koruyalım. 🧐', 1),
(8, 'yemekhane', 'Yemekhane Asistanı', '', 1),
(9, 'ders_programi', 'Ders Programı Asistanı', '', 1),
(10, 'akademik_takvim', 'Akademik Takvim Asistanı', '', 1),
(11, 'yetenekler', 'Kılavuz ve Yetenek Asistanı', '', 1);

-- Sample Keywords (Rule-Based Router Data)
INSERT INTO `bot_anahtar_kelimeler` (`modul_id`, `kelime`, `aktif_mi`, `cevap_override`, `oncelik`, `zaman_kontrol`) VALUES
-- Greetings
(1, 'merhaba', 1, 'Merhaba! Sana nasıl yardımcı olabilirim?', 55, NULL),
(1, 'selam', 1, 'Selam! Ne yapmak isterdin?', 55, NULL),
(1, 'günaydın', 1, 'Günaydın! Bugün harika bir gün olacak!', 80, 'sabah'),
(1, 'iyi akşamlar', 1, 'İyi akşamlar. Yardımcı olayım mı?', 90, 'aksam'),
(1, 'nasılsın', 1, 'Teşekkür ederim, iyiyim. Sen nasılsın?', 70, NULL),
(1, 'teşekkürler', 1, 'Rica ederim. Ne zaman istersen buradayım.', 80, NULL),
-- Cafeteria
(8, 'yemek', 1, NULL, 85, NULL),
(8, 'yemekhane', 1, NULL, 85, NULL),
(8, 'menü', 1, NULL, 90, NULL),
(8, 'bugün yemekte ne var', 1, NULL, 99, NULL),
(8, 'yarın yemekte ne var', 1, NULL, 98, NULL),
(8, 'haftalık menü', 1, NULL, 92, NULL),
(8, 'acıktım', 1, NULL, 75, NULL),
-- Course Schedule
(9, 'ders programı', 1, NULL, 95, NULL),
(9, 'bugün dersim var mı', 1, NULL, 99, NULL),
(9, 'yarın ders var mı', 1, NULL, 99, NULL),
(9, 'pazartesi dersleri', 1, NULL, 97, NULL),
(9, 'dersler', 1, NULL, 80, NULL),
-- Academic Calendar
(10, 'akademik takvim', 1, NULL, 100, NULL),
(10, 'finaller ne zaman', 1, NULL, 95, NULL),
(10, 'bütünleme', 1, NULL, 85, NULL),
(10, 'kayıtlar ne zaman', 1, NULL, 95, NULL);

-- Sample Course Schedule
INSERT INTO `bot_ders_programi` (`bolum_id`, `fakulte_id`, `sinif`, `gun`, `baslangic_saati`, `bitis_saati`, `ders_adi`, `ogretim_uyesi`, `derslik`, `aktif`) VALUES
(1, 1, 1, 'Pazartesi', '08:15:00', '10:00:00', 'BIL114 Bilgisayar Donanımı', 'Öğr. Gör. Mehmet KELEŞ', 'Bilgisayar Lab 2', 1),
(1, 1, 1, 'Pazartesi', '10:15:00', '12:00:00', 'BIL112 Görsel Programlama 1', 'Dr. Öğr. Üyesi Emre YILDIRIM', 'Bilgisayar Lab 1', 1),
(1, 1, 1, 'Salı', '08:15:00', '10:00:00', 'BIL118 Veri Tabanı ve Yönetimi 1', 'Öğr. Gör. Mehmet KELEŞ', 'Bilgisayar Lab 2', 1),
(1, 1, 1, 'Cuma', '09:15:00', '12:00:00', 'BIL120 Web Tasarımının Temelleri', 'Öğr. Gör. Mehmet KELEŞ', 'Bilgisayar Lab 1', 1);

-- Sample Meals
INSERT INTO `bot_zaman_cizelgesi` (`tip`, `baslik`, `detay`, `tarih`) VALUES
('yemek', 'Tavuk Şinitzel', 'Pirinç Pilavı, Ezogelin Çorbası, Ayran (981 kcal)', '2026-03-02'),
('yemek', 'Et Sote', 'Bulgur Pilavı, Ezogelin Çorbası, Salata (903 kcal)', '2026-03-03'),
('yemek', 'İnegöl Köfte + Patates', 'Makarna, Yayla Çorbası, Hoşaf (1098 kcal)', '2026-03-04'),
('yemek', 'Tavuk Baget Haşlama', 'Meyhane Pilavı, Mercimek Çorba, Salata (1003 kcal)', '2026-03-05');

-- Sample Academic Calendar
INSERT INTO `bot_akademik_takvim` (`yariyil`, `baslangic_tarihi`, `bitis_tarihi`, `faaliyet`, `aktif`) VALUES
('Bahar', '2026-02-09', '2026-05-22', 'Bahar Yarıyılı Eğitimi', 1),
('Bahar', '2026-06-01', '2026-06-12', 'Yarıyıl Sonu Sınavları (Final)', 1),
('Bahar', '2026-06-22', '2026-06-26', 'Bütünleme Sınavları', 1),
('Bahar', '2026-02-02', '2026-02-06', 'Ders Kayıtları', 1),
('Bahar', '2026-02-09', '2026-02-13', 'Ders Ekleme-Çıkarma Haftası', 1);

-- Entities (for synonym resolution)
INSERT INTO `bot_varliklar` (`id`, `kategori`, `standart_deger`, `aktif_mi`) VALUES
(1, 'bolum', 'bilgisayar', 1),
(2, 'bolum', 'cevre', 1),
(3, 'bolum', 'elektrik', 1),
(4, 'bolum', 'elektronik', 1),
(5, 'bolum', 'insaat', 1),
(6, 'bolum', 'makine', 1),
(13, 'sinif', '1', 1),
(14, 'sinif', '2', 1);

INSERT INTO `bot_varlik_esanlamlilar` (`varlik_id`, `kelime`) VALUES
(1, 'yazılım'), (1, 'yazilim'), (1, 'bil prog'),
(5, 'yapı'), (5, 'yapi'),
(6, 'metal'), (4, 'otomasyon');

-- Dialog Flow Menus
INSERT INTO `bot_akis_menuleri` (`id`, `menu_kodu`, `mesaj`, `aktif_mi`) VALUES
(1, 'ana_menu', 'İşte yapabileceklerim! Hangisini denemek istersin? 🚀', 1);

INSERT INTO `bot_akis_butonlari` (`ust_menu_id`, `buton_metni`, `aksiyon_tipi`, `hedef_veri`, `siralama`) VALUES
(1, '📚 Bugün dersim var mı?', 'modul', 'ders_programi', 1),
(1, '🍽️ Bugün yemekte ne var?', 'modul', 'yemekhane', 2),
(1, '📅 Akademik takvimi göster', 'modul', 'akademik_takvim', 3);

COMMIT;
