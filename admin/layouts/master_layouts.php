<?php
// /data/MistikAI/admin/layouts/master_layouts.php

// Güvenlik Duvarı: Giriş yapmamış kişileri login sayfasına şutla!
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_id'])) {
    // YOL DÜZELTİLDİ: .htaccess kuralına göre doğrudan "admin" rotasına gönder
    header("Location: /OmyoWeb/MistikAI/admin");
    exit;
}

// YOL DÜZELTİLDİ: Sitedeki tüm linklerin .htaccess üzerinden doğru çalışması için BASE URL
$base_url = '/OmyoWeb/MistikAI/admin'; 
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MistikAI | <?php echo isset($page_title) ? $page_title : 'Admin Panel'; ?></title>
    
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
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-layer-group"></i>
            <h2>MistikAI Admin</h2>
        </div>
        
       <nav class="nav-links">
    <a href="<?php echo $base_url; ?>/dashboard" class="nav-item <?php echo ($active_menu === 'dashboard') ? 'active' : ''; ?>">
        <i class="fas fa-home"></i> Ana Ekran (Dashboard)
    </a>
    <a href="<?php echo $base_url; ?>/logger" class="nav-item <?php echo ($active_menu === 'logger') ? 'active' : ''; ?>">
        <i class="fas fa-chart-pie"></i> Blackbox (Log Analizi)
    </a>
    <a href="<?php echo $base_url; ?>/modul" class="nav-item <?php echo ($active_menu === 'moduller') ? 'active' : ''; ?>">
        <i class="fas fa-robot"></i> Modül / Niyet Yönetimi
    </a>
    <a href="<?php echo $base_url; ?>/akis" class="nav-item <?php echo ($active_menu === 'akis') ? 'active' : ''; ?>">
        <i class="fas fa-project-diagram"></i> Akış (Flow) Yönetimi
    </a>
    <a href="<?php echo $base_url; ?>/icerik" class="nav-item <?php echo ($active_menu === 'icerik') ? 'active' : ''; ?>">
        <i class="fas fa-database"></i> Dinamik İçerik
    </a>
    <a href="<?php echo $base_url; ?>/ayarlar" class="nav-item <?php echo ($active_menu === 'ayarlar') ? 'active' : ''; ?>">
        <i class="fas fa-cogs"></i> Sistem Ayarları
    </a>
</nav>
        
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo mb_substr(mb_strtoupper($_SESSION['admin_ad'] ?? 'A', 'UTF-8'), 0, 1); ?>
                </div>
                <div class="user-details">
                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['admin_ad'] ?? 'Yönetici'); ?></span>
                    <span class="user-role">Sistem Yöneticisi</span>
                </div>
            </div>
            <a href="<?php echo $base_url; ?>/logout" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Güvenli Çıkış</a>
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