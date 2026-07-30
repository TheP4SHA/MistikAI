<?php
// /data/admin/front/modul.php

// GÜVENLİK DUVARI: Oturum yoksa logine şutla!
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_id'])) {
    header("Location: /OmyoWeb/MistikAI/admin/login");
    exit;
}

// 1. VERİTABANI BAĞLANTISI (Mutlak Yol)
$doc_root = $_SERVER['DOCUMENT_ROOT']; // /var/www/html/
$base_path = $doc_root . '/OmyoWeb/MistikAI/data';
$db_path = $base_path . '/config/db.php';

if (file_exists($db_path)) {
    require_once $db_path;
} else {
    try {
        require_once __DIR__ . '/../../config/db.php';
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Kritik Hata: Veritabanı bağlantısı kurulamadı.");
    }
}
global $db;

// ======================================================================
// 2. AJAX İŞLEM YAKALAYICILAR
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    // ... [ÖNCEKİ A/B/C/D/E/F İŞLEMLERİ AYNEN KORUNDU] ...
    if ($_POST['action'] === 'modul_ekle') {
        $kodu = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['modul_kodu'])));
        $adi = trim($_POST['modul_adi']);
        $cevap = trim($_POST['cevap_metni']);
        $buton_metni = trim($_POST['buton_metni']);
        $link_url = trim($_POST['link_url']);
        
        if (!empty($kodu) && !empty($adi)) {
            try {
                $check = $db->prepare("SELECT id FROM bot_moduller WHERE modul_kodu = :k");
                $check->execute([':k' => $kodu]);
                if ($check->rowCount() > 0) { echo json_encode(['success' => false, 'message' => "Bu modül kodu zaten kullanılıyor!"]); exit; }
                
                $insert = $db->prepare("INSERT INTO bot_moduller (modul_kodu, modul_adi, cevap_metni, buton_metni, link_url, aktif_mi) VALUES (:k, :a, :c, :b, :l, 1)");
                $insert->execute([':k' => $kodu, ':a' => $adi, ':c' => empty($cevap) ? null : $cevap, ':b' => empty($buton_metni) ? null : $buton_metni, ':l' => empty($link_url) ? null : $link_url]);
                echo json_encode(['success' => true, 'message' => 'Yetenek başarıyla sisteme eklendi!']);
            } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => 'DB Hatası: ' . $e->getMessage()]); }
        } else { echo json_encode(['success' => false, 'message' => 'Modül Adı ve Kodu zorunludur.']); }
        exit;
    }

    if ($_POST['action'] === 'modul_duzenle') {
        $modul_id = (int)$_POST['modul_id'];
        $kodu = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['modul_kodu'])));
        $adi = trim($_POST['modul_adi']);
        $cevap = trim($_POST['cevap_metni']);
        $buton_metni = trim($_POST['buton_metni']);
        $link_url = trim($_POST['link_url']);
        
        if (!empty($kodu) && !empty($adi) && $modul_id > 0) {
            try {
                $check = $db->prepare("SELECT id FROM bot_moduller WHERE modul_kodu = :k AND id != :id");
                $check->execute([':k' => $kodu, ':id' => $modul_id]);
                if ($check->rowCount() > 0) { echo json_encode(['success' => false, 'message' => "Bu modül kodu başka bir modül tarafından kullanılıyor!"]); exit; }
                
                $update = $db->prepare("UPDATE bot_moduller SET modul_kodu = :k, modul_adi = :a, cevap_metni = :c, buton_metni = :b, link_url = :l WHERE id = :id");
                $update->execute([':k' => $kodu, ':a' => $adi, ':c' => empty($cevap) ? null : $cevap, ':b' => empty($buton_metni) ? null : $buton_metni, ':l' => empty($link_url) ? null : $link_url, ':id' => $modul_id]);
                echo json_encode(['success' => true, 'message' => 'Modül başarıyla güncellendi!']);
            } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => 'DB Hatası: ' . $e->getMessage()]); }
        } else { echo json_encode(['success' => false, 'message' => 'Eksik bilgi girdiniz.']); }
        exit;
    }

    if ($_POST['action'] === 'toggle_status') {
        $modul_id = (int)$_POST['modul_id'];
        $yeni_durum = (int)$_POST['yeni_durum'];
        try {
            $update = $db->prepare("UPDATE bot_moduller SET aktif_mi = :d WHERE id = :id");
            $update->execute([':d' => $yeni_durum, ':id' => $modul_id]);
            echo json_encode(['success' => true, 'message' => 'Durum güncellendi!']);
        } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => 'DB Hatası: ' . $e->getMessage()]); }
        exit;
    }

    if ($_POST['action'] === 'modul_sil') {
        $modul_id = (int)$_POST['modul_id'];
        try {
            $db->prepare("DELETE FROM bot_anahtar_kelimeler WHERE modul_id = :id")->execute([':id' => $modul_id]);
            $db->prepare("DELETE FROM bot_moduller WHERE id = :id")->execute([':id' => $modul_id]);
            echo json_encode(['success' => true, 'message' => 'Modül ve içindeki tüm kelimeler kalıcı olarak silindi!']);
        } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => 'DB Hatası: ' . $e->getMessage()]); }
        exit;
    }

    if ($_POST['action'] === 'execute_sql') {
        if ($_SESSION['admin_id'] != 1) { echo json_encode(['success' => false, 'message' => 'Yetkisiz Erişim!']); exit; }
        $sql_query = trim($_POST['sql_query']);
        if(empty($sql_query)) { echo json_encode(['success' => false, 'message' => 'Sorgu alanı boş.']); exit; }
        try {
            if (stripos($sql_query, 'select') === 0) {
                $stmt = $db->query($sql_query);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'type' => 'select', 'message' => count($results)." satır getirildi.", 'data' => $results]);
            } else {
                $affected = $db->exec($sql_query);
                echo json_encode(['success' => true, 'type' => 'exec', 'message' => "Sorgu çalıştırıldı! Etkilenen: $affected"]);
            }
        } catch(PDOException $e) { echo json_encode(['success' => false, 'message' => 'SQL Hatası: ' . $e->getMessage()]); }
        exit;
    }

    // ======================================================================
    // 🌟 YENİ: GELİŞTİRİCİ STÜDYOSU (IN-BROWSER IDE) İŞLEMLERİ
    // ======================================================================
    if ($_POST['action'] === 'get_plugin_code') {
        if ($_SESSION['admin_id'] != 1) { echo json_encode(['success' => false, 'code' => '']); exit; }
        $modul_kodu = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['modul_kodu']);
        $file_path = $base_path . '/modules/' . $modul_kodu . '.php';
        
        $code = file_exists($file_path) ? file_get_contents($file_path) : "<?php\n// $modul_kodu Modülü - Özel PHP İşleyicisi\n// \$clean_input : Kullanıcının yazdığı temizlenmiş mesaj\n// \$raw_input : Orijinal mesaj\n\nreturn \$this->response('Özel modül çalışıyor!');\n";
        echo json_encode(['success' => true, 'code' => $code]);
        exit;
    }

    if ($_POST['action'] === 'save_plugin_code') {
        if ($_SESSION['admin_id'] != 1) { echo json_encode(['success' => false, 'message' => 'Yetkisiz Erişim!']); exit; }
        $modul_kodu = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['modul_kodu']);
        $php_code = $_POST['php_code'];
        
        if (empty($modul_kodu)) { echo json_encode(['success' => false, 'message' => 'Modül kodu geçersiz.']); exit; }
        
        $plugin_dir = $base_path . '/modules';
        if (!is_dir($plugin_dir)) { mkdir($plugin_dir, 0755, true); }
        
        $file_path = $plugin_dir . '/' . $modul_kodu . '.php';
        
        // Eğer içerik tamamen silindiyse, fiziksel dosyayı da sil ki sistem tekrar veritabanı metnine dönsün
        if (empty(trim($php_code))) {
            if(file_exists($file_path)) unlink($file_path);
            echo json_encode(['success' => true, 'message' => 'Kod silindi. Modül statik (veritabanı) moduna döndü.']);
        } else {
            file_put_contents($file_path, $php_code);
            echo json_encode(['success' => true, 'message' => 'PHP Kodu başarıyla derlendi ve canlıya alındı! 🚀']);
        }
        exit;
    }
}

// ======================================================================
// 3. VERİ ÇEKİMİ
// ======================================================================
$sql = "
    SELECT m.*, COUNT(k.id) as kelime_sayisi 
    FROM bot_moduller m 
    LEFT JOIN bot_anahtar_kelimeler k ON m.id = k.modul_id 
    GROUP BY m.id 
    ORDER BY m.id ASC
";
$moduller = $db->query($sql)->fetchAll();

$page_title = "Yetenek (Modül) Yönetimi";
$active_menu = "moduller";

$extra_css = "
    .search-box { position: relative; width: 100%; max-width: 400px; margin-bottom: 1.5rem; }
    .search-box input { width: 100%; padding: 0.8rem 1rem 0.8rem 2.5rem; background: var(--bg-panel); border: 1px solid var(--border-color); color: white; border-radius: 0.5rem; outline: none; font-size:0.95rem; transition: 0.3s;}
    .search-box input:focus { border-color: var(--accent-color); box-shadow: 0 0 10px rgba(139, 92, 246, 0.2); }
    .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }

    .modul-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .modul-card { background: var(--bg-panel); border-radius: 1rem; border: 1px solid var(--border-color); overflow: hidden; display: flex; flex-direction: column; transition: transform 0.3s, border-color 0.3s; }
    .modul-card:hover { transform: translateY(-5px); border-color: var(--accent-color); box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
    .modul-card.pasif { opacity: 0.7; filter: grayscale(50%); border-style: dashed; }
    
    .m-header { padding: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: flex-start; }
    .m-title-area { flex: 1; }
    .m-title { font-size: 1.2rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.3rem; display: flex; align-items: center; gap: 8px;}
    .m-code { font-size: 0.8rem; font-family: monospace; color: var(--accent-color); background: rgba(139, 92, 246, 0.1); padding: 0.2rem 0.5rem; border-radius: 4px; display: inline-block; }
    
    .m-body { padding: 1.5rem; flex: 1; display: flex; flex-direction: column; gap: 1rem;}
    .m-stat { display: flex; align-items: center; justify-content: space-between; font-size: 0.9rem; background: rgba(255,255,255,0.02); padding: 0.8rem; border-radius: 0.5rem; border: 1px solid var(--border-color); }
    .m-stat-val { font-weight: 700; color: var(--warning-color); font-size: 1.1rem; }
    
    .m-answer { font-size: 0.85rem; color: var(--text-muted); line-height: 1.5; background: var(--bg-main); padding: 1rem; border-radius: 0.5rem; border-left: 3px solid var(--border-color); font-style: italic; position: relative; }
    .m-link-badge { position: absolute; top: -10px; right: 10px; background: var(--accent-color); color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: bold; }
    
    .m-footer { padding: 1rem 1.5rem; background: rgba(0,0,0,0.1); border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;}
    
    .btn-action { background: transparent; border: 1px solid var(--border-color); color: var(--text-main); padding: 0.4rem 0.8rem; border-radius: 0.5rem; cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: 0.3s; display: inline-flex; align-items: center; gap: 6px; }
    .btn-action:hover { background: rgba(255,255,255,0.05); }
    .btn-action.to-pasif { color: var(--warning-color); border-color: rgba(245, 158, 11, 0.3); }
    .btn-action.to-aktif { color: var(--success-color); border-color: rgba(16, 185, 129, 0.3); }
    .btn-action.to-delete { color: var(--danger-color); border-color: transparent; }
    .btn-action.to-delete:hover { background: rgba(239, 68, 68, 0.1); }
    .btn-action.to-teach { color: var(--success-color); border-color: rgba(16, 185, 129, 0.3); }
    .btn-action.to-edit { color: var(--accent-color); border-color: rgba(139, 92, 246, 0.3); }
    .btn-action.to-code { color: #10b981; border-color: rgba(16, 185, 129, 0.3); }

    /* MODALLAR */
    .mistik-modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.8); display: none; align-items: center; justify-content: center; z-index: 9999; opacity: 0; transition: opacity 0.3s ease; }
    .mistik-modal-overlay.active { display: flex; opacity: 1; }
    .mistik-modal-box { background: var(--bg-panel); border: 1px solid var(--border-color); border-radius: 1rem; width: 550px; max-width: 90%; padding: 2rem; box-shadow: 0 10px 40px rgba(0,0,0,0.8); transform: translateY(-20px); transition: transform 0.3s ease; max-height: 90vh; overflow-y: auto;}
    .mistik-modal-overlay.active .mistik-modal-box { transform: translateY(0); }
    
    .sql-terminal { font-family: 'Courier New', Courier, monospace; background: #000; color: #0f0; border: 1px solid #333; padding: 1rem; border-radius: 0.5rem; width: 100%; height: 150px; resize: vertical; outline: none; }
    .sql-result-box { margin-top: 1rem; background: #111; color: #fff; padding: 1rem; font-family: monospace; font-size: 0.85rem; border-radius: 0.5rem; max-height: 200px; overflow-y: auto; display: none; border: 1px solid #333; }
    
    /* IDE EDITOR EKRANI */
    #ideContainer { width: 100%; height: 450px; border-radius: 8px; border: 1px solid var(--border-color); margin-top:10px; }
";

ob_start(); 
?>

<div class="page-header">
    <div>
        <h1>Modüller <span style="color: var(--text-muted); font-weight:400; font-size: 1.2rem;">/ Bot Yetenekleri</span></h1>
        <p style="color: var(--text-muted); margin-top: 5px;">Botun neleri anlayabileceğini ve nasıl davranacağını buradan yönet.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <?php if(isset($_SESSION['admin_id']) && $_SESSION['admin_id'] == 1): ?>
        <button onclick="openSqlModal()" class="btn-action" style="font-size: 1rem; padding: 0.8rem 1.5rem; border-color: var(--warning-color); color: var(--warning-color);">
            <i class="fas fa-terminal"></i> SQL (Root)
        </button>
        <?php endif; ?>

        <button onclick="openModulModal()" class="btn-primary" style="font-size: 1rem; padding: 0.8rem 1.5rem;">
            <i class="fas fa-plus"></i> Yeni Niyet Oluştur
        </button>
    </div>
</div>

<div class="search-box">
    <i class="fas fa-search"></i>
    <input type="text" id="searchInput" onkeyup="searchModules()" placeholder="Modül adı veya kodu ile ara...">
</div>

<div class="modul-grid" id="modulesContainer">
    <?php foreach($moduller as $mod): 
        $is_aktif = $mod['aktif_mi'] == 1;
        $card_class = $is_aktif ? '' : 'pasif';
        
        // 🌟 YENİ: Bu modüle ait fiziksel bir PHP dosyası var mı kontrol et
        $has_plugin = file_exists($base_path . '/modules/' . $mod['modul_kodu'] . '.php');
        
        $cevap_metni = $mod['cevap_metni'] ? htmlspecialchars($mod['cevap_metni']) : '<span style="color:var(--text-muted);">[Metin Yok]</span>';
        $has_link = !empty($mod['link_url']);
    ?>
    <div class="modul-card <?php echo $card_class; ?>">
        <div class="m-header">
            <div class="m-title-area">
                <div class="m-title">
                    <i class="fas <?php echo $is_aktif ? 'fa-brain' : 'fa-power-off'; ?>"></i> 
                    <span class="searchable-title"><?php echo htmlspecialchars($mod['modul_adi']); ?></span>
                </div>
                <div class="m-code searchable-code">
                    <?php if($has_plugin): ?>
                        <span class="badge" style="background:rgba(139,92,246,0.2); color:var(--accent-color); margin-right:5px;" title="Bu modül dinamik PHP kodu ile çalışır."><i class="fas fa-bolt"></i> PHP</span>
                    <?php endif; ?>
                    _<?php echo htmlspecialchars($mod['modul_kodu']); ?>
                </div>
            </div>
            <div>
                <?php if($is_aktif): ?><span class="badge success">Aktif</span><?php else: ?><span class="badge danger">Pasif</span><?php endif; ?>
            </div>
        </div>
        
        <div class="m-body">
            <div class="m-stat">
                <span><i class="fas fa-graduation-cap" style="color: var(--text-muted);"></i> Öğrenilen Zeka:</span>
                <span class="m-stat-val"><?php echo $mod['kelime_sayisi']; ?> kelime</span>
            </div>
            
            <div style="display:flex; flex-direction:column; gap: 5px;">
                <span style="font-size:0.8rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Botun Varsayılan Aksiyonu:</span>
                <div class="m-answer" <?php echo $has_plugin ? 'style="opacity:0.5;"' : ''; ?>>
                    <?php if($has_link): ?> <span class="m-link-badge"><i class="fas fa-link"></i> Linkli</span> <?php endif; ?>
                    <?php echo $cevap_metni; ?>
                    <?php if($has_plugin): ?><br><small style="color:var(--accent-color); font-weight:bold;">(Bu metin yerine özel PHP kodu devreye girecektir)</small><?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="m-footer">
            <span style="font-size: 0.8rem; color: var(--text-muted);">ID: #<?php echo $mod['id']; ?></span>
            
            <div style="display: flex; gap: 6px; align-items: center;">
                
                <?php if(isset($_SESSION['admin_id']) && $_SESSION['admin_id'] == 1): ?>
                <button class="btn-action to-code" title="Geliştirici Stüdyosu (IDE)" onclick="openIdeModal('<?php echo htmlspecialchars($mod['modul_kodu'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($mod['modul_adi'], ENT_QUOTES); ?>')">
                    <i class="fas fa-code"></i>
                </button>
                <?php endif; ?>

                <button class="btn-action to-edit" title="Metinleri Düzenle"
                    data-id="<?php echo $mod['id']; ?>"
                    data-adi="<?php echo htmlspecialchars($mod['modul_adi'], ENT_QUOTES); ?>"
                    data-kodu="<?php echo htmlspecialchars($mod['modul_kodu'], ENT_QUOTES); ?>"
                    data-cevap="<?php echo htmlspecialchars($mod['cevap_metni'] ?? '', ENT_QUOTES); ?>"
                    data-buton="<?php echo htmlspecialchars($mod['buton_metni'] ?? '', ENT_QUOTES); ?>"
                    data-link="<?php echo htmlspecialchars($mod['link_url'] ?? '', ENT_QUOTES); ?>"
                    onclick="openEditModal(this)">
                    <i class="fas fa-edit"></i>
                </button>

                <a href="zeka?id=<?php echo $mod['id']; ?>" class="btn-action to-teach" title="Zeka Laboratuvarı" style="text-decoration:none;">
                    <i class="fas fa-microchip"></i> Lab
                </a>
                
                <?php if($is_aktif): ?>
                    <button onclick="toggleStatus(<?php echo $mod['id']; ?>, 0)" class="btn-action to-pasif" title="Devre dışı bırak"><i class="fas fa-pause"></i></button>
                <?php else: ?>
                    <button onclick="toggleStatus(<?php echo $mod['id']; ?>, 1)" class="btn-action to-aktif" title="Aktifleştir"><i class="fas fa-play"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php 
$page_content = ob_get_clean();

// ======================================================================
// 5. GİZLİ MODALLAR VE JS
// ======================================================================
$extra_js = "
<script src=\"https://cdnjs.cloudflare.com/ajax/libs/ace/1.36.2/ace.js\"></script>

<div class=\"mistik-modal-overlay\" id=\"modulModal\">
    <div class=\"mistik-modal-box\">
        <div class=\"modal-header\" style=\"display:flex; justify-content:space-between; margin-bottom:1rem;\">
            <h2 style=\"font-size: 1.2rem; color: var(--accent-color);\"><i class=\"fas fa-magic\"></i> Yeni Niyet (Modül) Ekle</h2>
            <button onclick=\"closeModal('modulModal')\" style=\"background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;\"><i class=\"fas fa-times\"></i></button>
        </div>
        <form id=\"modulForm\" onsubmit=\"submitModulForm(event, 'modul_ekle', 'modulForm', 'modulFormMsg')\">
            <input type=\"hidden\" name=\"action\" value=\"modul_ekle\">
            <div style=\"margin-bottom: 1.2rem;\"><label style=\"display:block; margin-bottom:0.3rem; color:white;\">1. Modül Adı</label><input type=\"text\" name=\"modul_adi\" required style=\"width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:white; border-radius:0.5rem; outline:none;\"></div>
            <div style=\"margin-bottom: 1.2rem;\"><label style=\"display:block; margin-bottom:0.3rem; color:white;\">2. Modül Kodu</label><input type=\"text\" name=\"modul_kodu\" required style=\"width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:var(--accent-color); font-family:monospace; border-radius:0.5rem; outline:none;\"></div>
            <div style=\"margin-bottom: 1.2rem;\"><label style=\"display:block; margin-bottom:0.3rem; color:white;\">3. Botun Metin Cevabı</label><textarea name=\"cevap_metni\" rows=\"2\" style=\"width:100%; padding:0.8rem; background:var(--bg-main); border:1px solid var(--border-color); color:white; border-radius:0.5rem; outline:none;\"></textarea></div>
            <button type=\"submit\" style=\"width:100%; padding:1rem; background:var(--accent-color); color:white; border:none; border-radius:0.5rem; font-weight:bold; cursor:pointer;\">Modülü Ekle</button>
            <div id=\"modulFormMsg\" style=\"margin-top: 1rem; text-align: center;\"></div>
        </form>
    </div>
</div>

<div class=\"mistik-modal-overlay\" id=\"editModal\">
    <div class=\"mistik-modal-box\">
        <div class=\"modal-header\" style=\"display:flex; justify-content:space-between; margin-bottom:1rem;\">
            <h2 style=\"font-size: 1.2rem; color: var(--warning-color);\"><i class=\"fas fa-edit\"></i> Modülü Düzenle</h2>
            <button onclick=\"closeModal('editModal')\" style=\"background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;\"><i class=\"fas fa-times\"></i></button>
        </div>
        <form id=\"editForm\" onsubmit=\"submitModulForm(event, 'modul_duzenle', 'editForm', 'editFormMsg')\">
            <input type=\"hidden\" name=\"action\" value=\"modul_duzenle\">
            <input type=\"hidden\" name=\"modul_id\" id=\"editId\">
            <div style=\"margin-bottom: 1.2rem;\"><label style=\"display:block; color:white;\">Modül Adı</label><input type=\"text\" name=\"modul_adi\" id=\"editAdi\" required style=\"width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:white; border-radius:0.5rem;\"></div>
            <div style=\"margin-bottom: 1.2rem;\"><label style=\"display:block; color:white;\">Modül Kodu</label><input type=\"text\" name=\"modul_kodu\" id=\"editKodu\" required style=\"width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:var(--warning-color); font-family:monospace; border-radius:0.5rem;\"></div>
            <div style=\"margin-bottom: 1.2rem;\"><label style=\"display:block; color:white;\">Botun Metin Cevabı</label><textarea name=\"cevap_metni\" id=\"editCevap\" rows=\"3\" style=\"width:100%; padding:0.8rem; background:var(--bg-main); border:1px solid var(--border-color); color:white; border-radius:0.5rem;\"></textarea></div>
            <button type=\"submit\" style=\"width:100%; padding:1rem; background:var(--warning-color); color:#000; border:none; border-radius:0.5rem; font-weight:bold; cursor:pointer;\">Kaydet</button>
            <div id=\"editFormMsg\" style=\"margin-top: 1rem; text-align: center;\"></div>
        </form>
    </div>
</div>

<div class=\"mistik-modal-overlay\" id=\"ideModal\">
    <div class=\"mistik-modal-box\" style=\"width:900px; max-width:95%;\">
        <div class=\"modal-header\" style=\"display:flex; justify-content:space-between; margin-bottom:0.5rem;\">
            <h2 style=\"font-size: 1.2rem; color: #10b981;\"><i class=\"fas fa-code\"></i> Geliştirici Stüdyosu: <span id=\"ideTitleName\" style=\"color:white;\"></span></h2>
            <button onclick=\"closeModal('ideModal')\" style=\"background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;\"><i class=\"fas fa-times\"></i></button>
        </div>
        <p style=\"color:var(--text-muted); font-size:0.85rem; margin-bottom:10px;\">Bu alana yazacağınız PHP kodları motor (api.php) tarafından çalıştırılacaktır. Değişkenler: <code>\$clean_input</code> ve <code>\$raw_input</code>. İşlemi silmek için içeriği tamamen boşaltıp kaydedin.</p>
        
        <input type=\"hidden\" id=\"ideModulKodu\">
        <div id=\"ideContainer\"></div>
        
        <button id=\"btnSaveIde\" onclick=\"saveIdeCode()\" style=\"width:100%; padding:1rem; margin-top:15px; background:#10b981; color:#000; border:none; border-radius:0.5rem; font-weight:bold; cursor:pointer; font-size:1rem;\">
            <i class=\"fas fa-rocket\"></i> Kodu Derle ve Canlıya Al
        </button>
    </div>
</div>

<div class=\"mistik-modal-overlay\" id=\"sqlModal\">
    <div class=\"mistik-modal-box\" style=\"width:700px;\">
        <div class=\"modal-header\" style=\"display:flex; justify-content:space-between; margin-bottom:1rem;\"><h2 style=\"color:var(--warning-color);\"><i class=\"fas fa-terminal\"></i> SQL Terminali</h2><button onclick=\"closeModal('sqlModal')\" style=\"background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;\"><i class=\"fas fa-times\"></i></button></div>
        <form id=\"sqlForm\" onsubmit=\"submitSqlForm(event)\">
            <input type=\"hidden\" name=\"action\" value=\"execute_sql\">
            <textarea name=\"sql_query\" class=\"sql-terminal\" placeholder=\"-- Kodlar buraya\"></textarea>
            <button type=\"submit\" style=\"width:100%; padding:1rem; background:var(--warning-color); color:#000; border:none; border-radius:0.5rem; font-weight:bold; cursor:pointer; margin-top:1rem;\">Çalıştır</button>
            <pre id=\"sqlResult\" class=\"sql-result-box\"></pre>
        </form>
    </div>
</div>

<script>
    // Arama
    function searchModules() {
        let input = document.getElementById('searchInput').value.toLowerCase();
        let cards = document.querySelectorAll('.modul-card');
        cards.forEach(card => {
            let title = card.querySelector('.searchable-title').innerText.toLowerCase();
            let code = card.querySelector('.searchable-code').innerText.toLowerCase();
            if(title.includes(input) || code.includes(input)) { card.style.display = 'flex'; } else { card.style.display = 'none'; }
        });
    }

    // Modal Yönetimi
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    window.onclick = function(e) { if (e.target.classList.contains('mistik-modal-overlay')) { e.target.classList.remove('active'); } }
    function openModulModal() { document.getElementById('modulForm').reset(); document.getElementById('modulFormMsg').innerHTML = ''; document.getElementById('modulModal').classList.add('active'); }
    function openSqlModal() { document.getElementById('sqlResult').style.display = 'none'; document.getElementById('sqlModal').classList.add('active'); }

    function openEditModal(btn) {
        document.getElementById('editFormMsg').innerHTML = '';
        document.getElementById('editId').value = btn.getAttribute('data-id');
        document.getElementById('editAdi').value = btn.getAttribute('data-adi');
        document.getElementById('editKodu').value = btn.getAttribute('data-kodu');
        document.getElementById('editCevap').value = btn.getAttribute('data-cevap');
        document.getElementById('editModal').classList.add('active');
    }

    async function submitModulForm(e, actionType, formId, msgId) {
        e.preventDefault(); 
        const form = document.getElementById(formId); const msg = document.getElementById(msgId);
        const btn = form.querySelector('button[type=\"submit\"]'); const originalText = btn.innerHTML;
        btn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> İşleniyor...'; btn.disabled = true;
        try {
            const res = await fetch('modul', { method: 'POST', body: new FormData(form) });
            const data = await res.json();
            if(data.success) { msg.innerHTML = `<span style=\"color: var(--success-color);\"> \${data.message}</span>`; setTimeout(() => location.reload(), 1000); } 
            else { msg.innerHTML = `<span style=\"color: var(--danger-color);\"> \${data.message}</span>`; btn.innerHTML = originalText; btn.disabled = false; }
        } catch (err) { msg.innerHTML = 'Sunucu Hatası!'; btn.innerHTML = originalText; btn.disabled = false; }
    }

    async function toggleStatus(modul_id, yeni_durum) {
        if(!confirm(yeni_durum === 0 ? 'Devre dışı bırakılsın mı?' : 'Aktif edilsin mi?')) return;
        const fd = new FormData(); fd.append('action', 'toggle_status'); fd.append('modul_id', modul_id); fd.append('yeni_durum', yeni_durum);
        const res = await fetch('modul', { method: 'POST', body: fd }); const data = await res.json(); if(data.success) location.reload();
    }

    async function deleteModul(modul_id) {
        if(prompt('Bu modülü KALICI OLARAK silmek için \"SİL\" yazın:') !== 'SİL') return;
        const fd = new FormData(); fd.append('action', 'modul_sil'); fd.append('modul_id', modul_id);
        const res = await fetch('modul', { method: 'POST', body: fd }); const data = await res.json(); if(data.success) location.reload(); else alert(data.message);
    }

    async function submitSqlForm(e) {
        e.preventDefault(); 
        const res = await fetch('modul', { method: 'POST', body: new FormData(e.target) }); const data = await res.json(); 
        const rb = document.getElementById('sqlResult');
        if(data.success && data.type === 'select') { rb.innerHTML = JSON.stringify(data.data, null, 2); rb.style.display = 'block'; } else { alert(data.message); }
    }

    // 🌟 YENİ: IDE SİSTEMİ JS KODLARI
    let editor;
    
    // Sayfa yüklendiğinde Ace Editor'u hazırla
    window.onload = function() {
        if(typeof ace !== 'undefined') {
            editor = ace.edit(\"ideContainer\");
            editor.setTheme(\"ace/theme/monokai\");
            editor.session.setMode(\"ace/mode/php\");
            editor.setOptions({ fontSize: \"14px\", showPrintMargin: false, enableBasicAutocompletion: true });
        }
    };

    async function openIdeModal(modul_kodu, modul_adi) {
        document.getElementById('ideTitleName').innerText = modul_adi + ' (' + modul_kodu + '.php)';
        document.getElementById('ideModulKodu').value = modul_kodu;
        
        // Önce kodu sunucudan çekelim
        const fd = new FormData();
        fd.append('action', 'get_plugin_code');
        fd.append('modul_kodu', modul_kodu);
        
        editor.setValue(\"// Kodlar sunucudan yükleniyor, lütfen bekleyin...\", -1);
        document.getElementById('ideModal').classList.add('active');

        try {
            const res = await fetch('modul', { method: 'POST', body: fd });
            const data = await res.json();
            if(data.success) {
                editor.setValue(data.code, -1); // -1 imleci en üste koyar
            } else {
                editor.setValue(\"<?php\\n// Hata oluştu veya yetkiniz yok.\\n\", -1);
            }
        } catch (e) {
            editor.setValue(\"<?php\\n// Bağlantı hatası!\\n\", -1);
        }
    }

    async function saveIdeCode() {
        const btn = document.getElementById('btnSaveIde');
        const code = editor.getValue();
        const modul_kodu = document.getElementById('ideModulKodu').value;
        const originalText = btn.innerHTML;

        btn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> Sunucuya Yazılıyor...';
        btn.disabled = true;

        const fd = new FormData();
        fd.append('action', 'save_plugin_code');
        fd.append('modul_kodu', modul_kodu);
        fd.append('php_code', code);

        try {
            const res = await fetch('modul', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if(data.success) {
                location.reload(); // Rozetlerin (⚡) güncellenmesi için sayfayı yenile
            }
        } catch (e) {
            alert(\"Sunucu ile iletişim kurulamadı.\");
        } finally {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }
</script>
";

// YOL DÜZELTİLDİ: Mutlak Yol (Absolute)
$layout_path = $base_path . '/admin/layouts/master_layouts.php';
if (file_exists($layout_path)) {
    require_once $layout_path; 
} else {
    echo "HATA: Layout dosyası bulunamadı: " . $layout_path;
}
?>
