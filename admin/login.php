<?php
// admin/login.php - Yönetici girişi (kaba kuvvet freni: bot_giris_denemeleri)
define('PANEL_GIRISSIZ', true);
require_once __DIR__ . '/_baslat.php';

// Aynı IP + kullanıcı adı için 15 dakikada 5 hatalı deneme; aynı IP'den toplamda 20 hatalı deneme.
const GIRIS_PENCERE_DK = 15;
const GIRIS_KULLANICI_SINIRI = 5;
const GIRIS_IP_SINIRI = 20;

// Zaten giriş yapmışsa doğrudan panele.
if (!empty($_SESSION['admin_id'])) {
    Panel::yonlendir('dashboard');
}

// Hiç yönetici yoksa ilk kurulum sayfasına.
if ((int)$db->query("SELECT COUNT(*) FROM bot_admin_users")->fetchColumn() === 0) {
    Panel::yonlendir('kurulum');
}

/** Pencere içindeki hatalı deneme sayıları: [bu kullanıcı adı, bu IP toplamı]. */
function girisDenemeSayilari(PDO $db, string $ipOzeti, string $kullanici): array {
    $stmt = $db->prepare("SELECT COUNT(*) AS toplam, COALESCE(SUM(kullanici_adi = ?), 0) AS kullanici
                          FROM bot_giris_denemeleri
                          WHERE ip_ozeti = ? AND deneme_tarihi > NOW() - INTERVAL " . GIRIS_PENCERE_DK . " MINUTE");
    $stmt->execute([$kullanici, $ipOzeti]);
    $r = $stmt->fetch();
    return [(int)$r['kullanici'], (int)$r['toplam']];
}

$hata = '';
$bilgi = isset($_GET['kuruldu']) ? 'İlk yönetici oluşturuldu. Şimdi giriş yapabilirsiniz.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kullanici = trim((string)($_POST['kullanici_adi'] ?? ''));
    $sifre = (string)($_POST['sifre'] ?? '');
    $anahtar = mb_strtolower(mb_substr($kullanici, 0, 50, 'UTF-8'), 'UTF-8');
    $ipOzeti = Guvenlik::ipOzeti();

    if ($kullanici === '' || $sifre === '') {
        $hata = 'Lütfen tüm alanları doldurun.';
    } else {
        [$kullaniciDeneme, $ipDeneme] = girisDenemeSayilari($db, $ipOzeti, $anahtar);
        if ($kullaniciDeneme >= GIRIS_KULLANICI_SINIRI || $ipDeneme >= GIRIS_IP_SINIRI) {
            http_response_code(429);
            $hata = 'Çok fazla hatalı deneme. Lütfen ' . GIRIS_PENCERE_DK . ' dakika sonra tekrar deneyin.';
        } else {
            $stmt = $db->prepare("SELECT id, sifre, ad_soyad FROM bot_admin_users WHERE kullanici_adi = ?");
            $stmt->execute([$kullanici]);
            $user = $stmt->fetch();

            // Kullanıcı yoksa da bir hash doğrula ki yanıt süresi kullanıcı adının varlığını ele vermesin.
            $hash = $user['sifre'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
            $dogru = password_verify($sifre, $hash) && $user;

            if ($dogru) {
                Guvenlik::oturumYenile();
                $_SESSION['admin_id'] = (int)$user['id'];
                $_SESSION['admin_ad'] = $user['ad_soyad'];
                $db->prepare("DELETE FROM bot_giris_denemeleri WHERE ip_ozeti = ? AND kullanici_adi = ?")->execute([$ipOzeti, $anahtar]);
                if (password_needs_rehash($user['sifre'], PASSWORD_DEFAULT)) {
                    $db->prepare("UPDATE bot_admin_users SET sifre = ? WHERE id = ?")->execute([password_hash($sifre, PASSWORD_DEFAULT), $user['id']]);
                }
                Panel::yonlendir('dashboard');
            }

            $db->prepare("INSERT INTO bot_giris_denemeleri (ip_ozeti, kullanici_adi) VALUES (?, ?)")->execute([$ipOzeti, $anahtar]);
            $db->exec("DELETE FROM bot_giris_denemeleri WHERE deneme_tarihi < NOW() - INTERVAL 1 DAY");
            $hata = 'Kullanıcı adı veya şifre hatalı.';
        }
    }
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
        .info-msg { background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 0.8rem; border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem; text-align: center; border: 1px solid rgba(16,185,129,0.3); }
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
        
        <?php if($hata): ?><div class="error-msg"><?php echo Guvenlik::e($hata); ?></div><?php endif; ?>
        <?php if($bilgi): ?><div class="info-msg"><?php echo Guvenlik::e($bilgi); ?></div><?php endif; ?>
        
        <form method="POST" action="<?php echo Guvenlik::e(Panel::url('login')); ?>">
            <?php echo Guvenlik::csrfAlani(); ?>
            <div class="form-group">
                <label>Kullanıcı Adı</label>
                <input type="text" name="kullanici_adi" required autofocus autocomplete="username" maxlength="50">
            </div>
            <div class="form-group">
                <label>Şifre</label>
                <input type="password" name="sifre" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-submit">Sisteme Giriş Yap</button>
        </form>

        <div class="auth-footer">
            Yeni yönetici hesaplarını tam yetkili bir yönetici Sistem Ayarları'ndan ekler.
        </div>
    </div>
</body>
</html>
