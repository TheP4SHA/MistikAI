<?php
// Akademik Takvim Modülü
// Variables: $this (MistikEngine), $clean_input, $raw_input

$aranan = ""; 
$etiket = "Yaklaşan Akademik Takvim";

if (strpos($clean_input, 'kayit') !== false || strpos($clean_input, 'kayıt') !== false) { 
    $aranan = "Kayıt"; $etiket = "Ders Kayıt Tarihleri"; 
} elseif (strpos($clean_input, 'ekle') !== false || strpos($clean_input, 'cikar') !== false || strpos($clean_input, 'sil') !== false) { 
    $aranan = "Ekleme-Çıkarma"; $etiket = "Ders Ekleme/Çıkarma Tarihleri"; 
} elseif (strpos($clean_input, 'final') !== false || strpos($clean_input, 'yariyil sonu') !== false || strpos($clean_input, 'yarıyıl sonu') !== false) { 
    $aranan = "Yarıyıl Sonu Sınavları"; $etiket = "Final Sınav Tarihleri"; 
} elseif (strpos($clean_input, 'butunleme') !== false || strpos($clean_input, 'but') !== false || strpos($clean_input, 'bütünleme') !== false) { 
    $aranan = "Bütünleme"; $etiket = "Bütünleme Sınav Tarihleri"; 
} elseif (strpos($clean_input, 'vize') !== false || strpos($clean_input, 'ara sinav') !== false || strpos($clean_input, 'ara sınav') !== false) {
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
    return $this->response("Sorduğunuz konuyla ilgili akademik takvimde aktif bir kayıt bulunamadı.");
}

$cevap = "📅 <b>{$etiket}</b><br><br>";
foreach ($takvim as $t) {
    $bas = date('d.m.Y', strtotime($t['baslangic_tarihi'])); 
    $bit = date('d.m.Y', strtotime($t['bitis_tarihi']));
    $tarih_metni = ($bas == $bit) ? $bas : "{$bas} - {$bit}";
    $safe_faaliyet = htmlspecialchars((string)$t['faaliyet'], ENT_QUOTES, 'UTF-8');
    $cevap .= "📌 <b>{$safe_faaliyet}</b><br>⏳ {$tarih_metni} ({$t['yariyil']} Yarıyılı)<br>────────────────<br>";
}

return $this->response($cevap);
