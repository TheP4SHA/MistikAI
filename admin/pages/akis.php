<?php
// /data/admin/front/akis.php
require_once __DIR__ . '/../_baslat.php';

// ======================================================================
// 2. AJAX İŞLEMLERİ (CRUD)
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    if ($_POST['action'] === 'menu_ekle') {
        $kodu = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['menu_kodu'])));
        $mesaj = trim($_POST['mesaj']);
        if(!empty($kodu) && !empty($mesaj)) {
            try {
                $check = $db->prepare("SELECT id FROM bot_akis_menuleri WHERE menu_kodu = ?");
                $check->execute([$kodu]);
                if($check->rowCount() > 0) { echo json_encode(['success'=>false, 'message'=>'Bu menü kodu zaten var!']); exit; }
                $db->prepare("INSERT INTO bot_akis_menuleri (menu_kodu, mesaj) VALUES (?, ?)")->execute([$kodu, $mesaj]);
                echo json_encode(['success'=>true, 'message'=>'Yeni diyalog düğümü oluşturuldu!']);
            } catch(Exception $e) { echo json_encode(['success'=>false, 'message'=>$e->getMessage()]); }
        } else { echo json_encode(['success'=>false, 'message'=>'Boş alan bırakılamaz.']); }
        exit;
    }

    if ($_POST['action'] === 'menu_duzenle') {
        $id = (int)$_POST['menu_id'];
        $kodu = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['menu_kodu'])));
        $mesaj = trim($_POST['mesaj']);
        if(!empty($kodu) && !empty($mesaj) && $id > 0) {
            try {
                $check = $db->prepare("SELECT id FROM bot_akis_menuleri WHERE menu_kodu = ? AND id != ?");
                $check->execute([$kodu, $id]);
                if($check->rowCount() > 0) { echo json_encode(['success'=>false, 'message'=>'Bu menü kodu başka bir menü tarafından kullanılıyor!']); exit; }
                $db->prepare("UPDATE bot_akis_menuleri SET menu_kodu=?, mesaj=? WHERE id=?")->execute([$kodu, $mesaj, $id]);
                echo json_encode(['success'=>true, 'message'=>'Menü başarıyla güncellendi!']);
            } catch(Exception $e) { echo json_encode(['success'=>false, 'message'=>$e->getMessage()]); }
        } else { echo json_encode(['success'=>false, 'message'=>'Eksik bilgi.']); }
        exit;
    }
    
    if ($_POST['action'] === 'menu_sil') {
        try { $db->prepare("DELETE FROM bot_akis_menuleri WHERE id = ?")->execute([(int)$_POST['id']]); echo json_encode(['success'=>true]); } catch(Exception $e) { echo json_encode(['success'=>false]); }
        exit;
    }

    if ($_POST['action'] === 'buton_ekle') {
        $ust_id = (int)$_POST['ust_menu_id'];
        $metin = trim($_POST['buton_metni']);
        $tip = $_POST['aksiyon_tipi'];
        $hedef = trim($_POST['hedef_veri']);
        $sira = (int)$_POST['siralama'];
        if(!empty($metin) && !empty($hedef)) {
            try {
                $db->prepare("INSERT INTO bot_akis_butonlari (ust_menu_id, buton_metni, aksiyon_tipi, hedef_veri, siralama) VALUES (?, ?, ?, ?, ?)")->execute([$ust_id, $metin, $tip, $hedef, $sira]);
                echo json_encode(['success'=>true, 'message'=>'Buton başarıyla eklendi!']);
            } catch(Exception $e) { echo json_encode(['success'=>false, 'message'=>$e->getMessage()]); }
        } else { echo json_encode(['success'=>false, 'message'=>'Metin ve Hedef alanları zorunludur.']); }
        exit;
    }

    if ($_POST['action'] === 'buton_duzenle') {
        $id = (int)$_POST['buton_id'];
        $metin = trim($_POST['buton_metni']);
        $tip = $_POST['aksiyon_tipi'];
        $hedef = trim($_POST['hedef_veri']);
        $sira = (int)$_POST['siralama'];
        if(!empty($metin) && !empty($hedef) && $id > 0) {
            try {
                $db->prepare("UPDATE bot_akis_butonlari SET buton_metni=?, aksiyon_tipi=?, hedef_veri=?, siralama=? WHERE id=?")->execute([$metin, $tip, $hedef, $sira, $id]);
                echo json_encode(['success'=>true, 'message'=>'Buton başarıyla güncellendi!']);
            } catch(Exception $e) { echo json_encode(['success'=>false, 'message'=>$e->getMessage()]); }
        } else { echo json_encode(['success'=>false, 'message'=>'Metin ve Hedef alanları zorunludur.']); }
        exit;
    }

    if ($_POST['action'] === 'buton_sil') {
        try { $db->prepare("DELETE FROM bot_akis_butonlari WHERE id = ?")->execute([(int)$_POST['id']]); echo json_encode(['success'=>true]); } catch(Exception $e) { echo json_encode(['success'=>false]); }
        exit;
    }
}

// ======================================================================
// 3. VERİ ÇEKİMİ VE ORG-CHART HARİTA MOTORUMUZ
// ======================================================================
$menuler = $db->query("SELECT * FROM bot_akis_menuleri ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$butonlar_raw = $db->query("SELECT * FROM bot_akis_butonlari ORDER BY siralama ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$sistem_modulleri = $db->query("SELECT modul_kodu, modul_adi FROM bot_moduller ORDER BY modul_adi ASC")->fetchAll(PDO::FETCH_ASSOC);

$butonlar = [];
foreach($butonlar_raw as $b) { $butonlar[$b['ust_menu_id']][] = $b; }

// JSON Güvenli Hale Getirildi (Türkçe Karakter Uyumlu)
$js_moduller = json_encode($sistem_modulleri, JSON_UNESCAPED_UNICODE);
$js_menuler = json_encode(array_column($menuler, 'menu_kodu'), JSON_UNESCAPED_UNICODE);

// KUSURSUZ DOM MİMARİSİ
function drawNativeFlowTree($hedef_menu_kodu, $menuler, $butonlar, $ziyaret_edilenler = []) {
    $aktif_menu = null;
    foreach($menuler as $m) { if($m['menu_kodu'] === $hedef_menu_kodu) { $aktif_menu = $m; break; } }
    
    $html = "";
    
    if(!$aktif_menu) {
        $safe_kod = htmlspecialchars($hedef_menu_kodu, ENT_QUOTES, 'UTF-8');
        $html .= "<div class='n-box n-missing'><i class='fas fa-question-circle'></i> Eksik Menü:<br><b style='color:white;'>" . $safe_kod . "</b>";
        $html .= "<div class='n-actions'><button onclick=\"openMenuModal('$safe_kod')\" title='Bu menüyü oluştur'><i class='fas fa-plus'></i> Hemen Oluştur</button></div></div>";
        return $html;
    }

    if(in_array($hedef_menu_kodu, $ziyaret_edilenler)) {
        $html .= "<div class='n-box n-loop'><i class='fas fa-undo'></i> Döngü/Başa Sarma:<br><b style='color:white;'>" . htmlspecialchars($hedef_menu_kodu) . "</b></div>";
        return $html;
    }
    
    $ziyaret_edilenler[] = $hedef_menu_kodu;
    $menu_id = $aktif_menu['id'];

    $safe_m_kodu = htmlspecialchars($aktif_menu['menu_kodu'], ENT_QUOTES, 'UTF-8');
    $safe_m_mesaj_js = htmlspecialchars($aktif_menu['mesaj'], ENT_QUOTES, 'UTF-8');
    $safe_m_mesaj_disp = htmlspecialchars(mb_substr(strip_tags($aktif_menu['mesaj']), 0, 45)) . (mb_strlen($aktif_menu['mesaj']) > 45 ? "..." : "");

    $html .= "<div class='n-box n-menu'>";
    $html .= "  <div class='n-title'><i class='fas fa-code-branch'></i> " . $safe_m_kodu . "</div>";
    $html .= "  <div class='n-desc'>\"" . $safe_m_mesaj_disp . "\"</div>";
    $html .= "  <div class='n-actions'>";
    $html .= "    <button onclick=\"openEditMenuModal($menu_id, '$safe_m_kodu', '$safe_m_mesaj_js')\" title='Menüyü Düzenle'><i class='fas fa-edit'></i></button>";
    $html .= "    <button onclick=\"openButonModal($menu_id, '$safe_m_kodu')\" title='Altına Buton Ekle' class='add-btn'><i class='fas fa-plus'></i></button>";
    $html .= "    <button onclick=\"silMenu($menu_id)\" title='Menüyü Sil' class='del-btn'><i class='fas fa-trash'></i></button>";
    $html .= "  </div></div>";

    $btns = $butonlar[$menu_id] ?? [];
    if(!empty($btns)) {
        $html .= "<ul>";
        foreach($btns as $b) {
            $html .= "<li>";
            $b_id = $b['id'];
            $safe_b_metin_js = htmlspecialchars($b['buton_metni'], ENT_QUOTES, 'UTF-8');
            $safe_b_metin_disp = htmlspecialchars($b['buton_metni']);
            $safe_b_tip = $b['aksiyon_tipi'];
            $safe_b_hedef_js = htmlspecialchars($b['hedef_veri'], ENT_QUOTES, 'UTF-8');
            $b_sira = $b['siralama'];

            $html .= "  <div class='n-box n-btn'>";
            $html .= "    <div class='n-btn-text'><i class='far fa-hand-pointer'></i> " . $safe_b_metin_disp . "</div>";
            $html .= "    <div class='n-btn-actions'>";
            $html .= "      <button onclick=\"openEditButonModal($b_id, '$safe_b_metin_js', '$safe_b_tip', '$safe_b_hedef_js', $b_sira)\" title='Butonu Düzenle'><i class='fas fa-edit'></i></button>";
            $html .= "      <button onclick=\"silButon($b_id)\" title='Butonu Sil'><i class='fas fa-trash'></i></button>";
            $html .= "    </div></div>";
            
            $html .= "  <ul class='target-ul'><li>";
            if ($b['aksiyon_tipi'] === 'alt_menu') {
                $html .= drawNativeFlowTree($b['hedef_veri'], $menuler, $butonlar, $ziyaret_edilenler);
            } elseif ($b['aksiyon_tipi'] === 'modul_tetikle') {
                $html .= "  <div class='n-box n-plugin'><div style='font-size:0.75rem; opacity:0.8; margin-bottom:5px;'>Tetikleyici Modül:</div><b><i class='fas fa-bolt'></i> " . htmlspecialchars($b['hedef_veri']) . "</b></div>";
            } else {
                $html .= "  <div class='n-box n-url'><div style='font-size:0.75rem; opacity:0.8; margin-bottom:5px;'>Yönlendirilen URL:</div><b><i class='fas fa-link'></i> " . htmlspecialchars($b['hedef_veri']) . "</b></div>";
            }
            $html .= "  </li></ul></li>";
        }
        $html .= "</ul>";
    }
    
    return $html;
}

$page_title = "Diyalog Akışı & Flow Builder";
$active_menu = "akis";

ob_start();
?>
    /* SEKME VE MODAL CSS'İ */
    .view-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; }
    .view-tab { background: transparent; color: var(--text-muted); border: none; padding: 12px 25px; font-size: 1.05rem; font-weight: bold; cursor: pointer; border-radius: 8px; transition: 0.3s; }
    .view-tab.active { background: var(--accent-color); color: white; }
    .view-tab:hover:not(.active) { background: rgba(255,255,255,0.05); }
    .view-content { display: none; }
    .view-content.active { display: block; }

    .mistik-modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.85); display: none; align-items: center; justify-content: center; z-index: 9999; opacity: 0; transition: 0.3s ease; }
    .mistik-modal-overlay.active { display: flex; opacity: 1; }
    .mistik-modal-box { background: var(--bg-panel); border: 1px solid var(--border-color); border-radius: 1rem; width: 500px; padding: 2.5rem; transform: translateY(-20px); transition: 0.3s ease; box-shadow: 0 25px 50px rgba(0,0,0,0.6);}
    .mistik-modal-overlay.active .mistik-modal-box { transform: translateY(0); }
    .modern-input { width: 100%; padding: 1rem; background: var(--bg-input); border: 1px solid var(--border-color); color: white; border-radius: 0.5rem; outline: none; margin-bottom: 1.2rem; font-size: 1.05rem; }
    .modern-input:focus { border-color: var(--accent-color); }
    label { display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem; font-weight: bold; text-transform: uppercase; }
    
    .autocomplete-wrapper { position: relative; width: 100%; margin-bottom: 0; }
    .autocomplete-list { position: absolute; top: 100%; left: 0; width: 100%; background: #1e293b; border: 1px solid #475569; border-top: none; z-index: 10000; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; max-height: 250px; overflow-y: auto; box-shadow: 0 15px 30px rgba(0,0,0,0.6); display: none; }
    .autocomplete-list div { padding: 12px 15px; cursor: pointer; color: #cbd5e1; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 1.05rem; }
    .autocomplete-list div:last-child { border-bottom: none; }
    .autocomplete-list div:hover { background: rgba(139, 92, 246, 0.3); color: white; }

    /* ==============================================================
       CANVAS MOTORU (PAN & ZOOM & DİKDÖRTGEN SINIRLAR)
       ============================================================== */
    #mapContainer { 
        position: relative;
        width: 100%; height: 75vh; 
        background-color: #0b1121;
        background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 1px);
        background-size: 25px 25px;
        border-radius: 15px; border: 1px solid var(--border-color); 
        overflow: hidden; 
        box-shadow: inset 0 0 40px rgba(0,0,0,0.6); 
        cursor: grab; 
    }
    #mapContainer:active { cursor: grabbing; }

    .zoom-controls { position: absolute; right: 20px; bottom: 20px; display: flex; flex-direction: column; gap: 8px; z-index: 50; }
    .zoom-btn { background: #1e293b; border: 1px solid #475569; color: white; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; cursor: pointer; transition: 0.2s; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
    .zoom-btn:hover { background: var(--accent-color); border-color: var(--accent-color); }

    #treeCanvas { 
        position: absolute; 
        top: 0; left: 0; 
        transform-origin: 0 0; 
        width: max-content;
        height: max-content;
        padding: 40px;
    }

    .tree { display: block; white-space: nowrap; }
    .tree ul { padding-top: 30px; position: relative; display: flex; justify-content: center; padding-left: 0; margin: 0; list-style: none; }
    .tree ul::before { content: ''; position: absolute; top: 0; left: 50%; border-left: 2px solid #475569; width: 0; height: 30px; transform: translateX(-50%); }
    .tree > ul::before { display: none; } 
    .tree > ul { padding-top: 0; }
    .tree li { display: flex; flex-direction: column; align-items: center; position: relative; padding: 30px 10px 0 10px; flex: 0 0 auto; }
    .tree li::before, .tree li::after { content: ''; position: absolute; top: 0; width: 50%; height: 30px; border-top: 2px solid #475569; z-index: 1; }
    .tree li::before { right: 50%; border-right: none; }
    .tree li::after { left: 50%; border-left: 2px solid #475569; margin-left: -1px; }
    .tree li:first-of-type::before, .tree li:last-of-type::after { display: none; }
    .tree li:first-of-type::after { border-top-left-radius: 10px; }
    .tree li:last-of-type::before { border-top-right-radius: 10px; border-right: 2px solid #475569; margin-right:-1px; }
    .tree li:only-of-type::after, .tree li:only-of-type::before { display: none; }
    .tree li:only-of-type { padding-top: 0; }
    .tree .target-ul { padding-top: 25px; }
    .tree .target-ul::before { height: 25px; display: block; } 
    .tree .target-ul > li { padding-top: 0; } 

    .n-box { 
        padding: 15px; border-radius: 12px; font-size: 0.95rem; 
        box-shadow: 0 5px 15px rgba(0,0,0,0.4); margin: 0; 
        width: 240px; 
        white-space: normal; text-align: center; position: relative; z-index: 2; 
        transition: transform 0.2s, box-shadow 0.2s; border: 2px solid transparent; 
        background: #1e293b;
    }
    .n-box:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.6); z-index: 10; }
    
    .n-menu { background: rgba(16, 185, 129, 0.05); border-color: #10b981; }
    .n-title { font-weight: bold; color: #10b981; border-bottom: 1px solid rgba(16, 185, 129, 0.2); padding-bottom: 8px; margin-bottom: 8px; font-size: 1.1rem; }
    .n-desc { font-size: 0.85rem; color: #cbd5e1; font-style: italic; line-height: 1.4; }
    .n-actions { margin-top: 10px; display: flex; justify-content: center; gap: 8px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 10px; }
    .n-actions button { background: rgba(0,0,0,0.4); border: none; color: #cbd5e1; border-radius: 6px; padding: 6px 12px; cursor: pointer; transition: 0.2s; font-size: 0.9rem; }
    .n-actions button:hover { background: #3b82f6; color: white; }
    .n-actions button.add-btn:hover { background: #10b981; }
    .n-actions button.del-btn:hover { background: #ef4444; }
    
    .n-btn { background: #0f172a; border-color: #64748b; color: white; display: flex; flex-direction: column; justify-content: center; padding: 12px; }
    .n-btn-text { font-weight: bold; font-size: 1rem; margin-bottom: 8px; }
    .n-btn-actions { display: flex; gap: 15px; border-top: 1px dashed rgba(255,255,255,0.2); padding-top: 8px; width: 100%; justify-content: center; }
    .n-btn-actions button { background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 1rem; transition: 0.2s; padding: 0 5px;}
    .n-btn-actions button:hover { color: #3b82f6; }
    .n-btn-actions button:last-child:hover { color: #ef4444; }
    
    .n-plugin { background: rgba(245, 158, 11, 0.15); border-color: #f59e0b; color: #fcd34d; font-size:0.95rem; }
    .n-url { background: rgba(59, 130, 246, 0.15); border-color: #3b82f6; color: #93c5fd; font-size:0.95rem; }
    .n-loop { background: rgba(239, 68, 68, 0.1); border: 2px dashed #ef4444; color: #fca5a5; }
    .n-missing { background: rgba(100, 100, 100, 0.1); border: 2px dashed #94a3b8; color: #94a3b8; }
<?php
$extra_css = ob_get_clean();

// Verileri Javascript'e Aktar
$extra_js = "<script>
    const sysModules = " . $js_moduller . ";
    const sysMenus = " . $js_menuler . ";

    function switchTab(tabId) {
        document.querySelectorAll('.view-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.view-content').forEach(c => c.classList.remove('active'));
        event.target.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    }

    // ==========================================
    // MÜKEMMEL PAN & ZOOM MOTORU (CACHE SİSTEMLİ)
    // ==========================================
    let scale = 1, panning = false, pointX = 0, pointY = 0, startX, startY;
    let container, canvas;

    document.addEventListener('DOMContentLoaded', () => {
        container = document.getElementById('mapContainer');
        canvas = document.getElementById('treeCanvas');
        if(!container || !canvas) return;

        // KAYDEDİLMİŞ POZİSYONU GERİ YÜKLE
        let savedState = localStorage.getItem('mistik_flow_state');
        if(savedState) {
            let state = JSON.parse(savedState);
            scale = state.scale;
            pointX = state.x;
            pointY = state.y;
            setTransform();
        } else {
            setTimeout(fitScreen, 200);
        }

        container.addEventListener('mousedown', (e) => {
            if(e.button !== 0) return; 
            e.preventDefault();
            panning = true;
            container.style.cursor = 'grabbing';
            startX = e.clientX - pointX;
            startY = e.clientY - pointY;
        });

        window.addEventListener('mouseup', () => { 
            panning = false; 
            if(container) container.style.cursor = 'grab'; 
            saveState(); 
        });
        
        window.addEventListener('mousemove', (e) => {
            if(!panning) return;
            e.preventDefault();
            pointX = e.clientX - startX;
            pointY = e.clientY - startY;
            setTransform();
        });

        container.addEventListener('wheel', (e) => {
            if (e.ctrlKey) {
                e.preventDefault();
                let rect = container.getBoundingClientRect();
                let mouseX = e.clientX - rect.left;
                let mouseY = e.clientY - rect.top;

                let wheel = e.deltaY < 0 ? 1 : -1;
                let zoomFactor = Math.exp(wheel * 0.15); 
                let newScale = scale * zoomFactor;
                
                newScale = Math.max(0.15, Math.min(newScale, 2.5));

                pointX = mouseX - (mouseX - pointX) * (newScale / scale);
                pointY = mouseY - (mouseY - pointY) * (newScale / scale);
                
                scale = newScale;
                setTransform();
                saveState(); 
            } else {
                e.preventDefault();
                pointY -= e.deltaY;
                pointX -= e.deltaX;
                setTransform();
                saveState(); 
            }
        }, { passive: false });
    });

    function setTransform() {
        canvas.style.transform = `translate(\${pointX}px, \${pointY}px) scale(\${scale})`;
    }

    function saveState() {
        let state = { x: pointX, y: pointY, scale: scale };
        localStorage.setItem('mistik_flow_state', JSON.stringify(state));
    }

    function zoomIn() { scale = Math.min(scale * 1.2, 2.5); centerAdjust(); setTransform(); saveState(); }
    function zoomOut() { scale = Math.max(scale / 1.2, 0.15); centerAdjust(); setTransform(); saveState(); }
    
    function centerAdjust() {
        let cw = container.clientWidth / 2;
        let ch = container.clientHeight / 2;
        pointX = cw - (cw - pointX) * (scale / (scale*0.8));
    }

    function fitScreen() {
        if(!container || !canvas) return;
        let cw = container.clientWidth;
        let ch = container.clientHeight;
        
        canvas.style.transform = 'translate(0px, 0px) scale(1)';
        let tw = canvas.scrollWidth;
        let th = canvas.scrollHeight;

        let scaleX = (cw * 0.9) / tw;
        let scaleY = (ch * 0.9) / th;
        scale = Math.min(scaleX, scaleY);
        
        if(scale > 1) scale = 1; 
        if(scale < 0.15) scale = 0.15;

        pointX = (cw - (tw * scale)) / 2;
        pointY = 40; 

        setTransform();
        saveState(); 
    }
</script>";

ob_start(); 
?>

<div class="page-header" style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
    <div>
        <h1>Diyalog Akışı <span style="color: var(--text-muted); font-weight:400; font-size: 1.2rem;">/ Canvas Studio</span></h1>
        <p style="color: var(--text-muted); margin-top: 5px;">Ağacı haritada sürükleyin veya <b>Ctrl + Scroll</b> ile yakınlaşıp uzaklaşın.</p>
    </div>
    <button onclick="openMenuModal()" class="btn-primary" style="padding: 0.8rem 1.5rem;"><i class="fas fa-project-diagram"></i> Yeni Menü Ekle</button>
</div>

<div class="view-tabs">
    <button class="view-tab active" onclick="switchTab('mapView')"><i class="fas fa-sitemap"></i> Canvas Stüdyo</button>
    <button class="view-tab" onclick="switchTab('listView')"><i class="fas fa-list"></i> Klasik Liste</button>
</div>

<div id="mapView" class="view-content active">
    
    <div id="mapContainer">
        <div class="zoom-controls">
            <button class="zoom-btn" onclick="zoomIn()" title="Yaklaş">+</button>
            <button class="zoom-btn" onclick="fitScreen()" title="Ekrana Sığdır" style="font-size:1rem;"><i class="fas fa-expand"></i></button>
            <button class="zoom-btn" onclick="zoomOut()" title="Uzaklaş">-</button>
        </div>

        <div id="treeCanvas">
            <div class="tree">
                <ul><li><?php echo drawNativeFlowTree('ana_menu', $menuler, $butonlar); ?></li></ul>
            </div>
        </div>
    </div>
</div>

<div id="listView" class="view-content">
    <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
        <?php if(empty($menuler)): ?>
            <div style="text-align:center; padding: 3rem; background:rgba(0,0,0,0.2); border-radius:1rem; color:var(--text-muted);">Henüz hiç diyalog düğümü oluşturulmamış.</div>
        <?php endif; ?>
        <?php foreach($menuler as $m): $menu_butonlari = $butonlar[$m['id']] ?? []; ?>
        <div style="width:100%; display:block; padding:20px; border-radius:12px; margin-bottom:1rem; border:1px solid rgba(255,255,255,0.1); background:var(--bg-panel); text-align:left;">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:15px;">
                <div><b style="color:var(--accent-color); font-size:1.2rem;"><i class="fas fa-code-branch"></i> <?php echo htmlspecialchars($m['menu_kodu']); ?></b> <br> <span style="color:#94a3b8; font-style:italic; display:block; margin-top:5px;">"<?php echo htmlspecialchars($m['mesaj']); ?>"</span></div>
                <div>
                    <button onclick="openEditMenuModal(<?php echo $m['id']; ?>, '<?php echo htmlspecialchars($m['menu_kodu'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($m['mesaj'], ENT_QUOTES); ?>')" style="background:rgba(245, 158, 11, 0.1); border:1px solid var(--warning-color); padding:8px 12px; border-radius:6px; color:var(--warning-color); cursor:pointer;"><i class="fas fa-edit"></i> Düzenle</button>
                    <button onclick="silMenu(<?php echo $m['id']; ?>)" style="background:rgba(239, 68, 68, 0.1); border:1px solid var(--danger-color); padding:8px 12px; border-radius:6px; color:var(--danger-color); cursor:pointer; margin-left:10px;"><i class="fas fa-trash"></i> Sil</button>
                </div>
            </div>
            <div style="background:rgba(0,0,0,0.3); padding:15px; border-radius:10px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px;"><b style="color:#cbd5e1;">Bağlı Butonlar</b> <button onclick="openButonModal(<?php echo $m['id']; ?>, '<?php echo htmlspecialchars($m['menu_kodu']); ?>')" style="background:var(--success-color); border:none; padding:5px 12px; border-radius:5px; color:#000; cursor:pointer; font-weight:bold;"><i class="fas fa-plus"></i> Ekle</button></div>
                <?php foreach($menu_butonlari as $b): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.05); padding:10px 15px; border-radius:8px; margin-bottom:8px;">
                        <div>
                            <b style="color:white; font-size:1rem;"><i class="far fa-hand-pointer"></i> <?php echo htmlspecialchars($b['buton_metni']); ?></b> 
                            <span style="color:#64748b; margin-left:10px; font-size:0.9rem;">&rarr; <?php echo htmlspecialchars($b['hedef_veri']); ?> <small>(<?php echo $b['aksiyon_tipi']; ?>)</small></span>
                        </div>
                        <div>
                            <button onclick="openEditButonModal(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars($b['buton_metni'], ENT_QUOTES); ?>', '<?php echo $b['aksiyon_tipi']; ?>', '<?php echo htmlspecialchars($b['hedef_veri'], ENT_QUOTES); ?>', <?php echo $b['siralama']; ?>)" style="background:none; border:none; color:var(--warning-color); cursor:pointer; font-size:1.1rem; padding:0 8px;"><i class="fas fa-edit"></i></button>
                            <button onclick="silButon(<?php echo $b['id']; ?>)" style="background:none; border:none; color:var(--danger-color); cursor:pointer; font-size:1.1rem; padding:0 8px;"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="mistik-modal-overlay" id="menuModal">
    <div class="mistik-modal-box">
        <div style="display:flex; justify-content:space-between; margin-bottom:1.5rem;">
            <h3 style="color:var(--accent-color); margin:0;"><i class="fas fa-project-diagram"></i> Yeni Menü Oluştur</h3>
            <button onclick="closeModal('menuModal')" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="menuForm" onsubmit="submitForm(event, 'menuForm')">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="menu_ekle">
            <label>Menü Kodu (Benzersiz - Boşluksuz)</label>
            <input type="text" name="menu_kodu" id="add_m_kodu" class="modern-input" required>
            <label>Botun Söyleyeceği Mesaj</label>
            <textarea name="mesaj" class="modern-input" rows="3" required style="resize:vertical;"></textarea>
            <button type="submit" class="btn-primary" style="width:100%; padding:1rem; border-radius:0.5rem; justify-content:center;"><i class="fas fa-save"></i> Menüyü Kaydet</button>
            <div id="menuFormMsg" style="margin-top: 1rem; text-align: center; font-size: 0.9rem;"></div>
        </form>
    </div>
</div>

<div class="mistik-modal-overlay" id="editMenuModal">
    <div class="mistik-modal-box">
        <div style="display:flex; justify-content:space-between; margin-bottom:1.5rem;">
            <h3 style="color:var(--warning-color); margin:0;"><i class="fas fa-edit"></i> Menüyü Düzenle</h3>
            <button onclick="closeModal('editMenuModal')" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="editMenuForm" onsubmit="submitForm(event, 'editMenuForm')">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="menu_duzenle">
            <input type="hidden" name="menu_id" id="edit_m_id">
            <label>Menü Kodu</label>
            <input type="text" name="menu_kodu" id="edit_m_kodu" class="modern-input" required>
            <label>Botun Söyleyeceği Mesaj</label>
            <textarea name="mesaj" id="edit_m_mesaj" class="modern-input" rows="3" required style="resize:vertical;"></textarea>
            <button type="submit" style="width:100%; padding:1rem; border-radius:0.5rem; justify-content:center; background:var(--warning-color); color:#000; font-weight:bold; border:none; cursor:pointer;"><i class="fas fa-check"></i> Güncelle</button>
            <div id="editMenuFormMsg" style="margin-top: 1rem; text-align: center; font-size: 0.9rem;"></div>
        </form>
    </div>
</div>

<div class="mistik-modal-overlay" id="butonModal">
    <div class="mistik-modal-box">
        <div style="display:flex; justify-content:space-between; margin-bottom:1.5rem;">
            <h3 style="color:var(--success-color); margin:0;"><i class="fas fa-plus"></i> Buton Ekle</h3>
            <button onclick="closeModal('butonModal')" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="butonForm" onsubmit="submitForm(event, 'butonForm')">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="buton_ekle">
            <input type="hidden" name="ust_menu_id" id="b_ust_id">
            <div style="margin-bottom:15px; font-size:1rem; color:var(--text-muted);">Ekleneceği Menü: <b id="b_menu_kodu" style="color:white;"></b></div>
            <label>Buton Metni</label>
            <input type="text" name="buton_metni" class="modern-input" required>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Aksiyon Tipi</label>
                    <select name="aksiyon_tipi" id="b_aksiyon" class="modern-input" onchange="runAutocomplete('b_hedef', 'b_aksiyon', 'b_ac_list')">
                        <option value="alt_menu">Alt Menü Aç</option>
                        <option value="modul_tetikle">Modül/Plugin Tetikle</option>
                        <option value="url_git">URL'ye Git</option>
                    </select>
                </div>
                <div style="width:80px;"><label>Sıra</label><input type="number" name="siralama" value="1" class="modern-input" style="text-align:center;"></div>
            </div>
            
            <label>Hedef / Komut (Yazmaya Başlayın)</label>
            <div class="autocomplete-wrapper">
                <input type="text" name="hedef_veri" id="b_hedef" class="modern-input" required autocomplete="off" oninput="runAutocomplete('b_hedef', 'b_aksiyon', 'b_ac_list')" onfocus="runAutocomplete('b_hedef', 'b_aksiyon', 'b_ac_list')">
                <div id="b_ac_list" class="autocomplete-list"></div>
            </div>
            
            <button type="submit" style="width:100%; padding:1rem; border-radius:0.5rem; justify-content:center; background:var(--success-color); color:#000; font-weight:bold; border:none; cursor:pointer; margin-top:10px;"><i class="fas fa-check"></i> Ekle</button>
            <div id="butonFormMsg" style="margin-top: 1rem; text-align: center; font-size: 0.9rem;"></div>
        </form>
    </div>
</div>

<div class="mistik-modal-overlay" id="editButonModal">
    <div class="mistik-modal-box">
        <div style="display:flex; justify-content:space-between; margin-bottom:1.5rem;">
            <h3 style="color:var(--warning-color); margin:0;"><i class="fas fa-edit"></i> Butonu Düzenle</h3>
            <button onclick="closeModal('editButonModal')" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="editButonForm" onsubmit="submitForm(event, 'editButonForm')">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="buton_duzenle">
            <input type="hidden" name="buton_id" id="edit_b_id">
            <label>Buton Metni</label>
            <input type="text" name="buton_metni" id="edit_b_metin" class="modern-input" required>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Aksiyon Tipi</label>
                    <select name="aksiyon_tipi" id="edit_b_aksiyon" class="modern-input" onchange="runAutocomplete('edit_b_hedef', 'edit_b_aksiyon', 'edit_b_ac_list')">
                        <option value="alt_menu">Alt Menü Aç</option>
                        <option value="modul_tetikle">Modül/Plugin Tetikle</option>
                        <option value="url_git">URL'ye Git</option>
                    </select>
                </div>
                <div style="width:80px;"><label>Sıra</label><input type="number" name="siralama" id="edit_b_sira" class="modern-input" style="text-align:center;"></div>
            </div>
            
            <label>Hedef / Komut (Yazmaya Başlayın)</label>
            <div class="autocomplete-wrapper">
                <input type="text" name="hedef_veri" id="edit_b_hedef" class="modern-input" required autocomplete="off" oninput="runAutocomplete('edit_b_hedef', 'edit_b_aksiyon', 'edit_b_ac_list')" onfocus="runAutocomplete('edit_b_hedef', 'edit_b_aksiyon', 'edit_b_ac_list')">
                <div id="edit_b_ac_list" class="autocomplete-list"></div>
            </div>
            
            <button type="submit" style="width:100%; padding:1rem; border-radius:0.5rem; justify-content:center; background:var(--warning-color); color:#000; font-weight:bold; border:none; cursor:pointer; margin-top:10px;"><i class="fas fa-check"></i> Güncelle</button>
            <div id="editButonFormMsg" style="margin-top: 1rem; text-align: center; font-size: 0.9rem;"></div>
        </form>
    </div>
</div>

<script>
    function openMenuModal(prefillKodu = '') { 
        document.getElementById('menuForm').reset(); 
        if(prefillKodu) document.getElementById('add_m_kodu').value = prefillKodu;
        document.getElementById('menuModal').classList.add('active'); 
    }
    function openButonModal(menu_id, menu_kodu) { 
        document.getElementById('butonForm').reset(); 
        document.getElementById('b_ust_id').value = menu_id;
        document.getElementById('b_menu_kodu').innerText = menu_kodu;
        document.getElementById('butonModal').classList.add('active'); 
    }
    function openEditMenuModal(id, kodu, mesaj) {
        document.getElementById('edit_m_id').value = id;
        document.getElementById('edit_m_kodu').value = kodu;
        document.getElementById('edit_m_mesaj').value = mesaj;
        document.getElementById('editMenuModal').classList.add('active');
    }
    function openEditButonModal(id, metin, tip, hedef, sira) {
        document.getElementById('edit_b_id').value = id;
        document.getElementById('edit_b_metin').value = metin;
        document.getElementById('edit_b_aksiyon').value = tip;
        document.getElementById('edit_b_hedef').value = hedef;
        document.getElementById('edit_b_sira').value = sira;
        document.getElementById('editButonModal').classList.add('active');
    }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    // --- KUSURSUZ (NULL-SAFE) CANLI ARAMA MOTORU ---
    function runAutocomplete(inputId, actionId, listId) {
        const input = document.getElementById(inputId);
        const action = document.getElementById(actionId).value;
        const listContainer = document.getElementById(listId);
        
        let val = (input.value || '').toLowerCase().trim();
        listContainer.innerHTML = '';
        
        if(action === 'url_git') { listContainer.style.display = 'none'; return; }

        let matches = [];
        if(action === 'modul_tetikle') {
            // Null veriler çökmeye neden olmasın diye güvenli atama yapıldı
            matches = sysModules.filter(m => {
                let kod = (m.modul_kodu || '').toLowerCase();
                let adi = (m.modul_adi || '').toLowerCase();
                return kod.includes(val) || adi.includes(val);
            });
            
            // Başlık ve Sayaç Eklendi
            if(matches.length > 0) {
                let header = document.createElement('div');
                header.innerHTML = `<small style='color:#10b981; font-weight:bold;'>Sistemdeki Modüller (${matches.length} adet)</small>`;
                header.style.background = '#0f172a';
                header.style.cursor = 'default';
                header.style.borderBottom = '1px solid rgba(255,255,255,0.1)';
                listContainer.appendChild(header);
            }

            matches.forEach(m => {
                let div = document.createElement('div');
                div.innerHTML = `<i class='fas fa-bolt' style='color:#f59e0b;'></i> <b>${m.modul_adi || 'İsimsiz Modül'}</b> <small style="color:#64748b;">(${m.modul_kodu || 'kodu-yok'})</small>`;
                div.onclick = function() { input.value = m.modul_kodu; listContainer.style.display = 'none'; };
                listContainer.appendChild(div);
            });

        } else if(action === 'alt_menu') {
            matches = sysMenus.filter(m => (m || '').toLowerCase().includes(val));
            
            if(matches.length > 0) {
                let header = document.createElement('div');
                header.innerHTML = `<small style='color:#10b981; font-weight:bold;'>Mevcut Alt Menüler (${matches.length} adet)</small>`;
                header.style.background = '#0f172a';
                header.style.cursor = 'default';
                header.style.borderBottom = '1px solid rgba(255,255,255,0.1)';
                listContainer.appendChild(header);
            }

            matches.forEach(m => {
                let div = document.createElement('div');
                div.innerHTML = `<i class='fas fa-code-branch' style='color:#10b981;'></i> <b>${m}</b>`;
                div.onclick = function() { input.value = m; listContainer.style.display = 'none'; };
                listContainer.appendChild(div);
            });
        }

        if(matches.length > 0) listContainer.style.display = 'block';
        else listContainer.style.display = 'none';
    }

    document.addEventListener("click", function (e) {
        if(e.target.id !== "b_hedef" && e.target.id !== "edit_b_hedef") {
            document.getElementById('b_ac_list').style.display = 'none';
            document.getElementById('edit_b_ac_list').style.display = 'none';
        }
    });

    async function submitForm(e, formId) {
        e.preventDefault(); 
        const form = document.getElementById(formId);
        const msg = document.getElementById(formId + 'Msg');
        const btn = form.querySelector('button[type="submit"]');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> İşleniyor...'; btn.disabled = true;
        try {
            const data = await MISTIK.istek('akis', new FormData(form));
            if(data.success) { msg.innerHTML = `<span style="color: var(--success-color);"><i class="fas fa-check-circle"></i> ${data.message}</span>`; setTimeout(() => location.reload(), 800); } 
            else { msg.innerHTML = `<span style="color: var(--danger-color);"><i class="fas fa-exclamation-triangle"></i> ${data.message}</span>`; btn.innerHTML = originalText; btn.disabled = false; }
        } catch (err) { msg.innerHTML = 'Sunucu Hatası!'; btn.innerHTML = originalText; btn.disabled = false; }
    }

    async function silMenu(id) {
        if(!confirm('DİKKAT: Menü silinirse içindeki tüm butonlar da silinir. Onaylıyor musunuz?')) return;
        const fd = new FormData(); fd.append('action', 'menu_sil'); fd.append('id', id);
        const data = await MISTIK.istek('akis', fd);
        if(data.success) location.reload();
    }

    async function silButon(id) {
        if(!confirm('Butonu silmek istediğinize emin misiniz?')) return;
        const fd = new FormData(); fd.append('action', 'buton_sil'); fd.append('id', id);
        const data = await MISTIK.istek('akis', fd);
        if(data.success) location.reload();
    }
</script>

<?php 
$page_content = ob_get_clean();
require_once __DIR__ . '/../layouts/master_layouts.php';
