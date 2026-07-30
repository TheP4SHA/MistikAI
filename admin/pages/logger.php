<?php
// /data/admin/front/logger.php - Blackbox V2.2 (Sanal Niyet ve Saf Niyet Destekli)

// GÜVENLİK DUVARI: Oturum yoksa logine şutla!
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_id'])) {
    header("Location: /OmyoWeb/MistikAI/admin/login");
    exit;
}

// 1. VERİTABANI BAĞLANTISI
$doc_root = $_SERVER['DOCUMENT_ROOT']; 
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
        die("Kritik Hata: Veritabanı bağlantısı kurulamadı. " . $e->getMessage());
    }
}
global $db;

// ======================================================================
// 2. AJAX İŞLEM YAKALAYICI
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ogret') {
    header('Content-Type: application/json; charset=utf-8');
    
    $kelimeler_raw = $_POST['kelime'];
    $kelimeler_dizisi = array_map('trim', explode(',', $kelimeler_raw));
    
    $modul_id = (int)$_POST['modul_id'];
    $override = trim($_POST['cevap_override']); 
    $oncelik = 50; 

    if (!empty($kelimeler_dizisi) && $modul_id > 0) {
        try {
            $eklenen = 0;
            $esgecilen = 0;

            foreach($kelimeler_dizisi as $k) {
                $k = mb_strtolower($k, 'UTF-8');
                if(empty($k)) continue;

                $check = $db->prepare("SELECT id FROM bot_anahtar_kelimeler WHERE kelime = :k AND modul_id = :m");
                $check->execute([':k' => $k, ':m' => $modul_id]);
                
                if ($check->rowCount() > 0) {
                    $esgecilen++;
                    continue; 
                }
                
                $insert = $db->prepare("INSERT INTO bot_anahtar_kelimeler (modul_id, kelime, oncelik, cevap_override, aktif_mi) VALUES (:m, :k, :o, :c, 1)");
                $insert->execute([':m' => $modul_id, ':k' => $k, ':o' => $oncelik, ':c' => empty($override) ? null : $override]);
                $eklenen++;

                $update_logs = $db->prepare("UPDATE bot_sohbet_loglari SET anlasildi_mi = 1, eslesen_modul_id = :m WHERE kullanici_mesaji = :k AND anlasildi_mi = 0");
                $update_logs->execute([':m' => $modul_id, ':k' => $k]);
            }

            if ($eklenen > 0) {
                echo json_encode(['success' => true, 'message' => "$eklenen kelime başarıyla öğretildi! ($esgecilen zaten vardı)"]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Yazdığınız kelimeler zaten bu modülde kayıtlı!']);
            }
        } catch(PDOException $e) { 
            echo json_encode(['success' => false, 'message' => 'DB Hatası: ' . $e->getMessage()]); 
        }
    } else { 
        echo json_encode(['success' => false, 'message' => 'Kelime ve Modül alanları zorunludur.']); 
    }
    exit;
}

// ======================================================================
// 3. FİLTRELEME VE SAYFALAMA
// ======================================================================
$limit = 100; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_search = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter_session = isset($_GET['sess']) ? trim($_GET['sess']) : '';

$where_clauses = ["1=1"];
$params = [];

if ($filter_status === 'success') { $where_clauses[] = "l.anlasildi_mi = 1"; } 
elseif ($filter_status === 'fail') { $where_clauses[] = "l.anlasildi_mi = 0"; }

if (!empty($filter_search)) {
    $where_clauses[] = "l.kullanici_mesaji LIKE :q";
    $params[':q'] = "%{$filter_search}%";
}

if (!empty($filter_session)) {
    $where_clauses[] = "l.session_id = :sess";
    $params[':sess'] = $filter_session;
}

$where_sql = implode(' AND ', $where_clauses);

$data_sql = "
    SELECT l.*, m.modul_adi 
    FROM bot_sohbet_loglari l 
    LEFT JOIN bot_moduller m ON l.eslesen_modul_id = m.id 
    WHERE $where_sql 
    ORDER BY l.islem_tarihi DESC 
    LIMIT $limit OFFSET $offset
";
$stmt_data = $db->prepare($data_sql);
$stmt_data->execute($params);
$raw_loglar = $stmt_data->fetchAll();

// 🌟 IP ADRESİNE GÖRE GRUPLAMA
$grouped_logs = [];
foreach($raw_loglar as $log) {
    $ip = $log['session_id']; 
    if (!isset($grouped_logs[$ip])) {
        $grouped_logs[$ip] = [];
    }
    $grouped_logs[$ip][] = $log;
}

$tum_moduller = $db->query("SELECT id, modul_adi FROM bot_moduller WHERE aktif_mi = 1")->fetchAll();

// ======================================================================
// 4. SAYFA BİLGİLERİ VE CSS
// ======================================================================
$page_title = "Log Analizi ve Blackbox";
$active_menu = "logger";

$extra_css = "
    .filter-bar { background: var(--bg-panel); padding: 1rem 1.5rem; border-radius: 1rem; border: 1px solid var(--border-color); display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; }
    .filter-group { display: flex; align-items: center; gap: 0.5rem; flex: 1; min-width: 200px; }
    .filter-input, .filter-select { width: 100%; padding: 0.6rem 1rem; background: var(--bg-input); border: 1px solid var(--border-color); color: white; border-radius: 0.5rem; outline: none; }
    
    .table-wrapper { background: var(--bg-panel); border-radius: 1rem; border: 1px solid var(--border-color); overflow-x: auto; margin-bottom: 1.5rem; }
    table { width: 100%; min-width: 900px; border-collapse: collapse; text-align: left; }
    th { background: rgba(0,0,0,0.2); padding: 1rem; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; border-bottom: 1px solid var(--border-color); }
    td { padding: 1rem; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; vertical-align: top; }
    
    .main-row { transition: background 0.3s; }
    .main-row:hover { background: rgba(255,255,255,0.03); }
    .row-fail { background: rgba(239, 68, 68, 0.05); }
    
    .sub-row { display: none; background: rgba(0,0,0,0.15); }
    .sub-row.open { display: table-row; }
    .sub-row td { border-bottom: 1px dashed rgba(255,255,255,0.05); padding-left: 2rem; }
    
    .ip-badge { background: rgba(255,255,255,0.05); padding: 0.3rem 0.6rem; border-radius: 6px; font-family: monospace; font-size: 0.85rem; color: #a78bfa; border: 1px solid rgba(167, 139, 250, 0.2); }
    .toggle-btn { background: var(--bg-input); color: white; border: 1px solid var(--border-color); padding: 0.2rem 0.6rem; border-radius: 4px; cursor: pointer; font-size: 0.8rem; margin-top: 5px; transition: 0.2s; }
    .toggle-btn:hover { background: var(--accent-color); border-color: var(--accent-color); }

    .chat-user { font-weight: 600; font-size: 0.95rem; color: #f8fafc; margin-bottom: 0.4rem; }
    .chat-bot { font-size: 0.85rem; color: #cbd5e1; background: rgba(255,255,255,0.03); padding: 0.6rem; border-radius: 0.5rem; border-left: 3px solid var(--accent-color); max-height: 80px; overflow-y: auto; }
    
    .mistik-modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.8); display: none; align-items: center; justify-content: center; z-index: 9999; opacity: 0; transition: opacity 0.3s ease; }
    .mistik-modal-overlay.active { display: flex; opacity: 1; }
    .mistik-modal-box { background: var(--bg-panel); border: 1px solid var(--border-color); border-radius: 1rem; width: 500px; max-width: 90%; padding: 2rem; box-shadow: 0 10px 40px rgba(0,0,0,0.8); transform: translateY(-20px); transition: transform 0.3s ease; }
    .mistik-modal-overlay.active .mistik-modal-box { transform: translateY(0); }
    .form-text { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem; display: block; }
";

ob_start(); 
?>

<div class="page-header">
    <div>
        <h1>Blackbox <span style="color: var(--text-muted); font-weight:400; font-size: 1.2rem;">/ Derin Log Analizi</span></h1>
        <p style="color: var(--text-muted); margin-top: 5px;">IP tabanlı derin analiz ve yapay zeka eğitim modülü.</p>
    </div>
    <div>
        <button onclick="openManuelModal()" class="btn-primary" style="font-size: 1rem; padding: 0.8rem 1.5rem;">
            <i class="fas fa-plus"></i> Manuel Öğret
        </button>
    </div>
</div>

<form method="GET" class="filter-bar" action="logger">
    <div class="filter-group" style="flex: 0 0 150px;">
        <select name="status" class="filter-select">
            <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>Tüm Loglar</option>
            <option value="success" <?php echo $filter_status == 'success' ? 'selected' : ''; ?>>✅ Anlaşılanlar</option>
            <option value="fail" <?php echo $filter_status == 'fail' ? 'selected' : ''; ?>>❌ Kör Noktalar</option>
        </select>
    </div>
    <div class="filter-group">
        <i class="fas fa-search" style="color: var(--text-muted); position:absolute; margin-left:10px;"></i>
        <input type="text" name="q" class="filter-input" placeholder="Kelime veya cümle ara..." value="<?php echo htmlspecialchars($filter_search); ?>" style="padding-left: 35px;">
    </div>
    <?php if(!empty($filter_session)): ?>
    <div class="filter-group" style="flex: 0 0 auto;">
        <span class="badge warning"><i class="fas fa-network-wired"></i> Filtrelenen IP: <?php echo htmlspecialchars($filter_session); ?> <a href="logger" style="color:white; margin-left:5px;"><i class="fas fa-times"></i></a></span>
        <input type="hidden" name="sess" value="<?php echo htmlspecialchars($filter_session); ?>">
    </div>
    <?php endif; ?>
    <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Filtrele</button>
</form>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th width="140">Tarih / Saat</th>
                <th width="150">IP Adresi</th>
                <th>Yazışma (Kullanıcı & Bot)</th>
                <th width="160">Niyet & Güven</th>
                <th width="120">Aksiyon</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($grouped_logs)): ?>
                <tr><td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-muted);">Log bulunamadı.</td></tr>
            <?php else: ?>
                <?php 
                if (!function_exists('renderLogRow')) {
                    function renderLogRow($log, $is_main, $group_id, $log_count = 1) {
                        $is_success = $log['anlasildi_mi'] == 1;
                        $saf_mesaj = htmlspecialchars($log['kullanici_mesaji'], ENT_QUOTES);
                        $bot_cevabi = isset($log['bot_cevabi']) ? htmlspecialchars(strip_tags($log['bot_cevabi'])) : 'Cevap loglanmadı.';
                        if(mb_strlen($bot_cevabi) > 120) $bot_cevabi = mb_substr($bot_cevabi, 0, 120) . '...';
                        
                        $row_class = $is_main ? "main-row" : "sub-row sub-group-{$group_id}";
                        if(!$is_success) $row_class .= " row-fail";
                        
                        echo "<tr class=\"{$row_class}\">";
                        
                        // 1. Tarih
                        echo "<td>";
                        echo "<div style=\"font-weight:500;\">" . date('d.m.Y', strtotime($log['islem_tarihi'])) . "</div>";
                        echo "<div style=\"font-size:0.8rem; color:var(--text-muted);\"><i class=\"far fa-clock\"></i> " . date('H:i:s', strtotime($log['islem_tarihi'])) . "</div>";
                        echo "</td>";
                        
                        // 2. IP Adresi
                        echo "<td>";
                        if($is_main) {
                            echo "<a href=\"?sess=" . urlencode($log['session_id']) . "\" class=\"ip-badge\"><i class=\"fas fa-globe\"></i> " . htmlspecialchars($log['session_id']) . "</a>";
                            if($log_count > 1) {
                                echo "<div style=\"margin-top:8px;\">";
                                echo "<button type=\"button\" class=\"toggle-btn\" onclick=\"toggleGroup({$group_id})\"><i class=\"fas fa-chevron-down\" id=\"icon-{$group_id}\"></i> +" . ($log_count - 1) . " Geçmiş</button>";
                                echo "</div>";
                            }
                        } else {
                            echo "<span style=\"color:var(--text-muted); opacity:0.5;\"><i class=\"fas fa-level-up-alt fa-rotate-90\"></i> Önceki</span>";
                        }
                        echo "</td>";
                        
                        // 3. Yazışma Bloğu
                        echo "<td>";
                        echo "<div class=\"chat-user\">\"{$saf_mesaj}\"</div>";
                        echo "<div class=\"chat-bot\"><i class=\"fas fa-robot\"></i> {$bot_cevabi}</div>";
                        echo "</td>";
                        
                        // 4. Niyet ve Güven (GÜNCELLENDİ)
                        echo "<td>";
                        if($is_success) {
                            // Modül adı var mı? Yoksa algilanan_niyet (Sanal niyet) var mı? 
                            if(!empty($log['modul_adi'])) {
                                $gosterilecek_isim = $log['modul_adi'];
                            } elseif (!empty($log['algilanan_niyet']) && $log['algilanan_niyet'] !== 'bilinmiyor') {
                                // "argo_filtresi" yazısını "Argo Filtresi" şeklinde düzenle
                                $gosterilecek_isim = ucwords(str_replace('_', ' ', $log['algilanan_niyet']));
                            } else {
                                $gosterilecek_isim = "Genel Sohbet"; 
                            }
                            
                            echo "<div style=\"color: var(--accent-color); font-weight:500; margin-bottom:5px; font-size:0.9rem;\"><i class=\"fas fa-check-circle\"></i> " . htmlspecialchars($gosterilecek_isim) . "</div>";
                        } else {
                            // Cidden bulunamadıysa ama BERT bir niyet fırlattıysa onu göster
                            $hata_mesaji = (!empty($log['algilanan_niyet']) && $log['algilanan_niyet'] !== 'bilinmiyor') 
                                ? "Modül Yok (" . htmlspecialchars($log['algilanan_niyet']) . ")" 
                                : "Bulunamadı";
                            
                            echo "<div style=\"color: var(--danger-color); font-size:0.85rem; margin-bottom:5px;\"><i class=\"fas fa-exclamation-triangle\"></i> {$hata_mesaji}</div>";
                        }
                        
                        if($log['guven_skoru'] >= 70) echo '<span class="badge success">%'.$log['guven_skoru'].'</span>';
                        elseif($log['guven_skoru'] >= 30) echo '<span class="badge warning">%'.$log['guven_skoru'].'</span>';
                        else echo '<span class="badge danger">%'.$log['guven_skoru'].'</span>';
                        echo "</td>";
                        
                        // 5. Aksiyon
                        echo "<td>";
                        if(!$is_success) {
                            echo "<button onclick=\"openLogModal('{$saf_mesaj}')\" class=\"btn-primary\" style=\"padding: 0.4rem 0.8rem; font-size: 0.8rem;\"><i class=\"fas fa-graduation-cap\"></i> Öğret</button>";
                        } else {
                            echo "<button onclick=\"openLogModal('{$saf_mesaj}')\" class=\"btn-outline\" style=\"padding: 0.4rem 0.8rem; font-size: 0.8rem;\"><i class=\"fas fa-edit\"></i> Düzelt</button>";
                        }
                        echo "</td>";
                        
                        echo "</tr>";
                    }
                }

                $group_id = 0;
                foreach($grouped_logs as $ip => $logs): 
                    $group_id++;
                    $latest_log = $logs[0]; 
                    $log_count = count($logs);
                    
                    // Ana Satırı (En Günceli) Bas
                    renderLogRow($latest_log, true, $group_id, $log_count);
                    
                    // Alt Satırları (Varsa Geçmişi) Bas
                    if($log_count > 1) {
                        for($i = 1; $i < $log_count; $i++) {
                            renderLogRow($logs[$i], false, $group_id, 1);
                        }
                    }
                endforeach; 
                ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php 
$page_content = ob_get_clean();

// ======================================================================
// 5. GİZLİ EĞİTİM MODALI VE GRUP AÇ/KAPA JS
// ======================================================================
$extra_js = "
<div class=\"mistik-modal-overlay\" id=\"ogretModal\">
    <div class=\"mistik-modal-box\">
        <div class=\"modal-header\" style=\"display:flex; justify-content:space-between; margin-bottom:1.5rem;\">
            <h2 id=\"modalTitle\" style=\"font-size: 1.2rem; color: var(--accent-color);\"><i class=\"fas fa-microchip\"></i> Bota Kelime Öğret</h2>
            <button onclick=\"closeModal()\" style=\"background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;\"><i class=\"fas fa-times\"></i></button>
        </div>
        <form id=\"ogretForm\" onsubmit=\"submitOgretForm(event)\">
            <input type=\"hidden\" name=\"action\" value=\"ogret\">
            
            <div class=\"form-group\" style=\"margin-bottom: 1.2rem;\">
                <label style=\"display:block; margin-bottom:0.5rem; color:var(--text-muted);\">Öğretilecek Kelimeler / Cümleler</label>
                <textarea id=\"modalKelime\" name=\"kelime\" required rows=\"3\" style=\"width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:white; border-radius:0.5rem; outline:none; font-family:monospace; resize:none;\" placeholder=\"Örn: vizeler ne zaman, sınavlar, ara sınav\"></textarea>
                <span class=\"form-text\">Birden fazla kelime eklemek için aralarına <b>virgül (,)</b> koyun.</span>
            </div>
            
            <div class=\"form-group\" style=\"margin-bottom: 1.2rem;\">
                <label style=\"display:block; margin-bottom:0.5rem; color:var(--text-muted);\">Hangi Modüle (Niyete) Bağlansın?</label>
                <select name=\"modul_id\" required style=\"width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:white; border-radius:0.5rem; outline:none;\">
                    <option value=\"\">-- Modül Seçiniz --</option>";
                    foreach($tum_moduller as $mod) {
                        $extra_js .= "<option value=\"{$mod['id']}\">" . htmlspecialchars($mod['modul_adi']) . "</option>";
                    }
$extra_js .= "
                </select>
            </div>

            <div class=\"form-group\" style=\"margin-bottom: 1.2rem; background: rgba(139, 92, 246, 0.05); padding: 1rem; border-radius: 0.5rem; border: 1px dashed var(--accent-color);\">
                <label style=\"display:block; margin-bottom:0.5rem; color:var(--accent-color);\"><i class=\"fas fa-bolt\"></i> Özel Cevap (Override - Opsiyonel)</label>
                <textarea name=\"cevap_override\" rows=\"2\" style=\"width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:white; border-radius:0.5rem; outline:none; resize:none;\" placeholder=\"Bu kelime yazıldığında verilecek özel cevap...\"></textarea>
            </div>
            
            <button type=\"submit\" id=\"btnKaydet\" style=\"width:100%; padding:1rem; background:var(--accent-color); color:white; border:none; border-radius:0.5rem; font-weight:bold; cursor:pointer;\"><i class=\"fas fa-upload\"></i> Zekayı Güncelle</button>
            <div id=\"ogretFormMsg\" style=\"margin-top: 1rem; text-align: center; font-size: 0.95rem;\"></div>
        </form>
    </div>
</div>

<script>
    function toggleGroup(groupId) {
        const rows = document.querySelectorAll('.sub-group-' + groupId);
        const icon = document.getElementById('icon-' + groupId);
        
        rows.forEach(row => {
            row.classList.toggle('open');
        });
        
        if(icon) {
            icon.classList.toggle('fa-chevron-down');
            icon.classList.toggle('fa-chevron-up');
        }
    }

    const modal = document.getElementById('ogretModal');
    const inputKelimeler = document.getElementById('modalKelime');
    const formMsg = document.getElementById('ogretFormMsg');
    const modalTitle = document.getElementById('modalTitle');

    function openLogModal(kelime) {
        modalTitle.innerHTML = '<i class=\"fas fa-microchip\"></i> Logdan Zeka Öğret';
        inputKelimeler.value = kelime;
        formMsg.innerHTML = '';
        modal.classList.add('active');
    }

    function openManuelModal() {
        modalTitle.innerHTML = '<i class=\"fas fa-plus\"></i> Çoklu Kelime / Örüntü Ekle';
        inputKelimeler.value = ''; 
        formMsg.innerHTML = '';
        modal.classList.add('active');
        inputKelimeler.focus();
    }

    function closeModal() {
        modal.classList.remove('active');
    }

    window.onclick = function(event) {
        if (event.target == modal) { closeModal(); }
    }

    async function submitOgretForm(e) {
        e.preventDefault();
        const form = document.getElementById('ogretForm');
        const btn = document.getElementById('btnKaydet');
        const originalText = btn.innerHTML;
        
        btn.innerHTML = '<i class=\"fas fa-circle-notch fa-spin\"></i> Kaydediliyor...';
        btn.disabled = true;
        
        try {
            const response = await fetch('logger', { method: 'POST', body: new FormData(form) });
            const data = await response.json();
            
            if(data.success) {
                formMsg.innerHTML = `<span style=\"color: var(--success-color);\"><i class=\"fas fa-check-circle\"></i> \${data.message}</span>`;
                setTimeout(() => location.reload(), 1500); 
            } else {
                formMsg.innerHTML = `<span style=\"color: var(--danger-color);\"><i class=\"fas fa-exclamation-triangle\"></i> \${data.message}</span>`;
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        } catch (error) {
            formMsg.innerHTML = `<span style=\"color: var(--danger-color);\"><i class=\"fas fa-exclamation-triangle\"></i> Sunucu hatası!</span>`;
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }
</script>
";

$layout_path = $base_path . '/admin/layouts/master_layouts.php';
if (file_exists($layout_path)) {
    require_once $layout_path; 
} else {
    echo "HATA: Layout dosyası bulunamadı: " . $layout_path;
}
?>
