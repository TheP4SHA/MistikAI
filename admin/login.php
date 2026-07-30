<?php
// /data/MistikAI/admin/login.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// YOL DÜZELTİLDİ: Sitedeki tüm linklerin .htaccess üzerinden doğru çalışması için BASE URL
$base_url = '/OmyoWeb/MistikAI/admin'; 

// Zaten giriş yapmışsa direkt ana panele at (YOL DÜZELTİLDİ)
if (isset($_SESSION['admin_id'])) {
    header("Location: " . $base_url . "/dashboard");
    exit;
}

// 1. VERİTABANI BAĞLANTISI (Mutlak Yol ve FOG Canlı Yedeği)
$doc_root = $_SERVER['DOCUMENT_ROOT']; // /var/www/html/
$base_path = $doc_root . '/OmyoWeb/MistikAI/data';
$db_path = $base_path . '/config/db.php';

if (file_exists($db_path)) {
    require_once $db_path;
} else {
    try {
        require_once __DIR__ . '/../config/db.php';
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Kritik DB Hatası: Veritabanına bağlanılamadı.");
    }
}
global $db;

$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kullanici = trim($_POST['kullanici_adi']);
    $sifre = $_POST['sifre'];

    if (!empty($kullanici) && !empty($sifre)) {
        $stmt = $db->prepare("SELECT id, sifre, ad_soyad FROM bot_admin_users WHERE kullanici_adi = :k");
        $stmt->execute([':k' => $kullanici]);
        $user = $stmt->fetch();

        // password_verify() ile şifreli (Bcrypt) DB şifresini kontrol eder
        if ($user && password_verify($sifre, $user['sifre'])) {
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_ad'] = $user['ad_soyad'];
            // YOL DÜZELTİLDİ: Temiz URL ile Dashboard'a yönlendir
            header("Location: " . $base_url . "/dashboard");
            exit;
        } else {
            $hata = 'Kullanıcı adı veya şifre hatalı!';
        }
    } else {
        $hata = 'Lütfen tüm alanları doldurun.';
    }
}

// Kayıt sistemi açık mı diye kontrol edelim (Butonu göstermek için)
try {
    $stmt = $db->query("SELECT ayar_degeri FROM bot_sistem_ayarlari WHERE ayar_adi = 'admin_kayit_acik'");
    $kayit_acik = $stmt->fetchColumn() === '1';
} catch (\Throwable $th) {
    $kayit_acik = false; // Tablo yoksa kayıt kapalı varsay
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MistikAI - Admin Girişi</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg-main: #0f172a; --bg-panel: #1e293b; --bg-input: #334155; --text-main: #f8fafc; --text-muted: #94a3b8; --accent-color: #8b5cf6; --accent-hover: #7c3aed; --danger-color: #ef4444; --border-color: #334155; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-main); color: var(--text-main); display: flex; align-items: center; justify-content: center; height: 100vh; }
        .auth-card { background: var(--bg-panel); padding: 2.5rem; border-radius: 1rem; border: 1px solid var(--border-color); width: 100%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        .auth-header { text-align: center; margin-bottom: 2rem; }
        .auth-header i { font-size: 3rem; color: var(--accent-color); margin-bottom: 1rem; }
        .auth-header h1 { font-size: 1.5rem; font-weight: 700; }
        .form-group { margin-bottom: 1.2rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-muted); }
        .form-group input { width: 100%; padding: 0.8rem; background: var(--bg-input); border: 1px solid var(--border-color); color: white; border-radius: 0.5rem; font-size: 1rem; outline: none; transition: 0.3s; }
        .form-group input:focus { border-color: var(--accent-color); }
        .btn-submit { width: 100%; background: var(--accent-color); color: white; border: none; padding: 0.8rem; border-radius: 0.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 1rem; }
        .btn-submit:hover { background: var(--accent-hover); }
        .error-msg { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); padding: 0.8rem; border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem; text-align: center; border: 1px solid rgba(239,68,68,0.3); }
        .auth-footer { margin-top: 1.5rem; text-align: center; font-size: 0.9rem; color: var(--text-muted); }
        .auth-footer a { color: var(--accent-color); text-decoration: none; font-weight: 500; }
        .auth-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <i class="fas fa-brain"></i>
            <h1>MistikAI Yönetim</h1>
        </div>
        
        <?php if($hata): ?><div class="error-msg"><?php echo $hata; ?></div><?php endif; ?>
        
        <form method="POST" action="<?php echo $base_url; ?>/login">
            <div class="form-group">
                <label>Kullanıcı Adı</label>
                <input type="text" name="kullanici_adi" required autofocus>
            </div>
            <div class="form-group">
                <label>Şifre</label>
                <input type="password" name="sifre" required>
            </div>
            <button type="submit" class="btn-submit">Sisteme Giriş Yap</button>
        </form>
        
        <?php if($kayit_acik): ?>
        <div class="auth-footer">
            Hesabın yok mu? <a href="<?php echo $base_url; ?>/register">Hemen Kayıt Ol</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
