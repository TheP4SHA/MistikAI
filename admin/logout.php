<?php
// admin/logout.php - Güvenli çıkış
// Yalnız CSRF jetonlu POST ile çalışır (başka siteden zorla çıkış yaptırılamasın); GET isteği panele döner.
require_once __DIR__ . '/_baslat.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Panel::yonlendir('dashboard');
}

// Oturum verisini boşalt, çerezi oturumla aynı parametrelerle sil, sunucudaki oturumu yok et.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

Panel::yonlendir('login');
