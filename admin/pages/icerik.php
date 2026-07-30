<?php
// /data/admin/front/icerik.php

// GÜVENLİK DUVARI: Oturum yoksa logine şutla
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['admin_id'])) {
    header("Location: /OmyoWeb/MistikAI/admin/login");
    exit;
}

// 1. YOL DÜZELTMESİ: Kök dizin (Artık sadece /data kullanılıyor)
$doc_root = $_SERVER['DOCUMENT_ROOT']; // /var/www/html/
$base_path = $doc_root . '/OmyoWeb/MistikAI/data';

// Autoload Yolu (Yeni Klasör Yapısı)
$vendor_path = $base_path . '/vendor/autoload.php';
if (file_exists($vendor_path)) { require_once $vendor_path; }

// Veritabanı Yolu (Mutlak Yol)
$db_path = $base_path . '/config/db.php';
if (file_exists($db_path)) {
    require_once $db_path;
} else {
    // Eğer config/db.php yoksa, canlı FOG sunucusuna varsayılan bilgilerle bağlan
    try {
        require_once __DIR__ . '/../../config/db.php';
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Veritabanı bağlantı hatası!");
    }
}
global $db;

// Türkçe tarih metinlerini (Örn: "28 Temmuz 2025 Pazartesi") SQL formatına (YYYY-MM-DD) çeviren zeki fonksiyon
function parseTrDate($text) {
    $months = [
        'Ocak'=>'01', 'Şubat'=>'02', 'Mart'=>'03', 'Nisan'=>'04', 'Mayıs'=>'05', 'Haziran'=>'06', 
        'Temmuz'=>'07', 'Ağustos'=>'08', 'Eylül'=>'09', 'Ekim'=>'10', 'Kasım'=>'11', 'Aralık'=>'12',
        'Subat'=>'02', 'Mayis'=>'05', 'Agustos'=>'08', 'Eylul'=>'09', 'Kasim'=>'11', 'Aralik'=>'12'
    ];
    
    // Rakam + Ay Adı + Yıl arayan Regex
    if (preg_match('/(\d{1,2})\s+([a-zA-ZçÇğĞıİöÖşŞüÜ]+)\s+(\d{4})/ui', $text, $matches)) {
        $d = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $m_str = mb_convert_case($matches[2], MB_CASE_TITLE, "UTF-8");
        $y = $matches[3];
        $mo = isset($months[$m_str]) ? $months[$m_str] : '01';
        return "$y-$mo-$d";
    }
    return null;
}

// ======================================================================
// AJAX İŞLEMLERİ (YEMEK VE TAKVİM)
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // 1. YEMEK SENKRONİZASYONU
    if ($_POST['action'] === 'sync_yemek') {
        try {
            $ayar = $db->query("SELECT ayar_degeri FROM bot_sistem_ayarlari WHERE ayar_adi = 'yemekhane_url'")->fetchColumn();
            $url = $ayar ? $ayar : "https://www.osmaniye.edu.tr/yemekmenu";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
            $html = curl_exec($ch);
            curl_close($ch);

            $clean_html = preg_replace('/\s+/', ' ', $html);
            preg_match_all('/<tr[^>]*>\s*<td>(\d{2}\.\d{2}\.\d{4})<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<\/tr>/is', $clean_html, $matches, PREG_SET_ORDER);

            if (empty($matches)) { echo json_encode(['success' => false, 'message' => "Format çözülemedi!"]); exit; }

            $added = 0; $updated = 0;
            foreach ($matches as $row) {
                $raw_tarih = trim($row[1]); 
                $d = explode('.', $raw_tarih);
                $tarih_sql = "{$d[2]}-{$d[1]}-{$d[0]}";
                $baslik = html_entity_decode(trim(strip_tags($row[2])));
                $detay = html_entity_decode(trim(strip_tags($row[3])).", ".trim(strip_tags($row[4])).", ".trim(strip_tags($row[5]))." (".trim(strip_tags($row[6]))." kcal)");

                $check = $db->prepare("SELECT id FROM bot_zaman_cizelgesi WHERE tip='yemek' AND tarih = ?");
                $check->execute([$tarih_sql]);
                $exists = $check->fetch();
                if ($exists) {
                    $db->prepare("UPDATE bot_zaman_cizelgesi SET baslik = ?, detay = ? WHERE id = ?")->execute([$baslik, $detay, $exists['id']]);
                    $updated++;
                } else {
                    $db->prepare("INSERT INTO bot_zaman_cizelgesi (tip, baslik, detay, tarih) VALUES ('yemek', ?, ?, ?)")->execute([$baslik, $detay, $tarih_sql]);
                    $added++;
                }
            }
            echo json_encode(['success' => true, 'message' => "Ziyafet listesi güncellendi! ($added yeni, $updated güncel)"]);
        } catch(Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // 2. AKADEMİK TAKVİM SENKRONİZASYONU
    if ($_POST['action'] === 'sync_takvim') {
        $url = trim($_POST['takvim_url']);
        if (empty($url)) { echo json_encode(['success'=>false, 'message'=>'URL boş olamaz!']); exit; }

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
            $html = curl_exec($ch);
            curl_close($ch);

            $dom = new DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            $xpath = new DOMXPath($dom);
            $rows = $xpath->query('//table//tr'); 

            if ($rows->length === 0) {
                echo json_encode(['success' => false, 'message' => "Girilen linkte geçerli bir HTML tablosu bulunamadı."]);
                exit;
            }

            $db->exec("TRUNCATE TABLE bot_akademik_takvim");

            $added = 0;
            $current_yariyil = 'Güz'; 

            foreach($rows as $row) {
                $cells = $xpath->query('.//td', $row);
                
                if ($cells->length >= 1) {
                    $text = trim($cells->item(0)->textContent);
                    if (mb_stripos($text, 'GÜZ YARIYILI') !== false) { $current_yariyil = 'Güz'; continue; }
                    if (mb_stripos($text, 'BAHAR YARIYILI') !== false) { $current_yariyil = 'Bahar'; continue; }
                }

                if ($cells->length >= 3) {
                    $bas_text = trim($cells->item(0)->textContent);
                    $bit_text = trim($cells->item(1)->textContent);
                    $faaliyet = trim($cells->item(2)->textContent);

                    if (mb_stripos($bas_text, 'BAŞLANGIÇ') !== false || empty($faaliyet)) continue;

                    $bas_date = parseTrDate($bas_text);
                    $bit_date = parseTrDate($bit_text);

                    if (!$bas_date && $bit_date) { $bas_date = $bit_date; }
                    if ($bas_date && !$bit_date) { $bit_date = $bas_date; }
                    
                    if (!$bas_date && !$bit_date) continue;

                    $ins = $db->prepare("INSERT INTO bot_akademik_takvim (yariyil, baslangic_tarihi, bitis_tarihi, faaliyet) VALUES (?, ?, ?, ?)");
                    $ins->execute([$current_yariyil, $bas_date, $bit_date, $faaliyet]);
                    $added++;
                }
            }
            echo json_encode(['success' => true, 'message' => "Takvim başarıyla tarandı! Toplam $added etkinlik sisteme işlendi."]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]); }
        exit;
    }
}

$page_title = "Veri Havuzu & Scraper";
$active_menu = "icerik";
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'yemek';

ob_start();
?>
    /* --- MODERN CSS --- */
    .hub-nav { display: flex; gap: 10px; margin-bottom: 2rem; background: rgba(255,255,255,0.05); padding: 10px; border-radius: 15px; }
    .hub-tab { flex:1; padding: 15px; border-radius: 10px; cursor: pointer; text-align: center; font-weight: 800; color: #888; transition: 0.3s; text-decoration: none; display: block; }
    .hub-tab.active { background: var(--accent-color); color: #fff; box-shadow: 0 5px 15px rgba(139, 92, 246, 0.3); }
    .hub-tab:not(.active):hover { background: rgba(255,255,255,0.1); color: #ddd; }
    
    .card-pasha { background: #1a1d21; border-radius: 20px; border: 1px solid #333; overflow: hidden; margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    .card-pasha-head { padding: 20px 25px; border-bottom: 1px solid #333; background: rgba(255,255,255,0.02); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;}
    
    .modern-input { background: #0d0f12; border: 1px solid #444; color: white; padding: 12px 15px; border-radius: 8px; width: 100%; font-size: 0.95rem; transition: 0.3s; }
    .modern-input:focus { border-color: var(--accent-color); outline:none; }

    .btn-pasha { border:none; padding: 12px 25px; border-radius: 10px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; white-space: nowrap; }
    .btn-pasha:hover { transform: translateY(-2px); }
    .btn-pasha-green { background: var(--success-color); color: #000; }
    .btn-pasha-blue { background: var(--accent-color); color: #fff; }
    
    .modern-table { width: 100%; border-collapse: collapse; }
    .modern-table th { text-align: left; padding: 15px 20px; font-size: 0.8rem; color: #666; border-bottom: 1px solid #333; text-transform: uppercase; background: rgba(0,0,0,0.2); }
    .modern-table td { padding: 15px 20px; border-bottom: 1px solid #222; vertical-align: middle; }
    
    .badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; }
    .badge-guz { background: rgba(245, 158, 11, 0.15); color: var(--warning-color); border: 1px solid rgba(245, 158, 11, 0.3); }
    .badge-bahar { background: rgba(16, 185, 129, 0.15); color: var(--success-color); border: 1px solid rgba(16, 185, 129, 0.3); }
<?php
$extra_css = ob_get_clean();

ob_start(); 
?>

<div class="hub-container" style="max-width:1400px; margin:0 auto;">
    <div class="page-header" style="margin-bottom: 2rem;">
        <h1>Mistik Command Center <span style="color:var(--accent-color);">/</span> Pasha</h1>
        <p style="color:var(--text-muted);">Veri Havuzu ve Canlı Bot Scraper Yönetimi</p>
    </div>

    <div class="hub-nav">
        <a href="icerik?tab=yemek" class="hub-tab <?php echo $tab == 'yemek' ? 'active' : ''; ?>">🍱 YEMEKHANE</a>
        <a href="icerik?tab=takvim" class="hub-tab <?php echo $tab == 'takvim' ? 'active' : ''; ?>">📅 AKADEMİK TAKVİM</a>
        <a href="ders" class="hub-tab">📚 DERS PROGRAMI</a>
    </div>

    <?php if ($tab == 'yemek'): ?>
    <div class="card-pasha">
        <div class="card-pasha-head">
            <h3 style="margin:0;"><i class="fas fa-utensils" style="color:var(--warning-color);"></i> Aktif Menü Planı</h3>
            <button onclick="syncYemek()" class="btn-pasha btn-pasha-green" id="yemekBtn">
                <i class="fas fa-sync"></i> LİSTEYİ EŞİTLE (ÇEK)
            </button>
        </div>
        <div class="card-body" style="padding:0; overflow-x:auto;">
            <table class="modern-table">
                <thead><tr><th>Tarih</th><th>Ana Yemek</th><th>Detay</th></tr></thead>
                <tbody>
                    <?php
                    $list = $db->query("SELECT * FROM bot_zaman_cizelgesi WHERE tip='yemek' AND tarih >= CURDATE() ORDER BY tarih ASC LIMIT 31")->fetchAll();
                    if(empty($list)): ?>
                        <tr><td colspan="3" style="text-align:center; padding:30px; color:#666;">Veri bulunamadı. Lütfen eşitleme yapın.</td></tr>
                    <?php else: 
                        foreach($list as $l): ?>
                        <tr>
                            <td style="color:var(--accent-color); font-weight:bold; white-space:nowrap;"><?php echo date('d.m.Y', strtotime($l['tarih'])); ?></td>
                            <td style="font-weight:700;"><?php echo htmlspecialchars($l['baslik']); ?></td>
                            <td style="color:var(--text-muted); font-size:0.85rem;"><?php echo htmlspecialchars($l['detay']); ?></td>
                        </tr>
                        <?php endforeach; 
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($tab == 'takvim'): ?>
    <div class="card-pasha">
        <div class="card-pasha-head" style="flex-direction: column; align-items: stretch;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="margin:0;"><i class="fas fa-calendar-alt" style="color:var(--accent-color);"></i> Akademik Takvim Scraper</h3>
            </div>
            <div style="display: flex; gap: 10px; width: 100%;">
                <?php $takvim_url_ayar = $db->query("SELECT ayar_degeri FROM bot_sistem_ayarlari WHERE ayar_adi = 'takvim_url'")->fetchColumn(); ?>
                <input type="url" id="takvimUrl" class="modern-input" value="<?php echo htmlspecialchars($takvim_url_ayar ?: ''); ?>" placeholder="Üniversite akademik takvim URL'sini girin...">
                <button onclick="syncTakvim()" class="btn-pasha btn-pasha-blue" id="takvimBtn">
                    <i class="fas fa-spider"></i> VERİYİ KAZI
                </button>
            </div>
            <small style="color:var(--text-muted); margin-top:10px; display:block;">Bot, bu URL'ye giderek HTML tablolarını okur ve tarihleri ayıklar.</small>
        </div>
        <div class="card-body" style="padding:0; overflow-x:auto;">
            <table class="modern-table">
                <thead><tr><th>Dönem</th><th>Tarih Aralığı</th><th>Akademik Faaliyet</th></tr></thead>
                <tbody>
                    <?php
                    $list = $db->query("SELECT * FROM bot_akademik_takvim ORDER BY baslangic_tarihi ASC")->fetchAll();
                    if(empty($list)): ?>
                        <tr><td colspan="3" style="text-align:center; padding:30px; color:#666;">Henüz bir akademik takvim çekilmedi. URL girip 'Veriyi Kazı' butonuna basın.</td></tr>
                    <?php else: 
                        foreach($list as $l): 
                            $badge = ($l['yariyil'] == 'Güz') ? 'badge-guz' : 'badge-bahar';
                            $bas = date('d.m.Y', strtotime($l['baslangic_tarihi']));
                            $bit = date('d.m.Y', strtotime($l['bitis_tarihi']));
                            $tarih_text = ($bas == $bit) ? $bas : "$bas - $bit";
                        ?>
                        <tr>
                            <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($l['yariyil']); ?> Yarıyılı</span></td>
                            <td style="color:#ddd; font-weight:600; white-space:nowrap;"><?php echo $tarih_text; ?></td>
                            <td style="color:var(--text-muted); font-size:0.95rem; font-weight:500;"><?php echo htmlspecialchars($l['faaliyet']); ?></td>
                        </tr>
                        <?php endforeach; 
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
async function syncYemek() {
    const btn = document.getElementById('yemekBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> BAĞLANILIYOR...';
    
    const fd = new FormData(); fd.append('action', 'sync_yemek');
    try {
        const res = await fetch('icerik?tab=yemek', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { alert('✅ ' + data.message); location.reload(); } 
        else { alert('❌ ' + data.message); }
    } catch (e) { alert('Sunucu hatası!'); } 
    finally { btn.disabled = false; btn.innerHTML = originalText; }
}

async function syncTakvim() {
    const url = document.getElementById('takvimUrl').value;
    if(!url) { alert("Lütfen bir URL girin!"); return; }

    const btn = document.getElementById('takvimBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> KAZILIYOR...';
    
    const fd = new FormData(); 
    fd.append('action', 'sync_takvim');
    fd.append('takvim_url', url);

    try {
        const res = await fetch('icerik?tab=takvim', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) { alert('✅ ' + data.message); location.reload(); } 
        else { alert('❌ ' + data.message); }
    } catch (e) { alert('Sunucu veya Parse hatası! HTML yapısı değişmiş olabilir.'); } 
    finally { btn.disabled = false; btn.innerHTML = originalText; }
}
</script>

<?php 
$page_content = ob_get_clean();

// 4. LAYOUT YOLU DÜZELTİLDİ
$layout_path = $base_path . '/admin/layouts/master_layouts.php';
if (file_exists($layout_path)) {
    require_once $layout_path; 
} else {
    echo "HATA: Layout dosyası bulunamadı: " . $layout_path;
}
?>
