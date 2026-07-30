<?php
// Yemekhane Modülü - Eklenti Dosyası
// Mevcut Değişkenler: $this (Motor Nesnesi), $clean_input, $raw_input, $this->db (Veritabanı)

$hedef_tarih = date('Y-m-d'); 
$zaman_metni = "Bugünün"; 
$is_hafta = false;

if (strpos($clean_input, 'yarin') !== false) { 
    $hedef_tarih = date('Y-m-d', strtotime('+1 day')); 
    $zaman_metni = "Yarının"; 
} elseif (strpos($clean_input, 'dun') !== false) { 
    $hedef_tarih = date('Y-m-d', strtotime('-1 day')); 
    $zaman_metni = "Dünün"; 
} elseif (strpos($clean_input, 'hafta') !== false) { 
    $is_hafta = true; 
}

// Hafta İçi Fonksiyonu
if (!function_exists('getGunAdi')) {
    function getGunAdi($index) {
        $gunler = [1=>'Pazartesi', 2=>'Salı', 3=>'Çarşamba', 4=>'Perşembe', 5=>'Cuma', 6=>'Cumartesi', 7=>'Pazar'];
        return $gunler[$index] ?? '';
    }
}

if ($is_hafta) {
    $stmt = $this->db->query("SELECT tarih, baslik, detay FROM bot_zaman_cizelgesi WHERE tip='yemek' AND tarih >= CURDATE() AND tarih <= DATE_ADD(CURDATE(), INTERVAL 6 DAY) ORDER BY tarih ASC");
    $menuler = $stmt->fetchAll();
    
    if (empty($menuler)) return $this->response("Bu haftanın menüsü henüz sisteme girilmemiş.");
    
    $cevap = "🍱 <b>Bu Haftanın Yemek Menüsü:</b><br><br>";
    foreach ($menuler as $m) {
        if(date('N', strtotime($m['tarih'])) < 6) {
            $gun_adi = getGunAdi(date('N', strtotime($m['tarih']))); 
            $tarih_str = date('d.m.Y', strtotime($m['tarih']));
            $safe_baslik = htmlspecialchars((string)$m['baslik'], ENT_QUOTES, 'UTF-8'); 
            $safe_detay = htmlspecialchars((string)$m['detay'], ENT_QUOTES, 'UTF-8');
            $cevap .= "📅 <b>{$gun_adi} ({$tarih_str})</b><br>🍲 {$safe_baslik}<br>📝 <small>{$safe_detay}</small><br><br>";
        }
    }
    return $this->response($cevap);
}

// Günlük Sorgu
$stmt = $this->db->prepare("SELECT baslik, detay FROM bot_zaman_cizelgesi WHERE tip='yemek' AND tarih = :tarih");
$stmt->execute([':tarih' => $hedef_tarih]); 
$menu = $stmt->fetch();

if ($menu) {
    $gun_adi_full = getGunAdi(date('N', strtotime($hedef_tarih))); 
    $tarih_full = date('d.m.Y', strtotime($hedef_tarih));
    $safe_baslik = htmlspecialchars((string)$menu['baslik'], ENT_QUOTES, 'UTF-8'); 
    $safe_detay = htmlspecialchars((string)$menu['detay'], ENT_QUOTES, 'UTF-8');
    
    return $this->response("🍱 <b>{$tarih_full} {$gun_adi_full} Menüsü:</b><br><br>🍲 <b>{$safe_baslik}</b><br>📝 <small>{$safe_detay}</small>");
} else {
    return $this->response("Belirtilen tarihte menü henüz sistemde bulunmuyor (Hafta sonu veya tatil olabilir).");
}
?>