<?php
// MistikEngine - DNS Bükücü ve Fotoğraflı Rehber Modülü

$aranan_kisi = trim(str_replace(['[', ']'], '', $clean_input)); 
$isim_yok = empty($aranan_kisi) || in_array(mb_strtolower($aranan_kisi, 'UTF-8'), ['rehber', 'telefon rehberi', 'numara', 'ara']);

if ($isim_yok && !isset($_SESSION['context']['telefon_rehberi_bekliyor'])) {
    $_SESSION['context']['telefon_rehberi_bekliyor'] = true;
    return $this->response("🔍 Üniversite rehberinde kimi arıyorsun? Lütfen aradığın kişinin **Adını veya Soyadını** yaz.");
}

if (isset($_SESSION['context']['telefon_rehberi_bekliyor']) && $_SESSION['context']['telefon_rehberi_bekliyor'] === true) {
    $aranan_kisi = trim($raw_input);
    unset($_SESSION['context']['telefon_rehberi_bekliyor']);
}

// 🚀 ARAMA BAŞLIYOR 🚀
session_write_close(); // Kilitlenmeyi önler

$host = "rehber.osmaniye.edu.tr";
$api_url = "https://$host/rehberdata.php?aranan=" . urlencode($aranan_kisi);

// 🛡️ NİHAİ ÇÖZÜM: DNS KÖRDÜĞÜMÜNÜ AŞMAK 🛡️
// PHP'nin cURL'ü adresi bulurken donuyorsa, adresi biz çözüp eline veriyoruz!
$ip_adresi = gethostbyname($host);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_TIMEOUT, 8); // En fazla 8 saniye bekle

// cURL'a diyoruz ki: "DNS araması yapma, rehber.osmaniye.edu.tr demek $ip_adresi demektir, direkt oraya git!"
curl_setopt($ch, CURLOPT_RESOLVE, ["$host:443:$ip_adresi"]);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    return $this->response("⚠️ Rehber sunucusuna bağlanılamadı (IP: $ip_adresi). Hata: $err");
}

if ($http_code == 200 && $response) {
    $data = json_decode($response, true);
    
    if (empty($data)) {
        return $this->response("❌ Rehberde <b>'$aranan_kisi'</b> adına ait bir kayıt bulamadım.");
    }

    $cevap = "📞 **Rehber Sonuçları ('$aranan_kisi'):**<br><br>";
    $sayac = 0;

    foreach ($data as $kisi) {
        if ($sayac >= 50) break; 
        
        $isim = $kisi['adsoyad'] ?? "Bilinmiyor";
        $dahili = $kisi['telefon'] ?? "";
        $birim = $kisi['birim'] ?? "";
        $unvan = $kisi['unvankisa'] ?? "";
        $email = $kisi['email'] ?? "";
        $resim = $kisi['resim'] ?? ""; 

        $cevap .= "<div style='display:flex; align-items:center; margin-bottom:12px;'>";
        
        if (!empty($resim)) {
            $cevap .= "<img src='$resim' style='width:60px; height:60px; border-radius:50%; margin-right:15px; object-fit:cover; border: 2px solid var(--primary-color);' alt='Profil'>";
        }
        
        $cevap .= "<div style='line-height:1.4;'>";
        $cevap .= "👤 <b>" . trim("$unvan $isim") . "</b><br>";
        
        if (!empty($birim)) { 
            $birim = mb_convert_case($birim, MB_CASE_TITLE, "UTF-8");
            $cevap .= "🏢 $birim<br>"; 
        }
        
        if (!empty($dahili) && $dahili !== "0") {
            $cevap .= "☎️ Dahili: <b>$dahili</b><br>";
        }
        
        if (!empty($email)) {
            $cevap .= "📧 <a href='mailto:$email' style='color:#0ea5e9; text-decoration:none;'>$email</a>";
        }
        
        $cevap .= "</div></div><hr style='border:1px solid var(--border-color); margin:12px 0;'>";
        
        $sayac++;
    }
    
    if (count($data) > 50) {
        $cevap .= "<small style='color:var(--text-muted);'><i>(Çok fazla sonuç bulundu, ilk 50 tanesi listelendi.)</i></small>";
    }

    return $this->response($cevap);

} else {
    return $this->response("⚠️ Üniversite rehber sistemi şu an yanıt vermiyor (HTTP: $http_code).");
}
?>