<?php
// /data/MistikAI/admin/register.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// YOL DÜZELTİLDİ: Sitedeki tüm linklerin .htaccess üzerinden doğru çalışması için BASE URL
$base_url = '/OmyoWeb/MistikAI/admin'; 

// Zaten giriş yapmışsa direkt ana panele at
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

// 1. GÜVENLİK: Register sistemi veritabanından açık mı kontrol et!
try {
    $stmt = $db->query("SELECT ayar_degeri FROM bot_sistem_ayarlari WHERE ayar_adi = 'admin_kayit_acik'");
    $kayit_acik = $stmt->fetchColumn() === '1';
} catch (\Throwable $th) {
    $kayit_acik = false; // Tablo yoksa kayıt kapalı varsay
}

if (!$kayit_acik) {
    die("<h2 style='color:#ef4444; text-align:center; font-family:sans-serif; margin-top:50px;'>⚠️ HATA: Yönetici kaydı şu an sistem yöneticisi tarafından geçici olarak durdurulmuştur.</h2>");
}

$hata = '';
$basari = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad_soyad = trim($_POST['ad_soyad']);
    $kullanici = trim($_POST['kullanici_adi']);
    $sifre = $_POST['sifre'];
    $sifre_tekrar = $_POST['sifre_tekrar'];

    if (empty($ad_soyad) || empty($kullanici) || empty($sifre)) {
        $hata = 'Lütfen tüm alanları doldurun.';
    } elseif ($sifre !== $sifre_tekrar) {
        $hata = 'Şifreler birbiriyle eşleşmiyor!';
    } else {
        // Kullanıcı adı daha önce alınmış mı?
        $check = $db->prepare("SELECT id FROM bot_admin_users WHERE kullanici_adi = :k");
        $check->execute([':k' => $kullanici]);
        
        if ($check->rowCount() > 0) {
            $hata = 'Bu kullanıcı adı zaten alınmış!';
        } else {
            // Şifreyi asla düz metin kaydetmeyiz. Güçlü Bcrypt algoritması kullanıyoruz.
            $kriptolu_sifre = password_hash($sifre, PASSWORD_DEFAULT);
            
            $insert = $db->prepare("INSERT INTO bot_admin_users (kullanici_adi, sifre, ad_soyad) VALUES (:k, :s, :a)");
            if ($insert->execute([':k' => $kullanici, ':s' => $kriptolu_sifre, ':a' => $ad_soyad])) {
                $basari = 'Kayıt başarılı! Şimdi giriş yapabilirsiniz.';
            } else {
                $hata = 'Kayıt sırasında bir veritabanı hatası oluştu.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MistikAI - Yeni Admin Kaydı</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg-main: #0f172a; --bg-panel: #1e293b; --bg-input: #334155; --text-main: #f8fafc; --text-muted: #94a3b8; --accent-color: #8b5cf6; --accent-hover: #7c3aed; --danger-color: #ef4444; --success-color: #10b981; --border-color: #334155; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-main); color: var(--text-main); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem 0; }
        .auth-card { background: var(--bg-panel); padding: 2.5rem; border-radius: 1rem; border: 1px solid var(--border-color); width: 100%; max-width: 450px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        .auth-header { text-align: center; margin-bottom: 2rem; }
        .auth-header i { font-size: 2.5rem; color: var(--accent-color); margin-bottom: 0.5rem; }
        .auth-header h1 { font-size: 1.3rem; font-weight: 700; }
        .form-group { margin-bottom: 1.2rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-muted); }
        .form-group input { width: 100%; padding: 0.8rem; background: var(--bg-input); border: 1px solid var(--border-color); color: white; border-radius: 0.5rem; font-size: 1rem; outline: none; transition: 0.3s; }
        .form-group input:focus { border-color: var(--accent-color); }
        .btn-submit { width: 100%; background: var(--accent-color); color: white; border: none; padding: 0.8rem; border-radius: 0.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 1rem; }
        .btn-submit:hover { background: var(--accent-hover); }
        .error-msg { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); padding: 0.8rem; border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem; text-align: center; border: 1px solid rgba(239,68,68,0.3); }
        .success-msg { background: rgba(16, 185, 129, 0.1); color: var(--success-color); padding: 0.8rem; border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem; text-align: center; border: 1px solid rgba(16,185,129,0.3); }
        .auth-footer { margin-top: 1.5rem; text-align: center; font-size: 0.9rem; color: var(--text-muted); }
        .auth-footer a { color: var(--accent-color); text-decoration: none; font-weight: 500; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <i class="fas fa-user-plus"></i>
            <h1>Yeni Yönetici Kaydı</h1>
        </div>
        
        <?php if($hata): ?><div class="error-msg"><?php echo $hata; ?></div><?php endif; ?>
        <?php if($basari): ?><div class="success-msg"><?php echo $basari; ?></div><?php endif; ?>
        
        <?php if(!$basari): ?>
        <form method="POST" action="<?php echo $base_url; ?>/register">
            <div class="form-group">
                <label>Ad Soyad</label>
                <input type="text" name="ad_soyad" required>
            </div>
            <div class="form-group">
                <label>Kullanıcı Adı (Benzersiz)</label>
                <input type="text" name="kullanici_adi" required>
            </div>
            <div class="form-group">
                <label>Şifre</label>
                <input type="password" name="sifre" required>
            </div>
            <div class="form-group">
                <label>Şifre (Tekrar)</label>
                <input type="password" name="sifre_tekrar" required>
            </div>
            <button type="submit" class="btn-submit">Kayıt Ol</button>
        </form>
        <?php endif; ?>
        
        <div class="auth-footer">
            Zaten hesabın var mı? <a href="<?php echo $base_url; ?>/login">Giriş Yap</a>
        </div>
    </div>
</body>
</html>
