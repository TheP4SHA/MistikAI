<?php
// MistikAI API Entry Point
// All chat requests go through this file.
//
// İstek  (POST, JSON): {"message": "kullanıcı metni", "payload": "flow_x|modul_x|set_program_x_y" (isteğe bağlı), "csrf_token": "..."}
//                      CSRF jetonu gövdede ya da X-CSRF-Token başlığında olabilir.
// Cevap  (JSON):       {"reply": "<temizlenmiş HTML>", "buttons": [{"text": "...", "payload": "..."} | {"text": "...", "url": "https://..."}]}
// Hata kodları: 403 CSRF, 405 yöntem, 413 uzun mesaj, 429 hız sınırı, 500/503 sunucu.

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Istanbul');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function jsonCevap(int $kod, array $govde, array $basliklar = []): void {
    http_response_code($kod);
    foreach ($basliklar as $ad => $deger) {
        header($ad . ': ' . $deger);
    }
    if (!isset($govde['buttons']) && isset($govde['reply'])) {
        $govde['buttons'] = [];
    }
    echo json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

set_exception_handler(function (Throwable $e) {
    // Hata ayrıntısı yalnız sunucu günlüğüne gider; kullanıcıya genel mesaj gösterilir.
    error_log('[MistikAI] api.php: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['reply' => '⚠️ Bir sorun oluştu. Lütfen biraz sonra tekrar dene.', 'buttons' => []], JSON_UNESCAPED_UNICODE);
});

// ======================================================================
// Database & Config
// ======================================================================
require_once __DIR__ . '/../src/Guvenlik.php';

$configPath = dirname(__DIR__) . '/config/db.php';
if (!is_file($configPath)) {
    error_log('[MistikAI] config/db.php bulunamadı; config/db.php.example dosyasını kopyalayın.');
    jsonCevap(503, ['reply' => '⚠️ Sistem henüz yapılandırılmamış.']);
}
require_once $configPath;

global $db, $GROQ_API_KEY, $BERT_API_URL, $BERT_API_KEY;

if (!(($db ?? null) instanceof PDO)) {
    jsonCevap(503, ['reply' => '⚠️ Veritabanına bağlanılamadı.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonCevap(405, ['reply' => 'Yalnız POST desteklenir.'], ['Allow' => 'POST']);
}

require_once __DIR__ . '/../src/HizSiniri.php';
require_once __DIR__ . '/../src/Logger.php';

Guvenlik::oturumBaslat();
$ipOzeti = Guvenlik::ipOzeti();

// ======================================================================
// Hız sınırı (dakikalık, IP özetine göre) — get_csrf dahil her istek sayılır
// ======================================================================
$dakikaSiniri = (int)($GLOBALS['DAKIKA_ISTEK_SINIRI'] ?? 20);
if ($dakikaSiniri > 0) {
    try {
        $hiz = (new HizSiniri($db))->say($ipOzeti);
        if ($hiz['sayi'] > $dakikaSiniri) {
            if ($hiz['sayi'] === $dakikaSiniri + 1) {
                // Pencere başına bir kez logla (saldırı anında tabloyu şişirmemek için)
                (new Logger($db, []))->log($ipOzeti, '[hız sınırı aşıldı]', null, 0, null, false, 'sinir', null);
            }
            jsonCevap(429, [
                'reply' => "⏳ Biraz yavaşlayalım: dakikada en fazla {$dakikaSiniri} mesaj gönderebilirsin. {$hiz['kalan_sn']} saniye sonra tekrar dener misin?",
            ], ['Retry-After' => (string)$hiz['kalan_sn']]);
        }
    } catch (PDOException $e) {
        // Sınır tablosu yoksa/bozuksa sohbeti durdurma; günlüğe yaz.
        error_log('[MistikAI] Hız sınırı uygulanamadı: ' . $e->getMessage());
    }
}

// ======================================================================
// Request Handling
// ======================================================================
$hamGovde = (string)file_get_contents('php://input', false, null, 0, 32768);
$input = json_decode($hamGovde, true);
if (!is_array($input)) {
    $input = [];
}

// CSRF jetonu: sayfa (index.php) jetonu zaten gömüyor; bu uç nokta oturum yenilemek için.
// Başka siteden gelen tarayıcı isteklerine jeton verilmez.
if (($input['action'] ?? null) === 'get_csrf') {
    $kaynak = $_SERVER['HTTP_ORIGIN'] ?? null;
    $siteDisi = ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site'
        || ($kaynak !== null && strcasecmp((string)parse_url($kaynak, PHP_URL_HOST), (string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) !== 0);
    if ($siteDisi) {
        jsonCevap(403, ['reply' => '⚠️ İzin verilmeyen kaynak.']);
    }
    jsonCevap(200, ['csrf_token' => Guvenlik::csrfToken()]);
}

$jeton = isset($input['csrf_token']) && is_string($input['csrf_token']) ? $input['csrf_token'] : Guvenlik::istekCsrf();
if (!Guvenlik::csrfDogrula($jeton)) {
    jsonCevap(403, ['reply' => '⚠️ Oturumun yenilenmiş olabilir. Sayfayı yenileyip tekrar dener misin?', 'hata' => 'csrf']);
}

// ======================================================================
// Girdi temizliği
// ======================================================================
$mesaj = isset($input['message']) && is_scalar($input['message']) ? (string)$input['message'] : '';
$payload = isset($input['payload']) && is_scalar($input['payload']) ? (string)$input['payload'] : null;

// Geçersiz UTF-8 ve kontrol karakterleri atılır, boşluklar sadeleşir.
$mesaj = mb_convert_encoding($mesaj, 'UTF-8', 'UTF-8');
$mesaj = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $mesaj) ?? '';
$mesaj = trim(preg_replace('/[ \t]+/u', ' ', $mesaj) ?? '');

$maxUzunluk = max(1, (int)($GLOBALS['MESAJ_MAX_UZUNLUK'] ?? 500));
if (mb_strlen($mesaj) > $maxUzunluk) {
    (new Logger($db, []))->log($ipOzeti, mb_substr($mesaj, 0, 200) . '… [çok uzun: ' . mb_strlen($mesaj) . ' karakter]', null, 0, null, false, 'sinir', null);
    jsonCevap(413, ['reply' => "✂️ Mesajın biraz uzun oldu. En fazla {$maxUzunluk} karakter yazabilirsin; kısaltıp tekrar dener misin?"]);
}
if ($payload !== null) {
    $payload = trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', '', mb_convert_encoding($payload, 'UTF-8', 'UTF-8')) ?? '', 0, 200));
}

// ======================================================================
// Process Message
// ======================================================================
require_once __DIR__ . '/../src/MistikEngine.php';

$engine = new MistikEngine($db, [
    'groq_api_key'    => $GROQ_API_KEY ?? '',
    'bert_api_url'    => $BERT_API_URL ?? '',
    'bert_api_key'    => $GLOBALS['BERT_API_KEY'] ?? '',
    'llm_model'       => $GLOBALS['LLM_MODEL'] ?? 'llama-3.3-70b-versatile',
    'llm_yedek_model' => $GLOBALS['LLM_YEDEK_MODEL'] ?? 'llama-3.1-8b-instant',
    'llm_max_tokens'  => (int)($GLOBALS['LLM_MAX_TOKENS'] ?? 400),
    'llm_api_url'     => $GLOBALS['LLM_API_URL'] ?? 'https://api.groq.com/openai/v1/chat/completions',
    'okul_bilgisi'    => $GLOBALS['OKUL_BILGISI'] ?? 'Osmaniye Meslek Yüksekokulu (OMYO)',
    'log_saklama_gun' => (int)($GLOBALS['LOG_SAKLAMA_GUN'] ?? 180),
    'plugin_dir'      => __DIR__ . '/../modules',
    'session_id'      => $ipOzeti,
]);

$cevap = $engine->process($mesaj, $payload);
jsonCevap(200, $cevap->disaAktar());
