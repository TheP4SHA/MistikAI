<?php
// admin/pages/ayarlar.php - Sistem ayarları: kazıyıcı adresleri ve (tam yetkililer için) yönetici hesapları
require_once __DIR__ . '/../_baslat.php';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $upsert = $db->prepare("INSERT INTO bot_sistem_ayarlari (ayar_adi, ayar_degeri) VALUES (?, ?) ON DUPLICATE KEY UPDATE ayar_degeri = VALUES(ayar_degeri)");

    if ($_POST['action'] === 'save_settings') {
        $yemekhane_url = trim((string)($_POST['yemekhane_url'] ?? ''));
        $takvim_url = trim((string)($_POST['takvim_url'] ?? ''));
        foreach ([$yemekhane_url, $takvim_url] as $u) {
            $sema = strtolower((string)parse_url($u, PHP_URL_SCHEME));
            if (!filter_var($u, FILTER_VALIDATE_URL) || !in_array($sema, ['http', 'https'], true) || strlen($u) > 255) {
                Panel::json(['success' => false, 'message' => 'Adresler http:// ya da https:// ile başlayan geçerli URL olmalı (en fazla 255 karakter).']);
            }
        }
        try {
            $upsert->execute(['yemekhane_url', $yemekhane_url]);
            $upsert->execute(['takvim_url', $takvim_url]);
            Panel::json(['success' => true, 'message' => 'Ayarlar başarıyla kaydedildi!']);
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'ayar kaydı')]);
        }
    }

    if ($_POST['action'] === 'kayit_durumu') {
        if (!Panel::tamYetkili()) {
            Panel::json(['success' => false, 'message' => 'Bu ayarı yalnız tam yetkili yöneticiler değiştirebilir.'], 403);
        }
        $acik = ($_POST['acik'] ?? '') === '1' ? '1' : '0';
        try {
            $upsert->execute(['admin_kayit_acik', $acik]);
            Panel::json(['success' => true, 'message' => $acik === '1' ? 'Yönetici ekleme açıldı.' : 'Yönetici ekleme kapatıldı.']);
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'kayıt ayarı')]);
        }
    }

    Panel::json(['success' => false, 'message' => 'Bilinmeyen işlem.'], 400);
}

// Mevcut ayarlar
$ayarlar = [];
foreach ($db->query("SELECT ayar_adi, ayar_degeri FROM bot_sistem_ayarlari")->fetchAll() as $a) {
    $ayarlar[$a['ayar_adi']] = $a['ayar_degeri'];
}
$yemekhane_url = $ayarlar['yemekhane_url'] ?? 'https://www.osmaniye.edu.tr/yemekmenu';
$takvim_url = $ayarlar['takvim_url'] ?? 'https://www.osmaniye.edu.tr/tr/lisansustu/menuler/ogrenci/genel/47321-akademik-takvim';
$kayit_acik = ($ayarlar['admin_kayit_acik'] ?? '0') === '1';
$yoneticiler = Panel::tamYetkili()
    ? $db->query("SELECT kullanici_adi, ad_soyad, yetki, kayit_tarihi FROM bot_admin_users ORDER BY id ASC")->fetchAll()
    : [];
$izinli_hostlar = (array)($GLOBALS['SCRAPER_IZINLI_HOSTLAR'] ?? []);

$page_title = "Sistem Ayarları";
$active_menu = "ayarlar";

ob_start();
?>
    /* --- MODERN CSS --- */
    .card-pasha { background: #1a1d21; border-radius: 20px; border: 1px solid #333; overflow: hidden; margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    .card-pasha-head { padding: 20px 25px; border-bottom: 1px solid #333; background: rgba(255,255,255,0.02); display: flex; align-items: center; justify-content: space-between; gap: 15px;}

    .form-group { padding: 20px 25px; border-bottom: 1px solid #222; }
    .form-group:last-child { border-bottom: none; }
    .form-label { display: block; font-weight: 600; color: #bbb; margin-bottom: 10px; }

    .modern-input { background: #0d0f12; border: 1px solid #444; color: white; padding: 12px 15px; border-radius: 8px; width: 100%; font-size: 0.95rem; transition: 0.3s; }
    .modern-input:focus { border-color: var(--accent-color); outline:none; }

    .btn-pasha { border:none; padding: 12px 25px; border-radius: 10px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; white-space: nowrap; text-decoration: none; }
    .btn-pasha:hover { transform: translateY(-2px); }
    .btn-pasha-blue { background: var(--accent-color); color: #fff; }
    .btn-pasha-green { background: var(--success-color); color: #000; }

    .user-table { width: 100%; border-collapse: collapse; }
    .user-table th { text-align: left; padding: 12px 25px; font-size: 0.8rem; color: #666; text-transform: uppercase; border-bottom: 1px solid #333; }
    .user-table td { padding: 12px 25px; border-bottom: 1px solid #222; }
    .switch-row { display: flex; align-items: center; justify-content: space-between; gap: 15px; }
<?php
$extra_css = ob_get_clean();

ob_start();
?>

<div class="hub-container" style="max-width:900px; margin:0 auto;">
    <div class="page-header" style="margin-bottom: 2rem;">
        <h1>Sistem Ayarları <span style="color:var(--accent-color);">/</span> Konfigürasyon</h1>
        <p style="color:var(--text-muted);">Veri kazıma (scraping) ve yönetici hesaplarını yönetin.</p>
    </div>

    <div class="card-pasha">
        <div class="card-pasha-head">
            <h3 style="margin:0;"><i class="fas fa-spider" style="color:var(--accent-color);"></i> Veri Kazıma (Scraper) Adresleri</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">🍱 Yemekhane Menüsü URL</label>
                <input type="url" id="yemekhaneUrl" class="modern-input" value="<?php echo Guvenlik::e($yemekhane_url); ?>">
                <small style="color:var(--text-muted); display:block; margin-top:5px;">Günlük veya haftalık yemek menüsünün çekileceği adres.</small>
            </div>
            <div class="form-group">
                <label class="form-label">📅 Akademik Takvim URL</label>
                <input type="url" id="takvimUrl" class="modern-input" value="<?php echo Guvenlik::e($takvim_url); ?>">
                <small style="color:var(--text-muted); display:block; margin-top:5px;">Akademik yıl faaliyetlerinin çekileceği adres.</small>
            </div>
            <div class="form-group">
                <small style="color:var(--text-muted); display:block;">
                    <i class="fas fa-shield-alt"></i>
                    <?php if ($izinli_hostlar): ?>
                        Kazıyıcının erişebileceği alan adları (config/db.php → $SCRAPER_IZINLI_HOSTLAR): <b><?php echo Guvenlik::e(implode(', ', $izinli_hostlar)); ?></b>
                    <?php else: ?>
                        Kazıyıcı kapalı: config/db.php içindeki $SCRAPER_IZINLI_HOSTLAR listesine izinli alan adlarını ekleyin.
                    <?php endif; ?>
                </small>
            </div>

            <div class="form-group" style="text-align: right; background: rgba(0,0,0,0.2);">
                <button type="button" class="btn-pasha btn-pasha-blue" id="saveBtn">
                    <i class="fas fa-save"></i> AYARLARI KAYDET
                </button>
            </div>
        </div>
    </div>

    <?php if (Panel::tamYetkili()): ?>
    <div class="card-pasha">
        <div class="card-pasha-head">
            <h3 style="margin:0;"><i class="fas fa-users-cog" style="color:var(--accent-color);"></i> Yönetici Hesapları</h3>
            <?php if ($kayit_acik): ?>
                <a href="<?php echo Guvenlik::e(Panel::url('register')); ?>" class="btn-pasha btn-pasha-green"><i class="fas fa-user-plus"></i> Yönetici Ekle</a>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <div class="switch-row">
                <div>
                    <b>Yönetici ekleme</b><br>
                    <small style="color:var(--text-muted);">Açıkken tam yetkili yöneticiler bu panelden yeni hesap oluşturabilir. İşiniz bitince kapatın.</small>
                </div>
                <button type="button" id="kayitBtn" class="btn-pasha <?php echo $kayit_acik ? 'btn-pasha-green' : 'btn-pasha-blue'; ?>" data-acik="<?php echo $kayit_acik ? '1' : '0'; ?>">
                    <?php echo $kayit_acik ? '<i class="fas fa-lock-open"></i> AÇIK (kapat)' : '<i class="fas fa-lock"></i> KAPALI (aç)'; ?>
                </button>
            </div>
        </div>
        <table class="user-table">
            <thead><tr><th>Kullanıcı Adı</th><th>Ad Soyad</th><th>Yetki</th><th>Kayıt</th></tr></thead>
            <tbody>
                <?php foreach ($yoneticiler as $y): ?>
                <tr>
                    <td style="font-family:monospace;"><?php echo Guvenlik::e($y['kullanici_adi']); ?></td>
                    <td><?php echo Guvenlik::e($y['ad_soyad']); ?></td>
                    <td><?php echo $y['yetki'] === 'tam' ? '<span class="badge success">Tam</span>' : '<span class="badge warning">Kısıtlı</span>'; ?></td>
                    <td style="color:var(--text-muted);"><?php echo Guvenlik::e(date('d.m.Y', strtotime($y['kayit_tarihi']))); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('saveBtn').addEventListener('click', async function () {
    const btn = this;
    const yemekhane_url = document.getElementById('yemekhaneUrl').value;
    const takvim_url = document.getElementById('takvimUrl').value;
    if (!yemekhane_url || !takvim_url) { alert("Lütfen URL alanlarını boş bırakmayın!"); return; }

    const originalText = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> KAYDEDİLİYOR...';
    const fd = new FormData();
    fd.append('action', 'save_settings');
    fd.append('yemekhane_url', yemekhane_url);
    fd.append('takvim_url', takvim_url);
    try {
        const data = await MISTIK.istek('ayarlar', fd);
        alert((data.success ? '✅ ' : '❌ ') + data.message);
    } catch (e) {
        alert('Sunucu ile iletişim kurulamadı.');
    } finally {
        btn.disabled = false; btn.innerHTML = originalText;
    }
});

const kayitBtn = document.getElementById('kayitBtn');
if (kayitBtn) {
    kayitBtn.addEventListener('click', async function () {
        const ac = this.dataset.acik !== '1';
        if (!confirm(ac ? 'Yönetici ekleme açılsın mı?' : 'Yönetici ekleme kapatılsın mı?')) return;
        const fd = new FormData();
        fd.append('action', 'kayit_durumu');
        fd.append('acik', ac ? '1' : '0');
        const data = await MISTIK.istek('ayarlar', fd);
        if (data.success) { location.reload(); } else { alert(data.message); }
    });
}
</script>

<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../layouts/master_layouts.php';
