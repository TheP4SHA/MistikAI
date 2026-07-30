<?php
// /data/MistikAI/admin/pages/ayarlar.php

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_id'])) {
    header("Location: /OmyoWeb/MistikAI/admin");
    exit;
}

// Veritabanı Yolu
$db_path = dirname(__DIR__, 2) . '/config/db.php';
require_once $db_path;
global $db;

// AJAX İşlemi: Ayarları Kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $yemekhane_url = trim($_POST['yemekhane_url']);
        $takvim_url = trim($_POST['takvim_url']);
        
        // Upsert logic (Insert or Update)
        $stmt = $db->prepare("INSERT INTO bot_sistem_ayarlari (ayar_adi, ayar_degeri) VALUES (?, ?) ON DUPLICATE KEY UPDATE ayar_degeri = VALUES(ayar_degeri)");
        $stmt->execute(['yemekhane_url', $yemekhane_url]);
        $stmt->execute(['takvim_url', $takvim_url]);
        
        echo json_encode(['success' => true, 'message' => 'Ayarlar başarıyla kaydedildi!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Kayıt sırasında hata oluştu: ' . $e->getMessage()]);
    }
    exit;
}

// Mevcut Ayarları Çek
$ayarlar_raw = $db->query("SELECT * FROM bot_sistem_ayarlari")->fetchAll();
$ayarlar = [];
foreach ($ayarlar_raw as $a) {
    $ayarlar[$a['ayar_adi']] = $a['ayar_degeri'];
}

$yemekhane_url = $ayarlar['yemekhane_url'] ?? 'https://www.osmaniye.edu.tr/yemekmenu';
$takvim_url = $ayarlar['takvim_url'] ?? 'https://www.osmaniye.edu.tr/tr/lisansustu/menuler/ogrenci/genel/47321-akademik-takvim';

$page_title = "Sistem Ayarları";
$active_menu = "ayarlar";

ob_start();
?>
    /* --- MODERN CSS --- */
    .card-pasha { background: #1a1d21; border-radius: 20px; border: 1px solid #333; overflow: hidden; margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    .card-pasha-head { padding: 20px 25px; border-bottom: 1px solid #333; background: rgba(255,255,255,0.02); display: flex; align-items: center; gap: 15px;}
    
    .form-group { padding: 20px 25px; border-bottom: 1px solid #222; }
    .form-group:last-child { border-bottom: none; }
    .form-label { display: block; font-weight: 600; color: #bbb; margin-bottom: 10px; }
    
    .modern-input { background: #0d0f12; border: 1px solid #444; color: white; padding: 12px 15px; border-radius: 8px; width: 100%; font-size: 0.95rem; transition: 0.3s; }
    .modern-input:focus { border-color: var(--accent-color); outline:none; }

    .btn-pasha { border:none; padding: 12px 25px; border-radius: 10px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; white-space: nowrap; }
    .btn-pasha:hover { transform: translateY(-2px); }
    .btn-pasha-blue { background: var(--accent-color); color: #fff; }
    .btn-pasha-green { background: var(--success-color); color: #000; }
<?php
$extra_css = ob_get_clean();

ob_start(); 
?>

<div class="hub-container" style="max-width:900px; margin:0 auto;">
    <div class="page-header" style="margin-bottom: 2rem;">
        <h1>Sistem Ayarları <span style="color:var(--accent-color);">/</span> Konfigürasyon</h1>
        <p style="color:var(--text-muted);">Veri kazıma (scraping) ve API entegrasyonu bağlantılarını yönetin.</p>
    </div>

    <div class="card-pasha">
        <div class="card-pasha-head">
            <h3 style="margin:0;"><i class="fas fa-spider" style="color:var(--accent-color);"></i> Veri Kazıma (Scraper) Adresleri</h3>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">🍱 Yemekhane Menüsü URL</label>
                <input type="url" id="yemekhaneUrl" class="modern-input" value="<?php echo htmlspecialchars($yemekhane_url); ?>">
                <small style="color:var(--text-muted); display:block; margin-top:5px;">Günlük veya haftalık yemek menüsünün çekileceği adres.</small>
            </div>
            <div class="form-group">
                <label class="form-label">📅 Akademik Takvim URL</label>
                <input type="url" id="takvimUrl" class="modern-input" value="<?php echo htmlspecialchars($takvim_url); ?>">
                <small style="color:var(--text-muted); display:block; margin-top:5px;">Akademik yıl faaliyetlerinin çekileceği adres.</small>
            </div>
            
            <div class="form-group" style="text-align: right; background: rgba(0,0,0,0.2);">
                <button onclick="saveSettings()" class="btn-pasha btn-pasha-blue" id="saveBtn">
                    <i class="fas fa-save"></i> AYARLARI KAYDET
                </button>
            </div>
        </div>
    </div>
</div>

<script>
async function saveSettings() {
    const yemekhane_url = document.getElementById('yemekhaneUrl').value;
    const takvim_url = document.getElementById('takvimUrl').value;
    
    if(!yemekhane_url || !takvim_url) {
        alert("Lütfen URL alanlarını boş bırakmayın!");
        return;
    }

    const btn = document.getElementById('saveBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> KAYDEDİLİYOR...';
    
    const fd = new FormData(); 
    fd.append('action', 'save_settings');
    fd.append('yemekhane_url', yemekhane_url);
    fd.append('takvim_url', takvim_url);

    try {
        const res = await fetch('ayarlar', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { 
            alert('✅ ' + data.message); 
        } else { 
            alert('❌ ' + data.message); 
        }
    } catch (e) { 
        alert('Sunucu ile iletişim kurulamadı.'); 
    } finally { 
        btn.disabled = false; 
        btn.innerHTML = originalText; 
    }
}
</script>

<?php 
$page_content = ob_get_clean();

$layout_path = dirname(__DIR__) . '/layouts/master_layouts.php';
if (file_exists($layout_path)) {
    require_once $layout_path; 
} else {
    echo "HATA: Layout dosyası bulunamadı: " . $layout_path;
}
?>
