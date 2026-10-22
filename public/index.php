<?php
// MistikAI sohbet arayüzü
// Betik ve stiller public/assets/ altında; satır içi betik yok, CSP 'self' dışındaki betikleri engeller.
// CSRF jetonu sayfaya gömülür (api.php'nin get_csrf ucu yalnız oturum yenileme içindir).
// Okul adı config/db.php'deki $OKUL_BILGISI'nden gelir; sayfa yalnız ayarları okur, veritabanına bağlanmaz.

require_once __DIR__ . '/../src/Guvenlik.php';
require_once __DIR__ . '/../src/LLMClient.php';

define('MISTIK_YALNIZ_AYAR', true);
$yapilandirma = dirname(__DIR__) . '/config/db.php';
if (is_file($yapilandirma)) {
    require_once $yapilandirma;
}
$okulAdi = Guvenlik::e(LLMClient::okulAdi($OKUL_BILGISI ?? null));

Guvenlik::oturumBaslat();
$csrf = Guvenlik::csrfToken();

$csp = implode('; ', [
    "default-src 'self'",
    "script-src 'self'",
    // Modül cevaplarında style="" öznitelikleri var; temizHtml url()/expression içeren stilleri zaten atıyor.
    "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com",
    "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com",
    "img-src 'self' https: data:",
    "connect-src 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
]);
header('Content-Security-Policy: ' . $csp);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-cache, private');

/** Varlık dosyası adresi + önbellek kırıcı sürüm. */
function varlik(string $yol): string {
    $tam = __DIR__ . '/' . $yol;
    return Guvenlik::e($yol . (is_file($tam) ? '?v=' . filemtime($tam) : ''));
}

// Avatar: webp varsa <picture> ile önce o, yoksa yalnız gif (eksik webp'ye istek atılmaz).
$webpVar = is_file(__DIR__ . '/assets/mistik.webp');
$avatar = '<picture>'
    . ($webpVar ? '<source srcset="' . varlik('assets/mistik.webp') . '" type="image/webp">' : '')
    . '<img src="' . varlik('assets/mistik.gif') . '" alt="" width="44" height="44" decoding="async"></picture>';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5, user-scalable=yes">
    <title>MistikAI · <?= $okulAdi ?></title>
    <meta name="description" content="MistikAI - <?= $okulAdi ?> dijital kampüs asistanı. Ders programı, yemekhane menüsü ve akademik takvim bilgilerine anında erişin.">
    <meta name="csrf-token" content="<?= Guvenlik::e($csrf) ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= varlik('assets/mistik.css') ?>">
    <script src="<?= varlik('assets/mistik.js') ?>" defer></script>
</head>
<body>
    <!-- Bot avatarı tek yerde tanımlı; mesajlara JS ile kopyalanır (tarayıcı dosyayı bir kez indirir). -->
    <template id="botAvatarSablon"><?= $avatar ?></template>

    <main class="main-chat">
        <header class="top-bar">
            <div class="bot-info">
                <div class="bot-avatar" data-avatar></div>
                <div class="bot-title-area">
                    <h2>MistikAI</h2>
                    <div class="status-indicator">
                        <span class="status-dot"></span>
                        <span class="status-text">Sistem Aktif</span>
                    </div>
                </div>
            </div>
            <button type="button" class="theme-btn" id="themeToggle" title="Tema değiştir" aria-label="Tema değiştir"><i class="fas fa-moon" aria-hidden="true"></i></button>
        </header>
        <div class="chat-container" id="chatBox" role="log" aria-live="polite">
            <div class="message-wrapper bot">
                <div class="msg-avatar" data-avatar></div>
                <div class="message-bubble">
                    Merhaba! Ben <strong>MistikAI</strong>, <?= $okulAdi ?> için dijital kampüs asistanıyım.<br><br>
                    Ders programı, yemekhane menüsü veya kampüs ile ilgili diğer konularda size yardımcı olabilirim. Ne sormak istersiniz?
                </div>
            </div>
            <div class="chat-buttons-container" id="welcomeBtnContainer">
                <button type="button" class="quick-action-btn" id="welcomeBtn" data-text="Neler yapabilirsin?" data-payload="flow_ana_menu">
                    <i class="fas fa-magic" aria-hidden="true"></i> Neler yapabilirsin?
                </button>
            </div>
            <div class="message-wrapper bot gizli" id="typingIndicator" aria-hidden="true">
                <div class="msg-avatar" data-avatar></div>
                <div class="message-bubble yaziyor">
                    <div class="typing-indicator aktif"><span></span><span></span><span></span></div>
                </div>
            </div>
        </div>
        <div class="input-area">
            <form class="input-wrapper" id="chatForm">
                <label for="userInput" class="sr-only">Mesajınız</label>
                <textarea id="userInput" rows="1" placeholder="MistikAI'ye bir şeyler sor... (Örn: Bugün dersim var mı?)"></textarea>
                <button type="submit" class="send-btn" id="sendBtn" aria-label="Gönder"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
            </form>
            <div class="footer-info">
                <span>MistikAI beta aşamasındadır. Resmi işlemler için Öğrenci İşlerini kullanın.</span>
                <span class="imza">Created by Pasha</span>
            </div>
        </div>
    </main>
</body>
</html>
