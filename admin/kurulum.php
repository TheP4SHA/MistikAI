<?php
// admin/kurulum.php - İlk kurulum: ilk (tam yetkili) yöneticiyi oluşturur.
// Yalnız bot_admin_users tablosu boşken çalışır; bir yönetici oluştuktan sonra 404 döner.
define('PANEL_GIRISSIZ', true);
require_once __DIR__ . '/_baslat.php';

const KURULUM_MIN_SIFRE = 10;

function kurulumGerekli(PDO $db): bool {
    return (int)$db->query("SELECT COUNT(*) FROM bot_admin_users")->fetchColumn() === 0;
}

if (!kurulumGerekli($db)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>404</title></head><body style="font-family:sans-serif;text-align:center;padding-top:15vh;"><h2>Sayfa bulunamadı</h2></body></html>');
}

$hata = '';
$ad_soyad = '';
$kullanici = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad_soyad = trim((string)($_POST['ad_soyad'] ?? ''));
    $kullanici = trim((string)($_POST['kullanici_adi'] ?? ''));
    $sifre = (string)($_POST['sifre'] ?? '');
    $sifre_tekrar = (string)($_POST['sifre_tekrar'] ?? '');

    if ($ad_soyad === '' || $kullanici === '' || $sifre === '') {
        $hata = 'Lütfen tüm alanları doldurun.';
    } elseif (mb_strlen($ad_soyad, 'UTF-8') > 100) {
        $hata = 'Ad soyad en fazla 100 karakter olabilir.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $kullanici)) {
        $hata = 'Kullanıcı adı 3-50 karakter olmalı; yalnız harf, rakam, nokta, tire ve alt çizgi içerebilir.';
    } elseif (mb_strlen($sifre, 'UTF-8') < KURULUM_MIN_SIFRE) {
        $hata = 'Şifre en az ' . KURULUM_MIN_SIFRE . ' karakter olmalı.';
    } elseif ($sifre !== $sifre_tekrar) {
        $hata = 'Şifreler birbiriyle eşleşmiyor.';
    } else {
        // Aynı anda iki kurulum isteği gelirse yalnız biri yönetici oluşturabilsin.
        $kilit = (int)$db->query("SELECT GET_LOCK('mistikai_kurulum', 10)")->fetchColumn();
        $olusturuldu = false;
        try {
            if ($kilit !== 1 || !kurulumGerekli($db)) {
                http_response_code(409);
                $hata = 'Kurulum zaten tamamlanmış.';
            } else {
                $db->prepare("INSERT INTO bot_admin_users (kullanici_adi, sifre, ad_soyad, yetki) VALUES (?, ?, ?, 'tam')")
                   ->execute([$kullanici, password_hash($sifre, PASSWORD_DEFAULT), $ad_soyad]);
                $olusturuldu = true;
            }
        } catch (Throwable $e) {
            $hata = Panel::hata($e, 'kurulum');
        }
        $db->query("SELECT RELEASE_LOCK('mistikai_kurulum')");
        if ($olusturuldu) {
            Panel::yonlendir('login', ['kuruldu' => 1]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>MistikAI - İlk Kurulum</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg-main: #0f172a; --bg-panel: #1e293b; --bg-input: #334155; --text-main: #f8fafc; --text-muted: #94a3b8; --accent-color: #8b5cf6; --accent-hover: #7c3aed; --danger-color: #ef4444; --border-color: #334155; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-main); color: var(--text-main); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem 0; }
        .auth-card { background: var(--bg-panel); padding: 2.5rem; border-radius: 1rem; border: 1px solid var(--border-color); width: 100%; max-width: 450px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        .auth-header { text-align: center; margin-bottom: 1.5rem; }
        .auth-header i { font-size: 2.5rem; color: var(--accent-color); margin-bottom: 0.5rem; }
        .auth-header h1 { font-size: 1.3rem; font-weight: 700; }
        .auth-header p { color: var(--text-muted); font-size: 0.9rem; margin-top: 0.5rem; line-height: 1.5; }
        .form-group { margin-bottom: 1.2rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-muted); }
        .form-group input { width: 100%; padding: 0.8rem; background: var(--bg-input); border: 1px solid var(--border-color); color: white; border-radius: 0.5rem; font-size: 1rem; outline: none; transition: 0.3s; }
        .form-group input:focus { border-color: var(--accent-color); }
        .btn-submit { width: 100%; background: var(--accent-color); color: white; border: none; padding: 0.8rem; border-radius: 0.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 1rem; }
        .btn-submit:hover { background: var(--accent-hover); }
        .error-msg { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); padding: 0.8rem; border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem; text-align: center; border: 1px solid rgba(239,68,68,0.3); }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <i class="fas fa-shield-alt"></i>
            <h1>MistikAI İlk Kurulum</h1>
            <p>Henüz yönetici yok. Tam yetkili ilk yöneticiyi oluşturun. Bu sayfa, yönetici oluşturulduktan sonra kapanır.</p>
        </div>

        <?php if($hata): ?><div class="error-msg"><?php echo Guvenlik::e($hata); ?></div><?php endif; ?>

        <form method="POST" action="<?php echo Guvenlik::e(Panel::url('kurulum')); ?>">
            <?php echo Guvenlik::csrfAlani(); ?>
            <div class="form-group">
                <label>Ad Soyad</label>
                <input type="text" name="ad_soyad" required maxlength="100" value="<?php echo Guvenlik::e($ad_soyad); ?>">
            </div>
            <div class="form-group">
                <label>Kullanıcı Adı</label>
                <input type="text" name="kullanici_adi" required maxlength="50" pattern="[A-Za-z0-9_.\-]{3,50}" autocomplete="username" value="<?php echo Guvenlik::e($kullanici); ?>">
            </div>
            <div class="form-group">
                <label>Şifre (en az <?php echo KURULUM_MIN_SIFRE; ?> karakter)</label>
                <input type="password" name="sifre" required minlength="<?php echo KURULUM_MIN_SIFRE; ?>" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label>Şifre (Tekrar)</label>
                <input type="password" name="sifre_tekrar" required minlength="<?php echo KURULUM_MIN_SIFRE; ?>" autocomplete="new-password">
            </div>
            <button type="submit" class="btn-submit">Yöneticiyi Oluştur</button>
        </form>
    </div>
</body>
</html>
