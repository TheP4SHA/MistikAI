<?php
// Ders Programı Modülü - Eklenti Dosyası
// Mevcut Değişkenler: $this (Motor Nesnesi), $clean_input, $raw_input, $this->db (Veritabanı)
// Bölüm/sınıf seçimi $_SESSION['context'] içinde tutulur (motor: set_program_<bolum>_<sinif>).

// 1. Hafızadaki (Session) bilgileri al
$context = is_array($_SESSION['context'] ?? null) ? $_SESSION['context'] : [];
$bolum_std = isset($context['bolum']) ? (string)$context['bolum'] : null;
$sinif = isset($context['sinif']) ? (int)$context['sinif'] : null;
$bolum_id = isset($context['bolum_id']) ? (int)$context['bolum_id'] : null;

// 2. Eksik bilgi varsa kullanıcıya sor (Dinamik Butonlarla)
if ((!$bolum_std && !$bolum_id) || !$sinif) {
    $stmt = $this->db->query("SELECT DISTINCT d.bolum_id, d.sinif, b.ad AS bolum_adi FROM bot_ders_programi d JOIN bot_bolumler b ON d.bolum_id = b.id WHERE d.aktif = 1 ORDER BY b.ad, d.sinif");
    $combos = $stmt->fetchAll();
    
    if (empty($combos)) {
        return $this->response("Sistemde henüz ders programı bulunmamaktadır.");
    }
    
    $buttons = [];
    foreach ($combos as $combo) {
        // Buton metni istemcide textContent ile yazılır; kaçış gerekmez.
        $text = $combo['bolum_adi'] . ' ' . (int)$combo['sinif'] . '. Sınıf';
        $payload = "set_program_" . (int)$combo['bolum_id'] . '_' . (int)$combo['sinif'];
        $buttons[] = ['text' => $text, 'payload' => $payload];
    }
    return $this->response("Hangi bölüm ve sınıfın ders programını öğrenmek istersiniz?", $buttons);
}

// 3. Bölüm Adını ve ID'sini Garantile
$bolum_adi = "";
if (!$bolum_id) {
    $stmt = $this->db->prepare("SELECT id, ad FROM bot_bolumler WHERE ad = ? OR kod = ? LIMIT 1");
    $stmt->execute([$bolum_std, $bolum_std]); 
    $bolum_data = $stmt->fetch();
    
    if (!$bolum_data) {
        return $this->response("Üzgünüm, belirttiğiniz bölüm sistemde tanımlı değil.");
    }
    $bolum_id = $bolum_data['id']; 
    $bolum_adi = htmlspecialchars((string)$bolum_data['ad'], ENT_QUOTES, 'UTF-8');
} else {
    $stmt = $this->db->prepare("SELECT ad FROM bot_bolumler WHERE id = ?"); 
    $stmt->execute([$bolum_id]);
    $bolum_adi = htmlspecialchars((string)$stmt->fetchColumn() ?: "Bölüm", ENT_QUOTES, 'UTF-8');
}

// 4. Niyet Analizi: Tüm haftayı mı soruyor, belli bir günü mü?
// $clean_input küçük harfli, noktalamasız ve ASCII katlanmıştır ("Salı" → "sali"); kelime sınırıyla bakılır.
$tum_program_mi = (bool)preg_match('/\b(tum|butun|haftalik|hafta|hepsi|hepsini)\b/', $clean_input);

// Bölüm değiştirme butonu her cevabın altında
$program_butonlari = [
    ['text' => '📅 Haftalık program', 'payload' => 'modul_ders_programi'],
    ['text' => '🔁 Bölüm/sınıf değiştir', 'payload' => 'set_program_sifirla'],
];

// A) TÜM HAFTALIK PROGRAM İSTENİYORSA
if ($tum_program_mi) {
    $stmt = $this->db->prepare("SELECT * FROM bot_ders_programi WHERE bolum_id = :bid AND sinif = :sinif AND aktif = 1 ORDER BY FIELD(gun, 'Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi','Pazar'), baslangic_saati ASC");
    $stmt->execute([':bid' => $bolum_id, ':sinif' => $sinif]); 
    $tum_dersler = $stmt->fetchAll();
    
    if (empty($tum_dersler)) {
        return $this->response("<b>{$bolum_adi} {$sinif}. Sınıf</b> için sisteme kayıtlı ders bulunamadı.", [$program_butonlari[1]]);
    }

    $cevap = "📚 <b>{$bolum_adi} - {$sinif}. Sınıf Haftalık Program:</b><br>"; 
    $dongu_gun = "";
    
    foreach ($tum_dersler as $d) {
        if ($dongu_gun !== $d['gun']) { 
            $dongu_gun = $d['gun']; 
            $cevap .= "<br>📅 <b>{$dongu_gun}</b><br>"; 
        }
        $bas = substr($d['baslangic_saati'], 0, 5); 
        $bit = substr($d['bitis_saati'], 0, 5);
        $safe_adi = htmlspecialchars((string)$d['ders_adi'], ENT_QUOTES, 'UTF-8'); 
        $safe_yer = htmlspecialchars((string)$d['derslik'], ENT_QUOTES, 'UTF-8');
        
        $cevap .= "🕒 {$bas}-{$bit} | 📖 {$safe_adi} | 📍 {$safe_yer}<br>";
    }
    return $this->response($cevap, [$program_butonlari[1]]);
}

// B) BELİRLİ BİR GÜN VEYA BUGÜN/YARIN İSTENİYORSA
// Gün adı TarihYardimcisi ile çözülür: "cumartesi" asla "Cuma" olmaz, "Pazartesi" doğru yazılır, "salı/sali" tanınır.
$hedef_gun = TarihYardimcisi::gunAdi((int)date('N'));
$zaman_etiketi = "Bugün";

$goreli = TarihYardimcisi::goreliGun($clean_input);
$istenen_gun = TarihYardimcisi::gunBul($clean_input);
if ($goreli !== null && $goreli !== 0) {
    $hedef_gun = TarihYardimcisi::gunAdi((int)date('N', strtotime(($goreli > 0 ? '+' : '') . $goreli . ' day')));
    $zaman_etiketi = [1 => 'Yarın', 2 => 'Öbür gün', -1 => 'Dün'][$goreli] ?? $hedef_gun;
} elseif ($istenen_gun !== null) {
    $hedef_gun = $istenen_gun;
    $zaman_etiketi = $hedef_gun;
}

$stmt = $this->db->prepare("SELECT * FROM bot_ders_programi WHERE bolum_id = :bid AND sinif = :sinif AND gun = :gun AND aktif = 1 ORDER BY baslangic_saati ASC");
$stmt->execute([':bid' => $bolum_id, ':sinif' => $sinif, ':gun' => $hedef_gun]); 
$dersler = $stmt->fetchAll();

if (empty($dersler)) {
    return $this->response("<b>{$bolum_adi} {$sinif}. Sınıf</b> için {$zaman_etiketi} ({$hedef_gun}) ders bulunamadı. 🎉", $program_butonlari);
}

$cevap = "📚 <b>{$bolum_adi} - {$sinif}. Sınıf</b><br>🗓️ <b>{$zaman_etiketi} Programı:</b><br><br>";
foreach ($dersler as $d) {
    $bas = substr($d['baslangic_saati'], 0, 5); 
    $bit = substr($d['bitis_saati'], 0, 5);
    $safe_adi = htmlspecialchars((string)$d['ders_adi'], ENT_QUOTES, 'UTF-8'); 
    $safe_hoca = htmlspecialchars((string)$d['ogretim_uyesi'], ENT_QUOTES, 'UTF-8'); 
    $safe_yer = htmlspecialchars((string)$d['derslik'], ENT_QUOTES, 'UTF-8');
    
    $cevap .= "🕒 <b>{$bas} - {$bit}</b><br>📖 <b>{$safe_adi}</b><br>👤 <small>{$safe_hoca}</small><br>📍 <small>{$safe_yer}</small><br>────────────────<br>";
}

return $this->response($cevap, $program_butonlari);
