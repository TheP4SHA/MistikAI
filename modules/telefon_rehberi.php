<?php
// Telefon Rehberi Modülü - Fotoğraflı üniversite rehberi araması
// Mevcut Değişkenler: $this (Motor), $clean_input, $raw_input, $bekleyen (bekleme devamıysa dizi, değilse null)
//
// İki adımlı akış: "telefon rehberi" → "Kimi arıyorsun?" ($this->bekle()) → kullanıcı adı yazar → arama.
// Tek adımda da çalışır: "Ahmet Yılmaz'ın numarası".
// API'den gelen her alan kaçışlanır; fotoğraf yalnız https, e-posta bağlantısı yalnız geçerli adresle.

$rehber_url = (string)($GLOBALS['REHBER_API_URL'] ?? 'https://rehber.osmaniye.edu.tr/rehberdata.php');
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// 1. Aranan ismi çıkar: tetik/dolgu kelimeleri atılır.
//    "telefon rehberinden Ahmet Yılmaz'ın numarası" → "Ahmet Yılmaz"; bekleme devamında "Ahmet hoca" → "Ahmet".
$dolgu = array_flip(explode(' ',
    'telefon telefonu telefonunu tel rehber rehberi rehberde rehberden rehberinden numara numarasi numarasini '
    . 'dahili dahilisi e eposta posta mail maili iletisim bilgisi bilgileri ara arar ariyorum aramak bul bulur bulabilir '
    . 'misin musun mi mu kim kimdir nedir ne hoca hocanin hocam ogretmen ogretim gorevlisi uyesi dr doc prof ogr gor '
    . 'lazim bana ver verir verebilir baska birini biri birisi birisini kisi kisiyi kisinin nin in un'));
$kelimeler = [];
foreach (preg_split('/\s+/u', preg_replace("/['’`]\p{L}*/u", '', $raw_input) ?? '') as $k) {
    $parcalar = array_filter(explode(' ', Metin::temiz($k)), 'strlen');
    $hepsi_dolgu = true;
    foreach ($parcalar as $p) {
        if (!isset($dolgu[$p])) {
            $hepsi_dolgu = false;
            break;
        }
    }
    if (!$hepsi_dolgu) {
        $kelimeler[] = $k;
    }
}
$aranan_kisi = implode(' ', $kelimeler);
$aranan_kisi = trim(preg_replace('/[^\p{L}\s.\-]/u', '', $aranan_kisi) ?? '');
$aranan_kisi = mb_substr(preg_replace('/\s+/u', ' ', $aranan_kisi) ?? '', 0, 60);

if (mb_strlen($aranan_kisi) < 2) {
    $this->bekle(['adim' => 'isim']);
    $soru = $bekleyen !== null
        ? "Bir isim anlayamadım 🙂 Aradığın kişinin <b>adını veya soyadını</b> yazar mısın? (Vazgeçmek için <i>iptal</i> yazabilirsin.)"
        : "🔍 Üniversite rehberinde kimi arıyorsun? Lütfen aradığın kişinin <b>adını veya soyadını</b> yaz.";
    return $this->response($soru);
}

// 2. Arama
$parca = parse_url($rehber_url);
$host = (string)($parca['host'] ?? '');
$api_url = $rehber_url . (strpos($rehber_url, '?') === false ? '?' : '&') . 'aranan=' . rawurlencode($aranan_kisi);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $api_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_SSL_VERIFYPEER => true,   // Sertifika doğrulaması açık
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    CURLOPT_NOSIGNAL       => true,
]);
// Sunucunun DNS çözümleyicisi bu alan adında takılabiliyor; adresi önceden çözüp cURL'e veriyoruz.
// CURLOPT_RESOLVE yalnız IP eşlemesini değiştirir; sertifika yine $host adına doğrulanır.
if (($parca['scheme'] ?? '') === 'https' && $host !== '' && !filter_var($host, FILTER_VALIDATE_IP)) {
    $ip_adresi = gethostbyname($host);
    if ($ip_adresi !== $host && filter_var($ip_adresi, FILTER_VALIDATE_IP)) {
        curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:443:{$ip_adresi}"]);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
}

$response = curl_exec($ch);
$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

$tekrar_buton = [['text' => '🔍 Başka birini ara', 'payload' => 'modul_telefon_rehberi']];

if ($err || $http_code !== 200 || !is_string($response)) {
    error_log('[MistikAI] Rehber sorgusu başarısız: ' . ($err ?: "HTTP {$http_code}"));
    return $this->response("⚠️ Üniversite rehber sistemine şu an ulaşılamıyor. Lütfen biraz sonra tekrar dene.", $tekrar_buton);
}

$data = json_decode($response, true);
$guvenli_aranan = $e($aranan_kisi);

if (!is_array($data) || empty($data)) {
    return $this->response("❌ Rehberde <b>'{$guvenli_aranan}'</b> adına ait bir kayıt bulamadım.", $tekrar_buton);
}

$cevap = "📞 <b>Rehber Sonuçları ('{$guvenli_aranan}'):</b><br><br>";
$sayac = 0;

foreach ($data as $kisi) {
    if (!is_array($kisi)) {
        continue;
    }
    if ($sayac >= 50) {
        break;
    }

    $isim  = trim((string)($kisi['adsoyad'] ?? '')) ?: 'Bilinmiyor';
    $dahili = trim((string)($kisi['telefon'] ?? ''));
    $birim = trim((string)($kisi['birim'] ?? ''));
    $unvan = trim((string)($kisi['unvankisa'] ?? ''));
    $email = trim((string)($kisi['email'] ?? ''));
    $resim = trim((string)($kisi['resim'] ?? ''));

    // Göreli fotoğraf yolu rehber sunucusuna göre tamamlanır; yalnız https kabul edilir.
    if ($resim !== '' && strpos($resim, '/') === 0 && strpos($resim, '//') !== 0 && $host !== '') {
        $resim = 'https://' . $host . $resim;
    }
    $resim_gecerli = $resim !== '' && filter_var($resim, FILTER_VALIDATE_URL) && strtolower((string)parse_url($resim, PHP_URL_SCHEME)) === 'https';

    $cevap .= "<div style='display:flex; align-items:center; margin-bottom:12px;'>";
    if ($resim_gecerli) {
        $cevap .= "<img src='" . $e($resim) . "' style='width:60px; height:60px; border-radius:50%; margin-right:15px; object-fit:cover;' alt='Profil'>";
    }
    $cevap .= "<div style='line-height:1.4;'>";
    $cevap .= "👤 <b>" . $e(trim("{$unvan} {$isim}")) . "</b><br>";

    if ($birim !== '') {
        $cevap .= "🏢 " . $e(Metin::baslik($birim)) . "<br>";
    }
    if ($dahili !== '' && $dahili !== '0') {
        $cevap .= "☎️ Dahili: <b>" . $e($dahili) . "</b><br>";
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $cevap .= "📧 <a href='mailto:" . $e($email) . "' style='color:#0ea5e9; text-decoration:none;'>" . $e($email) . "</a>";
    } elseif ($email !== '') {
        $cevap .= "📧 " . $e($email);
    }
    $cevap .= "</div></div><hr style='border:1px solid var(--border-color); margin:12px 0;'>";

    $sayac++;
}

if (count($data) > 50) {
    $cevap .= "<small style='color:var(--text-muted);'><i>(Çok fazla sonuç bulundu, ilk 50 tanesi listelendi.)</i></small>";
}

return $this->response($cevap, $tekrar_buton);
