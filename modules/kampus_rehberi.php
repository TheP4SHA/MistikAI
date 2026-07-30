<?php
// Kampüs Rehberi Modülü - Eklenti Dosyası
// Mevcut Değişkenler: $this (Motor Nesnesi), $clean_input, $raw_input, $this->db (Veritabanı)

$response = "📍 <b>OMYO Kampüs Rehberi:</b><br><br>";

// 1. ÖĞRENCİ İŞLERİ KONTROLÜ
if (strpos($clean_input, 'öğrenci işleri') !== false || strpos($clean_input, 'ogrenci isleri') !== false || strpos($clean_input, 'öğrenci isleri') !== false) {
    return $this->response($response . "🏛️ <b>Öğrenci İşleri</b> binamızın <b>2. katında</b> yer almaktadır.");
}

// 2. MÜDÜRİYET KONTROLÜ
if (strpos($clean_input, 'müdür') !== false || strpos($clean_input, 'mudur') !== false || strpos($clean_input, 'müdüriyet') !== false) {
    return $this->response($response . "🏢 <b>Müdürlük ve Müdüriyet</b> odaları binamızın <b>3. katında</b> bulunmaktadır.");
}

// 3. SPESİFİK LABORATUVAR KONTROLÜ (Örn: "Lab 2 nerede?" veya "2 nolu lab")
if (preg_match('/(?:lab|laboratuvar|laboratuvarı).+?(\d+)/i', $clean_input, $matches) || preg_match('/(\d+).+?(?:lab|laboratuvar)/i', $clean_input, $matches)) {
    $lab_no = (int)$matches[1];
    if ($lab_no >= 1 && $lab_no <= 6) {
        return $this->response($response . "💻 <b>Bilgisayar Laboratuvarı {$lab_no}</b> binamızın <b>3. katında</b> yer almaktadır.");
    }
}

// 4. GENEL LABORATUVAR KONTROLÜ ("Laboratuvarlar nerede?")
if (strpos($clean_input, 'lab') !== false || strpos($clean_input, 'laboratuvar') !== false) {
    return $this->response($response . "💻 Tüm <b>Bilgisayar Laboratuvarları (01-06)</b> binamızın <b>3. katında</b> yer almaktadır.");
}

// 5. SINIF / DERSLİK KONTROLÜ (Örn: "15 nolu sınıf", "derslik 12", sadece "15")
if (preg_match('/(?:derslik|sınıf|sinif|no|numaralı|nolu).+?(\d+)/i', $clean_input, $matches) || preg_match('/(\d+).+?(?:nolu|numaralı|derslik|sınıf|sinif)/i', $clean_input, $matches) || preg_match('/\b(\d{1,2})\b/', $clean_input, $matches)) {
    $sinif_no = (int)$matches[1];
    
    // OMYO Kat Planı Algoritması
    if ($sinif_no >= 1 && $sinif_no <= 8) {
        return $this->response($response . "🏫 <b>{$sinif_no} Nolu Derslik</b> binamızın <b>1. katında</b> yer almaktadır.");
    } elseif ($sinif_no >= 9 && $sinif_no <= 16) {
        return $this->response($response . "🏫 <b>{$sinif_no} Nolu Derslik</b> binamızın <b>2. katında</b> yer almaktadır.");
    } elseif ($sinif_no >= 17 && $sinif_no <= 20) {
        return $this->response($response . "🏫 <b>{$sinif_no} Nolu Derslik</b> binamızın <b>3. katında</b> yer almaktadır.");
    } else {
        return $this->response($response . "❓ <b>{$sinif_no} numaralı</b> bir derslik/laboratuvar okulumuzun ana planında bulunamamıştır.");
    }
}

// 6. EŞLEŞME BULUNAMAZSA YÖNLENDİRME
return $this->response($response . "Tam olarak nereyi aradığınızı anlayamadım. Bana aradığınız yerin adını veya derslik numarasını söyleyebilirsiniz.<br><small>Örn: <i>'12 nolu derslik nerede?'</i> veya <i>'Öğrenci işleri hangi katta?'</i></small>");
?>