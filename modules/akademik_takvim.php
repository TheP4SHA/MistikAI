<?php
// Akademik Takvim Modülü
// Variables: $this (MistikEngine), $clean_input (lowercase, ASCII-folded), $raw_input

$aranan = "";
$etiket = "Yaklaşan Akademik Takvim";

// $clean_input küçük harfli, noktalamasız ve ASCII katlanmıştır ("Bütünleme" → "butunleme").
// Kelime başına bakılır: "but" artık "bütün" kelimesini yakalamaz.
if (preg_match('/\b(kayit|kayitlar)/', $clean_input)) {
    $aranan = "Kayıt"; $etiket = "Ders Kayıt Tarihleri";
} elseif (preg_match('/\b(ekle|ekleme|cikar|cikarma|ders sil)/', $clean_input)) {
    $aranan = "Ekleme-Çıkarma"; $etiket = "Ders Ekleme/Çıkarma Tarihleri";
} elseif (preg_match('/\b(final|yariyil sonu|donem sonu)/', $clean_input)) {
    $aranan = "Yarıyıl Sonu Sınavları"; $etiket = "Final Sınav Tarihleri";
} elseif (preg_match('/\bbutunleme|\bbut\b/', $clean_input)) {
    $aranan = "Bütünleme"; $etiket = "Bütünleme Sınav Tarihleri";
} elseif (preg_match('/\b(vize|ara sinav)/', $clean_input)) {
    $aranan = "Ara Sınav"; $etiket = "Vize (Ara Sınav) Tarihleri";
}

if ($aranan !== "") {
    $sql = "SELECT * FROM bot_akademik_takvim WHERE faaliyet LIKE :aranan AND aktif = 1 ORDER BY baslangic_tarihi ASC";
    $stmt = $this->db->prepare($sql); 
    $stmt->execute([':aranan' => "%$aranan%"]); 
    $takvim = $stmt->fetchAll();
} else {
    $sql = "SELECT * FROM bot_akademik_takvim WHERE bitis_tarihi >= CURDATE() AND aktif = 1 ORDER BY baslangic_tarihi ASC LIMIT 4";
    $stmt = $this->db->query($sql); 
    $takvim = $stmt->fetchAll();
}

if (empty($takvim)) {
    return $this->response("Sorduğunuz konuyla ilgili akademik takvimde aktif bir kayıt bulunamadı. Güncel bilgi için Öğrenci İşleri'ne danışabilirsiniz.");
}

$cevap = "📅 <b>{$etiket}</b><br><br>";
foreach ($takvim as $t) {
    $bas = date('d.m.Y', strtotime($t['baslangic_tarihi']));
    $bit = $t['bitis_tarihi'] ? date('d.m.Y', strtotime($t['bitis_tarihi'])) : $bas;
    $tarih_metni = ($bas == $bit) ? $bas : "{$bas} - {$bit}";
    $safe_faaliyet = htmlspecialchars((string)$t['faaliyet'], ENT_QUOTES, 'UTF-8');
    $safe_yariyil = htmlspecialchars((string)$t['yariyil'], ENT_QUOTES, 'UTF-8');
    $cevap .= "📌 <b>{$safe_faaliyet}</b><br>⏳ {$tarih_metni} ({$safe_yariyil} Yarıyılı)<br>────────────────<br>";
}

return $this->response($cevap);
