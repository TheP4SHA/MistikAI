<?php
// Yemekhane Modülü - Eklenti Dosyası
// Mevcut Değişkenler: $this (Motor Nesnesi), $clean_input, $raw_input, $this->db (Veritabanı)
// $clean_input küçük harfli, noktalamasız ve ASCII katlanmıştır: "Yarın yemekte ne var?" → "yarin yemekte ne var"

$bugun = new DateTimeImmutable('today');
$hedef = $bugun;
$zaman_metni = "Bugünün";
$is_hafta = false;

$goreli = TarihYardimcisi::goreliGun($clean_input);
$gun_adi = TarihYardimcisi::gunBul($clean_input);

if ($goreli !== null && $goreli !== 0) {
    $hedef = $bugun->modify(($goreli > 0 ? '+' : '') . $goreli . ' day');
    $zaman_metni = [1 => 'Yarının', 2 => 'Öbür günün', -1 => 'Dünün'][$goreli] ?? 'O günün';
} elseif ($goreli === null && $gun_adi !== null) {
    // "cuma yemek ne" → bu haftanın (ya da gelecek) cuması
    $hedef = TarihYardimcisi::siradakiTarih($gun_adi, $bugun) ?? $bugun;
    $zaman_metni = $gun_adi . ' gününün';
} elseif ($goreli === null && preg_match('/\b(hafta|haftalik|haftanin|haftaki)\b/', $clean_input)) {
    $is_hafta = true;
}

if ($is_hafta) {
    $stmt = $this->db->query("SELECT tarih, baslik, detay FROM bot_zaman_cizelgesi WHERE tip='yemek' AND tarih >= CURDATE() AND tarih <= DATE_ADD(CURDATE(), INTERVAL 6 DAY) ORDER BY tarih ASC");
    $menuler = $stmt->fetchAll();

    if (empty($menuler)) {
        return $this->response("Bu haftanın menüsü henüz sisteme girilmemiş.");
    }

    $cevap = "🍱 <b>Bu Haftanın Yemek Menüsü:</b><br><br>";
    foreach ($menuler as $m) {
        $gun_no = (int)date('N', strtotime($m['tarih']));
        if ($gun_no < 6) {
            $gun = TarihYardimcisi::gunAdi($gun_no);
            $tarih_str = date('d.m.Y', strtotime($m['tarih']));
            $safe_baslik = htmlspecialchars((string)$m['baslik'], ENT_QUOTES, 'UTF-8');
            $safe_detay = htmlspecialchars((string)$m['detay'], ENT_QUOTES, 'UTF-8');
            $cevap .= "📅 <b>{$gun} ({$tarih_str})</b><br>🍲 {$safe_baslik}<br>📝 <small>{$safe_detay}</small><br><br>";
        }
    }
    return $this->response($cevap);
}

// Günlük Sorgu
$hedef_tarih = $hedef->format('Y-m-d');
$stmt = $this->db->prepare("SELECT baslik, detay FROM bot_zaman_cizelgesi WHERE tip='yemek' AND tarih = :tarih");
$stmt->execute([':tarih' => $hedef_tarih]);
$menu = $stmt->fetch();

$gun_adi_full = TarihYardimcisi::gunAdi((int)$hedef->format('N'));
$tarih_full = $hedef->format('d.m.Y');

if ($menu) {
    $safe_baslik = htmlspecialchars((string)$menu['baslik'], ENT_QUOTES, 'UTF-8');
    $safe_detay = htmlspecialchars((string)$menu['detay'], ENT_QUOTES, 'UTF-8');

    return $this->response("🍱 <b>{$zaman_metni} Menüsü — {$tarih_full} {$gun_adi_full}</b><br><br>🍲 <b>{$safe_baslik}</b><br>📝 <small>{$safe_detay}</small>");
}

return $this->response("{$tarih_full} {$gun_adi_full} için menü henüz sistemde bulunmuyor (hafta sonu veya tatil olabilir).",
    [['text' => '📅 Haftalık menü', 'payload' => 'modul_yemekhane']]);
