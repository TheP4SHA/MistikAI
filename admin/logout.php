<?php
// /data/MistikAI/admin/logout.php

// 1. Oturumu başlat (açık olan oturumu yakalamak için)
session_start();

// 2. Tüm oturum değişkenlerini boşalt
$_SESSION = array();

// 3. Güvenlik: Eğer oturum çerezi kullanılıyorsa, onu da tarayıcıdan sil
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Oturumu tamamen sunucudan yok et
session_destroy();

// 5. YOL DÜZELTİLDİ: Temiz URL (.htaccess) yapısına uygun olarak login'e yönlendir
header("Location: /OmyoWeb/MistikAI/admin/login");
exit;
?>