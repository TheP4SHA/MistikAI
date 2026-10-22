<?php
// admin/register.php - Yönetici ekle
// Herkese açık kayıt değildir: yalnız oturum açmış TAM yetkili yönetici, ve yalnız
// Sistem Ayarları'nda "yönetici ekleme" (admin_kayit_acik = '1') açıkken yeni hesap oluşturabilir.
require_once __DIR__ . '/_baslat.php';

const KAYIT_MIN_SIFRE = 10;

if (!Panel::tamYetkili()) {
    Panel::reddet('Yönetici eklemek için tam yetkili olmalısınız.', 403);
}
$kayit_acik = Panel::ayar($db, 'admin_kayit_acik', '0') === '1';

$hata = '';
$basari = '';
$form = ['ad_soyad' => '', 'kullanici_adi' => '', 'yetki' => 'kisitli'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$kayit_acik) {
        Panel::reddet('Yönetici ekleme kapalı. Önce Sistem Ayarları\'ndan açın.', 403);
    }
    $form['ad_soyad'] = trim((string)($_POST['ad_soyad'] ?? ''));
    $form['kullanici_adi'] = trim((string)($_POST['kullanici_adi'] ?? ''));
    $form['yetki'] = ($_POST['yetki'] ?? '') === 'tam' ? 'tam' : 'kisitli';
    $sifre = (string)($_POST['sifre'] ?? '');
    $sifre_tekrar = (string)($_POST['sifre_tekrar'] ?? '');

    if ($form['ad_soyad'] === '' || $form['kullanici_adi'] === '' || $sifre === '') {
        $hata = 'Lütfen tüm alanları doldurun.';
    } elseif (mb_strlen($form['ad_soyad'], 'UTF-8') > 100) {
        $hata = 'Ad soyad en fazla 100 karakter olabilir.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $form['kullanici_adi'])) {
        $hata = 'Kullanıcı adı 3-50 karakter olmalı; yalnız harf, rakam, nokta, tire ve alt çizgi içerebilir.';
    } elseif (mb_strlen($sifre, 'UTF-8') < KAYIT_MIN_SIFRE) {
        $hata = 'Şifre en az ' . KAYIT_MIN_SIFRE . ' karakter olmalı.';
    } elseif ($sifre !== $sifre_tekrar) {
        $hata = 'Şifreler birbiriyle eşleşmiyor.';
    } else {
        try {
            $check = $db->prepare("SELECT id FROM bot_admin_users WHERE kullanici_adi = ?");
            $check->execute([$form['kullanici_adi']]);
            if ($check->fetch()) {
                $hata = 'Bu kullanıcı adı zaten alınmış.';
            } else {
                $db->prepare("INSERT INTO bot_admin_users (kullanici_adi, sifre, ad_soyad, yetki) VALUES (?, ?, ?, ?)")
                   ->execute([$form['kullanici_adi'], password_hash($sifre, PASSWORD_DEFAULT), $form['ad_soyad'], $form['yetki']]);
                $basari = 'Yönetici eklendi: ' . $form['kullanici_adi'] . ($form['yetki'] === 'tam' ? ' (tam yetkili)' : ' (kısıtlı)');
                $form = ['ad_soyad' => '', 'kullanici_adi' => '', 'yetki' => 'kisitli'];
            }
        } catch (Throwable $e) {
            $hata = Panel::hata($e, 'yönetici ekleme');
        }
    }
}

$page_title = "Yönetici Ekle";
$active_menu = "ayarlar";

ob_start();
?>
    .reg-card { background: var(--bg-panel); border-radius: 1rem; border: 1px solid var(--border-color); max-width: 560px; padding: 2rem; }
    .reg-card .form-group { margin-bottom: 1.2rem; }
    .reg-card label { display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-muted); }
    .reg-card input, .reg-card select { width: 100%; padding: 0.8rem; background: var(--bg-input); border: 1px solid var(--border-color); color: white; border-radius: 0.5rem; font-size: 1rem; outline: none; }
    .reg-card input:focus, .reg-card select:focus { border-color: var(--accent-color); }
    .reg-btn { width: 100%; background: var(--accent-color); color: white; border: none; padding: 0.8rem; border-radius: 0.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem; }
    .reg-msg { padding: 0.8rem; border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.9rem; }
    .reg-msg.err { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); border: 1px solid rgba(239,68,68,0.3); }
    .reg-msg.ok { background: rgba(16, 185, 129, 0.1); color: var(--success-color); border: 1px solid rgba(16,185,129,0.3); }
<?php
$extra_css = ob_get_clean();

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Yönetici Ekle</h1>
        <p style="color: var(--text-muted); margin-top: 5px;">Panele yeni bir yönetici hesabı tanımlayın.</p>
    </div>
    <a href="<?php echo Guvenlik::e(Panel::url('ayarlar')); ?>" style="color: var(--text-muted); text-decoration: none;"><i class="fas fa-arrow-left"></i> Sistem Ayarları</a>
</div>

<div class="reg-card">
    <?php if ($hata): ?><div class="reg-msg err"><?php echo Guvenlik::e($hata); ?></div><?php endif; ?>
    <?php if ($basari): ?><div class="reg-msg ok"><?php echo Guvenlik::e($basari); ?></div><?php endif; ?>

    <?php if (!$kayit_acik): ?>
        <div class="reg-msg err">Yönetici ekleme şu an kapalı. Sistem Ayarları'ndaki "Yönetici ekleme" anahtarını açtıktan sonra bu formu kullanabilirsiniz.</div>
    <?php else: ?>
    <form method="POST" action="<?php echo Guvenlik::e(Panel::url('register')); ?>">
        <?php echo Guvenlik::csrfAlani(); ?>
        <div class="form-group">
            <label>Ad Soyad</label>
            <input type="text" name="ad_soyad" required maxlength="100" value="<?php echo Guvenlik::e($form['ad_soyad']); ?>">
        </div>
        <div class="form-group">
            <label>Kullanıcı Adı (benzersiz)</label>
            <input type="text" name="kullanici_adi" required maxlength="50" pattern="[A-Za-z0-9_.\-]{3,50}" autocomplete="off" value="<?php echo Guvenlik::e($form['kullanici_adi']); ?>">
        </div>
        <div class="form-group">
            <label>Yetki</label>
            <select name="yetki">
                <option value="kisitli" <?php echo $form['yetki'] === 'kisitli' ? 'selected' : ''; ?>>Kısıtlı (içerik, modül ve akış yönetimi)</option>
                <option value="tam" <?php echo $form['yetki'] === 'tam' ? 'selected' : ''; ?>>Tam (yönetici ekleme, geliştirici araçları)</option>
            </select>
        </div>
        <div class="form-group">
            <label>Şifre (en az <?php echo KAYIT_MIN_SIFRE; ?> karakter)</label>
            <input type="password" name="sifre" required minlength="<?php echo KAYIT_MIN_SIFRE; ?>" autocomplete="new-password">
        </div>
        <div class="form-group">
            <label>Şifre (Tekrar)</label>
            <input type="password" name="sifre_tekrar" required minlength="<?php echo KAYIT_MIN_SIFRE; ?>" autocomplete="new-password">
        </div>
        <button type="submit" class="reg-btn"><i class="fas fa-user-plus"></i> Yöneticiyi Ekle</button>
    </form>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/layouts/master_layouts.php';
