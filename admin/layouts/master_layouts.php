<?php
// admin/layouts/master_layouts.php - Panel iskeleti
// Oturum, CSRF ve güvenlik başlıkları admin/_baslat.php'de halledilir; bu dosya yalnız sayfayı çizer.
// Çağıran sayfa: $page_title, $active_menu, $page_content, isteğe bağlı $extra_css ve $extra_js.

if (!class_exists('Panel')) {
    http_response_code(404);
    exit;
}
$aktif_kullanici = Panel::kullanici() ?? [];
$active_menu = $active_menu ?? '';
$menu_ogeleri = [
    'dashboard' => ['dashboard', 'fa-home', 'Ana Ekran (Dashboard)'],
    'logger'    => ['logger', 'fa-chart-pie', 'Blackbox (Log Analizi)'],
    'moduller'  => ['modul', 'fa-robot', 'Modül / Niyet Yönetimi'],
    'akis'      => ['akis', 'fa-project-diagram', 'Akış (Flow) Yönetimi'],
    'icerik'    => ['icerik', 'fa-database', 'Dinamik İçerik'],
    'ayarlar'   => ['ayarlar', 'fa-cogs', 'Sistem Ayarları'],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>MistikAI | <?php echo Guvenlik::e($page_title ?? 'Admin Panel'); ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-main: #0f172a; --bg-panel: #1e293b; --bg-input: #334155;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --accent-color: #8b5cf6; --accent-hover: #7c3aed;
            --danger-color: #ef4444; --success-color: #10b981; --warning-color: #f59e0b;
            --border-color: #334155;
            --sidebar-width: 260px;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-main); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }
        
        /* SIDEBAR (Sol Menü) */
        .sidebar { width: var(--sidebar-width); background: var(--bg-panel); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; flex-shrink: 0; }
        .sidebar-header { padding: 2rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; }
        .sidebar-header i { font-size: 1.8rem; color: var(--accent-color); }
        .sidebar-header h2 { font-size: 1.3rem; font-weight: 700; letter-spacing: -0.5px; }
        
        .nav-links { padding: 1.5rem 1rem; display: flex; flex-direction: column; gap: 0.5rem; flex: 1; overflow-y: auto;}
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 1rem 1.2rem; color: var(--text-muted); text-decoration: none; border-radius: 0.8rem; font-weight: 500; transition: 0.3s; }
        .nav-item:hover { background: rgba(139, 92, 246, 0.1); color: var(--accent-color); }
        .nav-item.active { background: var(--accent-color); color: white; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3); }
        
        /* Kullanıcı Bilgisi ve Çıkış Butonu */
        .sidebar-footer { padding: 1.5rem; border-top: 1px solid var(--border-color); }
        .user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 1rem; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--bg-input); display: flex; align-items: center; justify-content: center; color: var(--accent-color); font-weight: bold; font-size: 1.2rem; }
        .user-details { display: flex; flex-direction: column; }
        .user-name { font-weight: 600; font-size: 0.95rem; }
        .user-role { font-size: 0.8rem; color: var(--text-muted); }
        .btn-logout { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 0.8rem; background: rgba(239, 68, 68, 0.1); color: var(--danger-color); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 0.5rem; text-decoration: none; font-weight: 600; transition: 0.3s; }
        .btn-logout:hover { background: var(--danger-color); color: white; }

        /* ANA İÇERİK ALANI (Main) */
        .main-content { flex: 1; overflow-y: auto; padding: 2rem 3rem; position: relative; }
        
        /* Ortak Bileşenler (Kartlar, Butonlar, Tablolar) */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .page-header h1 { font-size: 1.8rem; font-weight: 600; }
        
        .grid-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .card { background: var(--bg-panel); padding: 1.5rem; border-radius: 1rem; border: 1px solid var(--border-color); }
        .card-title { color: var(--text-muted); font-size: 0.9rem; font-weight: 500; text-transform: uppercase; margin-bottom: 0.5rem; display:block; }
        .card-value { font-size: 2.5rem; font-weight: 700; }
        
        .content-box { background: var(--bg-panel); border-radius: 1rem; border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 2rem;}
        .box-header { padding: 1.2rem 1.5rem; border-bottom: 1px solid var(--border-color); font-weight: 600; display: flex; justify-content: space-between; align-items: center;}
        
        .badge { padding: 0.3rem 0.8rem; border-radius: 2rem; font-size: 0.8rem; font-weight: 600; display: inline-block;}
        .badge.success { background-color: rgba(16, 185, 129, 0.15); color: var(--success-color); border: 1px solid rgba(16,185,129,0.3); }
        .badge.danger { background-color: rgba(239, 68, 68, 0.15); color: var(--danger-color); border: 1px solid rgba(239,68,68,0.3); }
        .badge.warning { background-color: rgba(245, 158, 11, 0.15); color: var(--warning-color); border: 1px solid rgba(245,158,11,0.3); }
        
        /* İçerik sayfalarının göndereceği ekstra CSS kodları buraya basılır */
        <?php if(isset($extra_css)) echo $extra_css; ?>
        
    </style>
    <script>
        // Panel ortak JS: CSRF jetonu, sayfa URL'leri ve tüm POST istekleri için tek yardımcı.
        window.MISTIK = <?php echo Panel::jsAyarlari(); ?>;
        MISTIK.url = function (sayfa, sorgu) {
            let u = MISTIK.urller[sayfa];
            if (sorgu) { u += (u.indexOf('?') === -1 ? '?' : '&') + new URLSearchParams(sorgu).toString(); }
            return u;
        };
        // POST + X-CSRF-Token; yanıt her zaman {success, message, ...} nesnesi olarak döner.
        MISTIK.istek = async function (sayfa, govde, sorgu) {
            const yanit = await fetch(MISTIK.url(sayfa, sorgu), {
                method: 'POST',
                body: govde,
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': MISTIK.csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            try {
                return await yanit.json();
            } catch (e) {
                return { success: false, message: 'Sunucudan geçersiz yanıt alındı (HTTP ' + yanit.status + ').' };
            }
        };
        // Sunucu mesajını metin olarak (HTML yorumlanmadan) renkli gösterir.
        MISTIK.mesaj = function (kutu, metin, basarili) {
            kutu.textContent = '';
            const s = document.createElement('span');
            s.style.color = basarili ? 'var(--success-color)' : 'var(--danger-color)';
            s.textContent = metin || '';
            kutu.appendChild(s);
        };
    </script>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-layer-group"></i>
            <h2>MistikAI Admin</h2>
        </div>
        
        <nav class="nav-links">
            <?php foreach ($menu_ogeleri as $anahtar => [$sayfa, $ikon, $etiket]): ?>
            <a href="<?php echo Guvenlik::e(Panel::url($sayfa)); ?>" class="nav-item <?php echo $active_menu === $anahtar ? 'active' : ''; ?>">
                <i class="fas <?php echo $ikon; ?>"></i> <?php echo Guvenlik::e($etiket); ?>
            </a>
            <?php endforeach; ?>
        </nav>
        
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo Guvenlik::e(mb_substr(mb_strtoupper($aktif_kullanici['ad_soyad'] ?? 'A', 'UTF-8'), 0, 1)); ?>
                </div>
                <div class="user-details">
                    <span class="user-name"><?php echo Guvenlik::e($aktif_kullanici['ad_soyad'] ?? 'Yönetici'); ?></span>
                    <span class="user-role"><?php echo Panel::tamYetkili() ? 'Tam Yetkili Yönetici' : 'Kısıtlı Yönetici'; ?></span>
                </div>
            </div>
            <form method="POST" action="<?php echo Guvenlik::e(Panel::url('logout')); ?>">
                <?php echo Guvenlik::csrfAlani(); ?>
                <button type="submit" class="btn-logout" style="cursor:pointer;"><i class="fas fa-sign-out-alt"></i> Güvenli Çıkış</button>
            </form>
        </div>
    </aside>

    <main class="main-content">
        <?php 
            // Bu iskeleti çağıran dosyalar ($page_content değişkeni ile) kendi içeriğini buraya gömecek
            if(isset($page_content)) {
                echo $page_content;
            } else {
                echo "<h2 style='color:var(--danger-color);'>İçerik Yüklenemedi!</h2>";
            }
        ?>
    </main>

    <?php if(isset($extra_js)) echo $extra_js; ?>

</body>
</html>