<?php
// admin/pages/akis.php - Diyalog akışı (flow builder)
// Buton aksiyon tipleri şemadaki enum ile birebir aynıdır: alt_menu | modul | link.
//   alt_menu → hedef_veri: var olan bir menü kodu   (motor: flow_<kod>)
//   modul    → hedef_veri: bot_moduller.modul_kodu  (motor: modul_<kod>)
//   link     → hedef_veri: http(s) URL
require_once __DIR__ . '/../_baslat.php';

const AKSIYON_TIPLERI = ['alt_menu' => 'Alt Menü Aç', 'modul' => 'Modül Tetikle', 'link' => 'Bağlantı (URL) Aç'];

/** Menü kodu: küçük harf, a-z 0-9 _ (2-50); uymuyorsa null. */
function menuKoduAl(string $ham): ?string {
    $kod = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', trim($ham))));
    return preg_match('/^[a-z0-9_]{2,50}$/', $kod) ? $kod : null;
}

/** Buton formunu doğrular; hata varsa JSON hata yanıtıyla biter. Dönüş: [metin, tip, hedef, sıra]. */
function butonAlanlari(PDO $db): array {
    $metin = trim((string)($_POST['buton_metni'] ?? ''));
    $tip = (string)($_POST['aksiyon_tipi'] ?? '');
    $hedef = trim((string)($_POST['hedef_veri'] ?? ''));
    $sira = max(0, min(1000, (int)($_POST['siralama'] ?? 0)));

    if ($metin === '' || mb_strlen($metin, 'UTF-8') > 100) {
        Panel::json(['success' => false, 'message' => 'Buton metni 1-100 karakter olmalı.']);
    }
    if (!isset(AKSIYON_TIPLERI[$tip])) {
        Panel::json(['success' => false, 'message' => 'Geçersiz aksiyon tipi (alt_menu, modul ya da link olmalı).']);
    }
    if ($hedef === '' || mb_strlen($hedef, 'UTF-8') > 255) {
        Panel::json(['success' => false, 'message' => 'Hedef alanı 1-255 karakter olmalı.']);
    }
    if ($tip === 'alt_menu') {
        $q = $db->prepare("SELECT 1 FROM bot_akis_menuleri WHERE menu_kodu = ?");
        $q->execute([$hedef]);
        if (!$q->fetchColumn()) {
            Panel::json(['success' => false, 'message' => 'Hedef menü bulunamadı. Önce "' . $hedef . '" menüsünü oluşturun.']);
        }
    } elseif ($tip === 'modul') {
        $q = $db->prepare("SELECT 1 FROM bot_moduller WHERE modul_kodu = ?");
        $q->execute([$hedef]);
        if (!$q->fetchColumn()) {
            Panel::json(['success' => false, 'message' => 'Hedef modül bulunamadı; listeden bir modül kodu seçin.']);
        }
    } else {
        $sema = strtolower((string)parse_url($hedef, PHP_URL_SCHEME));
        if (!filter_var($hedef, FILTER_VALIDATE_URL) || !in_array($sema, ['http', 'https'], true)) {
            Panel::json(['success' => false, 'message' => 'Bağlantı http:// ya da https:// ile başlayan geçerli bir URL olmalı.']);
        }
    }
    return [$metin, $tip, $hedef, $sira];
}

// ======================================================================
// AJAX İŞLEMLERİ (CRUD)
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'menu_ekle' || $action === 'menu_duzenle') {
        $id = (int)($_POST['menu_id'] ?? 0);
        $kodu = menuKoduAl((string)($_POST['menu_kodu'] ?? ''));
        $mesaj = trim((string)($_POST['mesaj'] ?? ''));
        if ($kodu === null || $mesaj === '' || ($action === 'menu_duzenle' && $id <= 0)) {
            Panel::json(['success' => false, 'message' => 'Menü kodu (a-z, 0-9, _ / 2-50 karakter) ve mesaj zorunludur.']);
        }
        try {
            $check = $db->prepare("SELECT id FROM bot_akis_menuleri WHERE menu_kodu = ? AND id <> ?");
            $check->execute([$kodu, $id]);
            if ($check->fetch()) {
                Panel::json(['success' => false, 'message' => 'Bu menü kodu zaten kullanılıyor!']);
            }
            if ($action === 'menu_ekle') {
                $db->prepare("INSERT INTO bot_akis_menuleri (menu_kodu, mesaj) VALUES (?, ?)")->execute([$kodu, $mesaj]);
                Panel::kuralOnbelleginiTemizle();
                Panel::json(['success' => true, 'message' => 'Yeni diyalog düğümü oluşturuldu!']);
            }
            $eski = $db->prepare("SELECT menu_kodu FROM bot_akis_menuleri WHERE id = ?");
            $eski->execute([$id]);
            $eski_kod = $eski->fetchColumn();
            if ($eski_kod === false) {
                Panel::json(['success' => false, 'message' => 'Menü bulunamadı.']);
            }
            // Kod değişirse bu menüyü hedefleyen alt_menu butonları da yeni koda taşınır.
            $db->beginTransaction();
            $db->prepare("UPDATE bot_akis_menuleri SET menu_kodu = ?, mesaj = ? WHERE id = ?")->execute([$kodu, $mesaj, $id]);
            if ($eski_kod !== $kodu) {
                $db->prepare("UPDATE bot_akis_butonlari SET hedef_veri = ? WHERE aksiyon_tipi = 'alt_menu' AND hedef_veri = ?")->execute([$kodu, $eski_kod]);
            }
            $db->commit();
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true, 'message' => 'Menü başarıyla güncellendi!']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'akış menüsü kaydı')]);
        }
    }

    if ($action === 'menu_sil') {
        try {
            $db->prepare("DELETE FROM bot_akis_menuleri WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true]);
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'akış menüsü silme')]);
        }
    }

    if ($action === 'buton_ekle') {
        $ust_id = (int)($_POST['ust_menu_id'] ?? 0);
        $q = $db->prepare("SELECT 1 FROM bot_akis_menuleri WHERE id = ?");
        $q->execute([$ust_id]);
        if (!$q->fetchColumn()) {
            Panel::json(['success' => false, 'message' => 'Butonun ekleneceği menü bulunamadı.']);
        }
        [$metin, $tip, $hedef, $sira] = butonAlanlari($db);
        try {
            $db->prepare("INSERT INTO bot_akis_butonlari (ust_menu_id, buton_metni, aksiyon_tipi, hedef_veri, siralama) VALUES (?, ?, ?, ?, ?)")
               ->execute([$ust_id, $metin, $tip, $hedef, $sira]);
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true, 'message' => 'Buton başarıyla eklendi!']);
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'akış butonu ekleme')]);
        }
    }

    if ($action === 'buton_duzenle') {
        $id = (int)($_POST['buton_id'] ?? 0);
        [$metin, $tip, $hedef, $sira] = butonAlanlari($db);
        try {
            $upd = $db->prepare("UPDATE bot_akis_butonlari SET buton_metni = ?, aksiyon_tipi = ?, hedef_veri = ?, siralama = ? WHERE id = ?");
            $upd->execute([$metin, $tip, $hedef, $sira, $id]);
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true, 'message' => 'Buton başarıyla güncellendi!']);
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'akış butonu düzenleme')]);
        }
    }

    if ($action === 'buton_sil') {
        try {
            $db->prepare("DELETE FROM bot_akis_butonlari WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true]);
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'akış butonu silme')]);
        }
    }

    Panel::json(['success' => false, 'message' => 'Bilinmeyen işlem.'], 400);
}

// ======================================================================
// VERİ ÇEKİMİ VE AĞAÇ
// ======================================================================
$menuler = $db->query("SELECT * FROM bot_akis_menuleri ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$butonlar_raw = $db->query("SELECT * FROM bot_akis_butonlari ORDER BY siralama ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$sistem_modulleri = $db->query("SELECT modul_kodu, modul_adi FROM bot_moduller ORDER BY modul_adi ASC")->fetchAll(PDO::FETCH_ASSOC);

$butonlar = [];
foreach ($butonlar_raw as $b) { $butonlar[$b['ust_menu_id']][] = $b; }

// <script> içine güvenle gömülecek JSON (</script> ve tırnaklar kaçışlı)
$json_bayraklari = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
$js_moduller = json_encode($sistem_modulleri, $json_bayraklari);
$js_menuler = json_encode(array_column($menuler, 'menu_kodu'), $json_bayraklari);

/** data-* öznitelik dizisi üretir (değerler kaçışlanır). */
function veriOznitelikleri(array $veri): string {
    $cikti = '';
    foreach ($veri as $ad => $deger) {
        $cikti .= ' data-' . $ad . '="' . Guvenlik::e((string)$deger) . '"';
    }
    return $cikti;
}

/**
 * Akış ağacını HTML olarak çizer. Satır içi JS yok: düğmeler data-islem ve data-* öznitelikleri taşır,
 * tıklamalar sayfadaki tek bir addEventListener tarafından yakalanır.
 */
function drawNativeFlowTree(string $hedef_menu_kodu, array $menuler, array $butonlar, array $ziyaret_edilenler = []): string {
    $e = [Guvenlik::class, 'e'];
    $aktif_menu = null;
    foreach ($menuler as $m) { if ($m['menu_kodu'] === $hedef_menu_kodu) { $aktif_menu = $m; break; } }

    if (!$aktif_menu) {
        return "<div class='n-box n-missing'><i class='fas fa-question-circle'></i> Eksik Menü:<br><b style='color:white;'>" . $e($hedef_menu_kodu) . "</b>"
             . "<div class='n-actions'><button type='button'" . veriOznitelikleri(['islem' => 'menu-olustur', 'kod' => $hedef_menu_kodu]) . " title='Bu menüyü oluştur'><i class='fas fa-plus'></i> Hemen Oluştur</button></div></div>";
    }
    if (in_array($hedef_menu_kodu, $ziyaret_edilenler, true)) {
        return "<div class='n-box n-loop'><i class='fas fa-undo'></i> Döngü/Başa Sarma:<br><b style='color:white;'>" . $e($hedef_menu_kodu) . "</b></div>";
    }
    $ziyaret_edilenler[] = $hedef_menu_kodu;
    $menu_id = (int)$aktif_menu['id'];
    $ozet = mb_substr(strip_tags($aktif_menu['mesaj']), 0, 45, 'UTF-8') . (mb_strlen($aktif_menu['mesaj'], 'UTF-8') > 45 ? '...' : '');

    $html  = "<div class='n-box n-menu'>";
    $html .= "  <div class='n-title'><i class='fas fa-code-branch'></i> " . $e($aktif_menu['menu_kodu']) . "</div>";
    $html .= "  <div class='n-desc'>\"" . $e($ozet) . "\"</div>";
    $html .= "  <div class='n-actions'>";
    $html .= "    <button type='button'" . veriOznitelikleri(['islem' => 'menu-duzenle', 'id' => $menu_id, 'kod' => $aktif_menu['menu_kodu'], 'mesaj' => $aktif_menu['mesaj']]) . " title='Menüyü Düzenle'><i class='fas fa-edit'></i></button>";
    $html .= "    <button type='button'" . veriOznitelikleri(['islem' => 'buton-ekle', 'id' => $menu_id, 'kod' => $aktif_menu['menu_kodu']]) . " title='Altına Buton Ekle' class='add-btn'><i class='fas fa-plus'></i></button>";
    $html .= "    <button type='button'" . veriOznitelikleri(['islem' => 'menu-sil', 'id' => $menu_id]) . " title='Menüyü Sil' class='del-btn'><i class='fas fa-trash'></i></button>";
    $html .= "  </div></div>";

    $btns = $butonlar[$menu_id] ?? [];
    if ($btns) {
        $html .= "<ul>";
        foreach ($btns as $b) {
            $html .= "<li><div class='n-box n-btn'>";
            $html .= "  <div class='n-btn-text'><i class='far fa-hand-pointer'></i> " . $e($b['buton_metni']) . "</div>";
            $html .= "  <div class='n-btn-actions'>";
            $html .= "    <button type='button'" . veriOznitelikleri(['islem' => 'buton-duzenle', 'id' => $b['id'], 'metin' => $b['buton_metni'], 'tip' => $b['aksiyon_tipi'], 'hedef' => $b['hedef_veri'], 'sira' => $b['siralama']]) . " title='Butonu Düzenle'><i class='fas fa-edit'></i></button>";
            $html .= "    <button type='button'" . veriOznitelikleri(['islem' => 'buton-sil', 'id' => $b['id']]) . " title='Butonu Sil'><i class='fas fa-trash'></i></button>";
            $html .= "  </div></div>";
            $html .= "  <ul class='target-ul'><li>";
            if ($b['aksiyon_tipi'] === 'alt_menu') {
                $html .= drawNativeFlowTree($b['hedef_veri'], $menuler, $butonlar, $ziyaret_edilenler);
            } elseif ($b['aksiyon_tipi'] === 'modul') {
                $html .= "<div class='n-box n-plugin'><div style='font-size:0.75rem; opacity:0.8; margin-bottom:5px;'>Tetikleyici Modül:</div><b><i class='fas fa-bolt'></i> " . $e($b['hedef_veri']) . "</b></div>";
            } elseif ($b['aksiyon_tipi'] === 'link') {
                $html .= "<div class='n-box n-url'><div style='font-size:0.75rem; opacity:0.8; margin-bottom:5px;'>Yönlendirilen URL:</div><b><i class='fas fa-link'></i> " . $e($b['hedef_veri']) . "</b></div>";
            } else {
                $html .= "<div class='n-box n-missing'><i class='fas fa-exclamation-triangle'></i> Geçersiz aksiyon tipi<br><small>Butonu düzenleyip tip seçin.</small></div>";
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

ob_start(); 
?>

<div class="page-header" style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
    <div>
        <h1>Diyalog Akışı <span style="color: var(--text-muted); font-weight:400; font-size: 1.2rem;">/ Canvas Studio</span></h1>
        <p style="color: var(--text-muted); margin-top: 5px;">Ağacı haritada sürükleyin veya <b>Ctrl + Scroll</b> ile yakınlaşıp uzaklaşın.</p>
    </div>
    <button type="button" data-islem="menu-olustur" data-kod="" class="btn-primary" style="padding: 0.8rem 1.5rem;"><i class="fas fa-project-diagram"></i> Yeni Menü Ekle</button>
</div>

<div class="view-tabs">
    <button type="button" class="view-tab active" data-sekme="mapView"><i class="fas fa-sitemap"></i> Canvas Stüdyo</button>
    <button type="button" class="view-tab" data-sekme="listView"><i class="fas fa-list"></i> Klasik Liste</button>
</div>

<div id="mapView" class="view-content active">
    <div id="mapContainer">
        <div class="zoom-controls">
            <button type="button" class="zoom-btn" data-zoom="in" title="Yaklaş">+</button>
            <button type="button" class="zoom-btn" data-zoom="fit" title="Ekrana Sığdır" style="font-size:1rem;"><i class="fas fa-expand"></i></button>
            <button type="button" class="zoom-btn" data-zoom="out" title="Uzaklaş">-</button>
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
                <div><b style="color:var(--accent-color); font-size:1.2rem;"><i class="fas fa-code-branch"></i> <?php echo Guvenlik::e($m['menu_kodu']); ?></b> <br> <span style="color:#94a3b8; font-style:italic; display:block; margin-top:5px;">"<?php echo Guvenlik::e($m['mesaj']); ?>"</span></div>
                <div>
                    <button type="button"<?php echo veriOznitelikleri(['islem' => 'menu-duzenle', 'id' => $m['id'], 'kod' => $m['menu_kodu'], 'mesaj' => $m['mesaj']]); ?> style="background:rgba(245, 158, 11, 0.1); border:1px solid var(--warning-color); padding:8px 12px; border-radius:6px; color:var(--warning-color); cursor:pointer;"><i class="fas fa-edit"></i> Düzenle</button>
                    <button type="button"<?php echo veriOznitelikleri(['islem' => 'menu-sil', 'id' => $m['id']]); ?> style="background:rgba(239, 68, 68, 0.1); border:1px solid var(--danger-color); padding:8px 12px; border-radius:6px; color:var(--danger-color); cursor:pointer; margin-left:10px;"><i class="fas fa-trash"></i> Sil</button>
                </div>
            </div>
            <div style="background:rgba(0,0,0,0.3); padding:15px; border-radius:10px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:10px;"><b style="color:#cbd5e1;">Bağlı Butonlar</b> <button type="button"<?php echo veriOznitelikleri(['islem' => 'buton-ekle', 'id' => $m['id'], 'kod' => $m['menu_kodu']]); ?> style="background:var(--success-color); border:none; padding:5px 12px; border-radius:5px; color:#000; cursor:pointer; font-weight:bold;"><i class="fas fa-plus"></i> Ekle</button></div>
                <?php foreach($menu_butonlari as $b): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.05); padding:10px 15px; border-radius:8px; margin-bottom:8px;">
                        <div>
                            <b style="color:white; font-size:1rem;"><i class="far fa-hand-pointer"></i> <?php echo Guvenlik::e($b['buton_metni']); ?></b> 
                            <span style="color:#64748b; margin-left:10px; font-size:0.9rem;">&rarr; <?php echo Guvenlik::e($b['hedef_veri']); ?> <small>(<?php echo Guvenlik::e(AKSIYON_TIPLERI[$b['aksiyon_tipi']] ?? 'geçersiz tip'); ?>)</small></span>
                        </div>
                        <div>
                            <button type="button"<?php echo veriOznitelikleri(['islem' => 'buton-duzenle', 'id' => $b['id'], 'metin' => $b['buton_metni'], 'tip' => $b['aksiyon_tipi'], 'hedef' => $b['hedef_veri'], 'sira' => $b['siralama']]); ?> style="background:none; border:none; color:var(--warning-color); cursor:pointer; font-size:1.1rem; padding:0 8px;"><i class="fas fa-edit"></i></button>
                            <button type="button"<?php echo veriOznitelikleri(['islem' => 'buton-sil', 'id' => $b['id']]); ?> style="background:none; border:none; color:var(--danger-color); cursor:pointer; font-size:1.1rem; padding:0 8px;"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
$tip_secenekleri = '';
foreach (AKSIYON_TIPLERI as $deger => $etiket) {
    $tip_secenekleri .= '<option value="' . Guvenlik::e($deger) . '">' . Guvenlik::e($etiket) . '</option>';
}
?>
<div class="mistik-modal-overlay" id="menuModal">
    <div class="mistik-modal-box">
        <div style="display:flex; justify-content:space-between; margin-bottom:1.5rem;">
            <h3 style="color:var(--accent-color); margin:0;"><i class="fas fa-project-diagram"></i> Yeni Menü Oluştur</h3>
            <button type="button" data-kapat="menuModal" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="menuForm" class="js-akis-form">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="menu_ekle">
            <label>Menü Kodu (Benzersiz - Boşluksuz)</label>
            <input type="text" name="menu_kodu" id="add_m_kodu" class="modern-input" required maxlength="50">
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
            <button type="button" data-kapat="editMenuModal" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="editMenuForm" class="js-akis-form">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="menu_duzenle">
            <input type="hidden" name="menu_id" id="edit_m_id">
            <label>Menü Kodu</label>
            <input type="text" name="menu_kodu" id="edit_m_kodu" class="modern-input" required maxlength="50">
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
            <button type="button" data-kapat="butonModal" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="butonForm" class="js-akis-form">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="buton_ekle">
            <input type="hidden" name="ust_menu_id" id="b_ust_id">
            <div style="margin-bottom:15px; font-size:1rem; color:var(--text-muted);">Ekleneceği Menü: <b id="b_menu_kodu" style="color:white;"></b></div>
            <label>Buton Metni</label>
            <input type="text" name="buton_metni" class="modern-input" required maxlength="100">
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Aksiyon Tipi</label>
                    <select name="aksiyon_tipi" id="b_aksiyon" class="modern-input js-ac-tetik" data-hedef="b_hedef" data-liste="b_ac_list"><?php echo $tip_secenekleri; ?></select>
                </div>
                <div style="width:80px;"><label>Sıra</label><input type="number" name="siralama" value="1" min="0" max="1000" class="modern-input" style="text-align:center;"></div>
            </div>
            <label>Hedef (Yazmaya Başlayın)</label>
            <div class="autocomplete-wrapper">
                <input type="text" name="hedef_veri" id="b_hedef" class="modern-input js-ac-tetik" data-hedef="b_hedef" data-liste="b_ac_list" data-tip="b_aksiyon" required maxlength="255" autocomplete="off">
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
            <button type="button" data-kapat="editButonModal" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="editButonForm" class="js-akis-form">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="buton_duzenle">
            <input type="hidden" name="buton_id" id="edit_b_id">
            <label>Buton Metni</label>
            <input type="text" name="buton_metni" id="edit_b_metin" class="modern-input" required maxlength="100">
            <div style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label>Aksiyon Tipi</label>
                    <select name="aksiyon_tipi" id="edit_b_aksiyon" class="modern-input js-ac-tetik" data-hedef="edit_b_hedef" data-liste="edit_b_ac_list"><?php echo $tip_secenekleri; ?></select>
                </div>
                <div style="width:80px;"><label>Sıra</label><input type="number" name="siralama" id="edit_b_sira" min="0" max="1000" class="modern-input" style="text-align:center;"></div>
            </div>
            <label>Hedef (Yazmaya Başlayın)</label>
            <div class="autocomplete-wrapper">
                <input type="text" name="hedef_veri" id="edit_b_hedef" class="modern-input js-ac-tetik" data-hedef="edit_b_hedef" data-liste="edit_b_ac_list" data-tip="edit_b_aksiyon" required maxlength="255" autocomplete="off">
                <div id="edit_b_ac_list" class="autocomplete-list"></div>
            </div>
            <button type="submit" style="width:100%; padding:1rem; border-radius:0.5rem; justify-content:center; background:var(--warning-color); color:#000; font-weight:bold; border:none; cursor:pointer; margin-top:10px;"><i class="fas fa-check"></i> Güncelle</button>
            <div id="editButonFormMsg" style="margin-top: 1rem; text-align: center; font-size: 0.9rem;"></div>
        </form>
    </div>
</div>

<?php 
$page_content = ob_get_clean();

ob_start();
?>
<script>
(function () {
    const sysModules = <?php echo $js_moduller; ?>;
    const sysMenus = <?php echo $js_menuler; ?>;
    const $ = id => document.getElementById(id);
    const ac = id => $(id).classList.add('active');

    // ---------- Sekmeler ----------
    document.querySelectorAll('.view-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.view-tab').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.view-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            $(btn.dataset.sekme).classList.add('active');
        });
    });

    // ---------- Pan & zoom ----------
    let scale = 1, panning = false, pointX = 0, pointY = 0, startX, startY;
    const container = $('mapContainer'), canvas = $('treeCanvas');

    function setTransform() { canvas.style.transform = `translate(${pointX}px, ${pointY}px) scale(${scale})`; }
    function saveState() {
        try { localStorage.setItem('mistik_flow_state', JSON.stringify({ x: pointX, y: pointY, scale: scale })); } catch (e) {}
    }
    function centerAdjust() {
        const cw = container.clientWidth / 2;
        pointX = cw - (cw - pointX) * (scale / (scale * 0.8));
    }
    function fitScreen() {
        const cw = container.clientWidth, ch = container.clientHeight;
        canvas.style.transform = 'translate(0px, 0px) scale(1)';
        const tw = canvas.scrollWidth, th = canvas.scrollHeight;
        scale = Math.min((cw * 0.9) / tw, (ch * 0.9) / th);
        scale = Math.max(0.15, Math.min(scale, 1));
        pointX = (cw - (tw * scale)) / 2;
        pointY = 40;
        setTransform(); saveState();
    }
    document.querySelectorAll('[data-zoom]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const z = btn.dataset.zoom;
            if (z === 'fit') { fitScreen(); return; }
            scale = z === 'in' ? Math.min(scale * 1.2, 2.5) : Math.max(scale / 1.2, 0.15);
            centerAdjust(); setTransform(); saveState();
        });
    });

    let kayitli = null;
    try { kayitli = JSON.parse(localStorage.getItem('mistik_flow_state') || 'null'); } catch (e) {}
    if (kayitli && typeof kayitli.scale === 'number') {
        scale = kayitli.scale; pointX = +kayitli.x || 0; pointY = +kayitli.y || 0; setTransform();
    } else {
        setTimeout(fitScreen, 200);
    }
    container.addEventListener('mousedown', (e) => {
        if (e.button !== 0 || e.target.closest('button')) return;
        e.preventDefault();
        panning = true; container.style.cursor = 'grabbing';
        startX = e.clientX - pointX; startY = e.clientY - pointY;
    });
    window.addEventListener('mouseup', () => { if (!panning) return; panning = false; container.style.cursor = 'grab'; saveState(); });
    window.addEventListener('mousemove', (e) => {
        if (!panning) return;
        e.preventDefault();
        pointX = e.clientX - startX; pointY = e.clientY - startY; setTransform();
    });
    container.addEventListener('wheel', (e) => {
        e.preventDefault();
        if (e.ctrlKey) {
            const rect = container.getBoundingClientRect();
            const mouseX = e.clientX - rect.left, mouseY = e.clientY - rect.top;
            const newScale = Math.max(0.15, Math.min(scale * Math.exp((e.deltaY < 0 ? 1 : -1) * 0.15), 2.5));
            pointX = mouseX - (mouseX - pointX) * (newScale / scale);
            pointY = mouseY - (mouseY - pointY) * (newScale / scale);
            scale = newScale;
        } else {
            pointY -= e.deltaY; pointX -= e.deltaX;
        }
        setTransform(); saveState();
    }, { passive: false });

    // ---------- Modallar: tüm düğmeler data-islem ile, değerler data-* özniteliklerinden ----------
    function formuSifirla(id) { $(id).reset(); $(id + 'Msg').textContent = ''; }

    document.addEventListener('click', async function (e) {
        if (e.target.classList.contains('mistik-modal-overlay')) { e.target.classList.remove('active'); return; }
        const kapat = e.target.closest('[data-kapat]');
        if (kapat) { $(kapat.dataset.kapat).classList.remove('active'); return; }

        const el = e.target.closest('[data-islem]');
        if (!el) {
            if (!e.target.classList.contains('js-ac-tetik')) {
                $('b_ac_list').style.display = 'none'; $('edit_b_ac_list').style.display = 'none';
            }
            return;
        }
        const d = el.dataset;
        switch (d.islem) {
            case 'menu-olustur':
                formuSifirla('menuForm');
                $('add_m_kodu').value = d.kod || '';
                ac('menuModal');
                break;
            case 'menu-duzenle':
                formuSifirla('editMenuForm');
                $('edit_m_id').value = d.id; $('edit_m_kodu').value = d.kod; $('edit_m_mesaj').value = d.mesaj;
                ac('editMenuModal');
                break;
            case 'buton-ekle':
                formuSifirla('butonForm');
                $('b_ust_id').value = d.id; $('b_menu_kodu').textContent = d.kod;
                ac('butonModal');
                break;
            case 'buton-duzenle':
                formuSifirla('editButonForm');
                $('edit_b_id').value = d.id; $('edit_b_metin').value = d.metin;
                $('edit_b_aksiyon').value = d.tip; $('edit_b_hedef').value = d.hedef; $('edit_b_sira').value = d.sira;
                ac('editButonModal');
                break;
            case 'menu-sil':
            case 'buton-sil': {
                const menu = d.islem === 'menu-sil';
                if (!confirm(menu ? 'DİKKAT: Menü silinirse içindeki tüm butonlar da silinir. Onaylıyor musunuz?' : 'Butonu silmek istediğinize emin misiniz?')) return;
                const fd = new FormData(); fd.append('action', menu ? 'menu_sil' : 'buton_sil'); fd.append('id', d.id);
                const data = await MISTIK.istek('akis', fd);
                if (data.success) location.reload(); else alert(data.message);
                break;
            }
        }
    });

    // ---------- Hedef için otomatik tamamlama (DOM ile; isimler HTML olarak yorumlanmaz) ----------
    function oneriSatiri(ikon, renk, baslik, ek, deger, input, liste) {
        const div = document.createElement('div');
        const i = document.createElement('i'); i.className = 'fas ' + ikon; i.style.color = renk;
        const b = document.createElement('b'); b.textContent = baslik;
        div.append(i, ' ', b);
        if (ek) { const sm = document.createElement('small'); sm.style.color = '#64748b'; sm.textContent = ' (' + ek + ')'; div.appendChild(sm); }
        div.addEventListener('click', function () { input.value = deger; liste.style.display = 'none'; });
        return div;
    }
    function listeBasligi(metin) {
        const h = document.createElement('div');
        const sm = document.createElement('small'); sm.style.color = '#10b981'; sm.style.fontWeight = 'bold'; sm.textContent = metin;
        h.appendChild(sm); h.style.background = '#0f172a'; h.style.cursor = 'default'; h.style.borderBottom = '1px solid rgba(255,255,255,0.1)';
        return h;
    }
    function runAutocomplete(inputId, actionId, listId) {
        const input = $(inputId), tip = $(actionId).value, liste = $(listId);
        const val = (input.value || '').toLowerCase().trim();
        liste.textContent = '';
        let sayi = 0;
        if (tip === 'modul') {
            const eslesen = sysModules.filter(m => (m.modul_kodu || '').toLowerCase().includes(val) || (m.modul_adi || '').toLowerCase().includes(val));
            if (eslesen.length) liste.appendChild(listeBasligi('Sistemdeki Modüller (' + eslesen.length + ' adet)'));
            eslesen.forEach(m => liste.appendChild(oneriSatiri('fa-bolt', '#f59e0b', m.modul_adi || 'İsimsiz Modül', m.modul_kodu, m.modul_kodu, input, liste)));
            sayi = eslesen.length;
        } else if (tip === 'alt_menu') {
            const eslesen = sysMenus.filter(m => (m || '').toLowerCase().includes(val));
            if (eslesen.length) liste.appendChild(listeBasligi('Mevcut Alt Menüler (' + eslesen.length + ' adet)'));
            eslesen.forEach(m => liste.appendChild(oneriSatiri('fa-code-branch', '#10b981', m, '', m, input, liste)));
            sayi = eslesen.length;
        }
        input.placeholder = tip === 'link' ? 'https://...' : '';
        liste.style.display = sayi > 0 ? 'block' : 'none';
    }
    document.querySelectorAll('.js-ac-tetik').forEach(function (el) {
        const calistir = () => runAutocomplete(el.dataset.hedef, el.tagName === 'SELECT' ? el.id : el.dataset.tip, el.dataset.liste);
        el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', calistir);
        if (el.tagName !== 'SELECT') el.addEventListener('focus', calistir);
    });

    // ---------- Form gönderimi ----------
    document.querySelectorAll('.js-akis-form').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const msg = $(form.id + 'Msg');
            const btn = form.querySelector('button[type="submit"]'); const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> İşleniyor...'; btn.disabled = true;
            try {
                const data = await MISTIK.istek('akis', new FormData(form));
                MISTIK.mesaj(msg, data.message, data.success);
                if (data.success) { setTimeout(() => location.reload(), 800); return; }
            } catch (err) { MISTIK.mesaj(msg, 'Sunucu Hatası!', false); }
            btn.innerHTML = originalText; btn.disabled = false;
        });
    });
})();
</script>
<?php
$extra_js = ob_get_clean();

require_once __DIR__ . '/../layouts/master_layouts.php';
