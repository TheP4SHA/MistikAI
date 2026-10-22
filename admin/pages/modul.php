<?php
// admin/pages/modul.php - Modül (niyet) yönetimi
// Geliştirici araçları (tarayıcı içi PHP editörü ve SQL terminali) yalnız config/db.php'de
// $ADMIN_KOD_EDITORU === true VE kullanıcı tam yetkiliyse görünür ve çalışır; aksi halde sunucu 403 döner.
require_once __DIR__ . '/../_baslat.php';

const MODUL_KODU_DESENI = '/^[a-z0-9_]{2,40}$/';

$moduller_dizini = dirname(__DIR__, 2) . '/modules';
$kod_editoru = Panel::kodEditoruAcik();

/** Modül kodu formdan: küçük harfe çevrilir, geçersiz karakterler '_' olur; desene uymuyorsa null. */
function modulKoduAl(string $ham): ?string {
    $kod = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', trim($ham))));
    return preg_match(MODUL_KODU_DESENI, $kod) ? $kod : null;
}

/** Bağlantı alanı: boş ya da http(s) URL. */
function baglantiGecerli(string $url): bool {
    if ($url === '') {
        return true;
    }
    $sema = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return (bool)filter_var($url, FILTER_VALIDATE_URL) && in_array($sema, ['http', 'https'], true);
}

/**
 * Editörün yazabileceği tek yer: modules/<kod>.php. Kod deseni denetlenir, yol realpath ile
 * modules dizininin içinde olmalı (sembolik bağlantıyla dışarı çıkılamaz). Uygun değilse null.
 */
function eklentiYolu(string $dizin, string $kod): ?string {
    if (!preg_match(MODUL_KODU_DESENI, $kod)) {
        return null;
    }
    $kok = realpath($dizin);
    if ($kok === false || !is_dir($kok)) {
        return null;
    }
    $yol = $kok . DIRECTORY_SEPARATOR . $kod . '.php';
    if (file_exists($yol) || is_link($yol)) {
        $gercek = realpath($yol);
        if ($gercek === false || dirname($gercek) !== $kok) {
            return null;
        }
        return $gercek;
    }
    return $yol;
}

/** exec() kullanılabiliyorsa PHP CLI yolu, değilse null (sözdizimi denetimi atlanır). */
function phpCliYolu(): ?string {
    if (!function_exists('exec')) {
        return null;
    }
    $kapali = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $kapali, true)) {
        return null;
    }
    // php-fpm/cgi altında PHP_BINARY lint yapamaz; aynı kurulumun CLI ikilisini dene.
    foreach ([PHP_BINARY, PHP_BINDIR . '/php'] as $aday) {
        if ($aday && is_file($aday) && is_executable($aday) && !preg_match('/fpm|cgi/i', basename($aday))) {
            return $aday;
        }
    }
    return null;
}

/** Kodu kaydetmeden önce `php -l` ile denetler: [geçerli mi, mesaj]. */
function sozdizimiDenetle(string $kod): array {
    $php = phpCliYolu();
    if ($php === null) {
        return [true, 'atlandı'];
    }
    $gecici = tempnam(sys_get_temp_dir(), 'mistik_lint_');
    if ($gecici === false || file_put_contents($gecici, $kod) === false) {
        return [true, 'atlandı'];
    }
    $cikti = [];
    $durum = 1;
    exec(escapeshellarg($php) . ' -n -l ' . escapeshellarg($gecici) . ' 2>&1', $cikti, $durum);
    @unlink($gecici);
    if ($durum === 0) {
        return [true, 'geçti'];
    }
    $mesaj = trim(str_replace($gecici, 'modül dosyası', implode("\n", $cikti)));
    return [false, $mesaj !== '' ? $mesaj : 'PHP sözdizimi hatası.'];
}

// ======================================================================
// AJAX İŞLEMLERİ
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    // Geliştirici araçları: bayrak kapalıysa ya da kullanıcı tam yetkili değilse 403.
    if (in_array($action, ['execute_sql', 'get_plugin_code', 'save_plugin_code'], true) && !$kod_editoru) {
        Panel::json(['success' => false, 'message' => 'Geliştirici araçları kapalı. Yalnız $ADMIN_KOD_EDITORU açıkken tam yetkili yöneticiler kullanabilir.'], 403);
    }

    if ($action === 'modul_ekle' || $action === 'modul_duzenle') {
        $modul_id = (int)($_POST['modul_id'] ?? 0);
        $kodu = modulKoduAl((string)($_POST['modul_kodu'] ?? ''));
        $adi = trim((string)($_POST['modul_adi'] ?? ''));
        $cevap = trim((string)($_POST['cevap_metni'] ?? ''));
        $buton_metni = trim((string)($_POST['buton_metni'] ?? ''));
        $link_url = trim((string)($_POST['link_url'] ?? ''));

        if ($kodu === null || $adi === '' || ($action === 'modul_duzenle' && $modul_id <= 0)) {
            Panel::json(['success' => false, 'message' => 'Modül adı zorunlu; modül kodu 2-40 karakter olmalı (a-z, 0-9, _).']);
        }
        if (mb_strlen($adi, 'UTF-8') > 100 || mb_strlen($buton_metni, 'UTF-8') > 100) {
            Panel::json(['success' => false, 'message' => 'Modül adı ve buton metni en fazla 100 karakter olabilir.']);
        }
        if (!baglantiGecerli($link_url)) {
            Panel::json(['success' => false, 'message' => 'Bağlantı http:// ya da https:// ile başlayan geçerli bir URL olmalı.']);
        }
        try {
            $check = $db->prepare("SELECT id FROM bot_moduller WHERE modul_kodu = ? AND id <> ?");
            $check->execute([$kodu, $modul_id]);
            if ($check->fetch()) {
                Panel::json(['success' => false, 'message' => 'Bu modül kodu zaten kullanılıyor!']);
            }
            $degerler = [$kodu, $adi, $cevap === '' ? null : $cevap, $buton_metni === '' ? null : $buton_metni, $link_url === '' ? null : $link_url];
            if ($action === 'modul_ekle') {
                $db->prepare("INSERT INTO bot_moduller (modul_kodu, modul_adi, cevap_metni, buton_metni, link_url, aktif_mi) VALUES (?, ?, ?, ?, ?, 1)")->execute($degerler);
                Panel::kuralOnbelleginiTemizle();
                Panel::json(['success' => true, 'message' => 'Yetenek başarıyla sisteme eklendi!']);
            }
            $degerler[] = $modul_id;
            $db->prepare("UPDATE bot_moduller SET modul_kodu = ?, modul_adi = ?, cevap_metni = ?, buton_metni = ?, link_url = ? WHERE id = ?")->execute($degerler);
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true, 'message' => 'Modül başarıyla güncellendi!']);
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'modül kaydı')]);
        }
    }

    if ($action === 'toggle_status') {
        $yeni_durum = ($_POST['yeni_durum'] ?? '') === '1' ? 1 : 0;
        try {
            $db->prepare("UPDATE bot_moduller SET aktif_mi = ? WHERE id = ?")->execute([$yeni_durum, (int)($_POST['modul_id'] ?? 0)]);
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true, 'message' => 'Durum güncellendi!']);
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'modül durumu')]);
        }
    }

    if ($action === 'modul_sil') {
        $modul_id = (int)($_POST['modul_id'] ?? 0);
        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM bot_anahtar_kelimeler WHERE modul_id = ?")->execute([$modul_id]);
            $db->prepare("DELETE FROM bot_moduller WHERE id = ?")->execute([$modul_id]);
            $db->commit();
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true, 'message' => 'Modül ve içindeki tüm kelimeler kalıcı olarak silindi!']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'modül silme')]);
        }
    }

    // ------------------------------------------------------------------
    // Geliştirici araçları (buraya yalnız $kod_editoru true iken gelinir)
    // ------------------------------------------------------------------
    if ($action === 'execute_sql') {
        $sql_query = trim((string)($_POST['sql_query'] ?? ''));
        if ($sql_query === '') {
            Panel::json(['success' => false, 'message' => 'Sorgu alanı boş.']);
        }
        error_log('[MistikAI admin] SQL terminali, kullanıcı #' . (int)Panel::kullanici()['id'] . ': ' . mb_substr($sql_query, 0, 500, 'UTF-8'));
        try {
            if (preg_match('/^\s*(select|show|describe|desc|explain)\b/i', $sql_query)) {
                $results = $db->query($sql_query)->fetchAll(PDO::FETCH_ASSOC);
                Panel::json(['success' => true, 'type' => 'select', 'message' => count($results) . ' satır getirildi.', 'data' => array_slice($results, 0, 500)]);
            }
            $affected = $db->exec($sql_query);
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true, 'type' => 'exec', 'message' => "Sorgu çalıştırıldı! Etkilenen: $affected"]);
        } catch (PDOException $e) {
            error_log('[MistikAI admin] SQL terminali hatası: ' . $e->getMessage());
            Panel::json(['success' => false, 'message' => 'SQL hatası (SQLSTATE ' . $e->getCode() . '). Ayrıntı sunucu günlüğünde.']);
        }
    }

    if ($action === 'get_plugin_code') {
        $modul_kodu = (string)($_POST['modul_kodu'] ?? '');
        $file_path = eklentiYolu($moduller_dizini, $modul_kodu);
        if ($file_path === null) {
            Panel::json(['success' => false, 'message' => 'Geçersiz modül kodu.'], 400);
        }
        $code = is_file($file_path) ? (string)file_get_contents($file_path)
            : "<?php\n// $modul_kodu Modülü - Özel PHP İşleyicisi\n// \$clean_input : Kullanıcının yazdığı temizlenmiş mesaj\n// \$raw_input : Orijinal mesaj\n\nreturn \$this->response('Özel modül çalışıyor!');\n";
        Panel::json(['success' => true, 'code' => $code]);
    }

    if ($action === 'save_plugin_code') {
        $modul_kodu = (string)($_POST['modul_kodu'] ?? '');
        $php_code = (string)($_POST['php_code'] ?? '');
        $file_path = eklentiYolu($moduller_dizini, $modul_kodu);
        if ($file_path === null) {
            Panel::json(['success' => false, 'message' => 'Geçersiz modül kodu ya da modül dizini.'], 400);
        }
        $var = $db->prepare("SELECT 1 FROM bot_moduller WHERE modul_kodu = ?");
        $var->execute([$modul_kodu]);
        if (!$var->fetchColumn()) {
            Panel::json(['success' => false, 'message' => 'Bu kodla kayıtlı bir modül yok.'], 400);
        }

        // İçerik tamamen silindiyse dosyayı da sil; modül veritabanındaki metne döner.
        if (trim($php_code) === '') {
            if (is_file($file_path) && !unlink($file_path)) {
                Panel::json(['success' => false, 'message' => 'Dosya silinemedi (izinleri kontrol edin).']);
            }
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true, 'message' => 'Kod silindi. Modül statik (veritabanı) moduna döndü.']);
        }

        [$gecerli, $lint] = sozdizimiDenetle($php_code);
        if (!$gecerli) {
            Panel::json(['success' => false, 'message' => "Kaydedilmedi, PHP sözdizimi hatası:\n" . $lint]);
        }
        // Önce geçici dosyaya yaz, sonra taşı: motor yarım dosyayı require etmesin.
        $gecici = $file_path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($gecici, $php_code, LOCK_EX) === false || !rename($gecici, $file_path)) {
            @unlink($gecici);
            Panel::json(['success' => false, 'message' => 'Dosya yazılamadı (modules dizininin izinlerini kontrol edin).']);
        }
        error_log('[MistikAI admin] Modül dosyası güncellendi: modules/' . $modul_kodu . '.php, kullanıcı #' . (int)Panel::kullanici()['id']);
        Panel::kuralOnbelleginiTemizle();
        Panel::json(['success' => true, 'message' => 'Kod kaydedildi ve canlıya alındı (sözdizimi denetimi: ' . $lint . ').']);
    }

    Panel::json(['success' => false, 'message' => 'Bilinmeyen işlem.'], 400);
}

// ======================================================================
// VERİ ÇEKİMİ
// ======================================================================
$moduller = $db->query("
    SELECT m.*, COUNT(k.id) AS kelime_sayisi
    FROM bot_moduller m
    LEFT JOIN bot_anahtar_kelimeler k ON m.id = k.modul_id
    GROUP BY m.id
    ORDER BY m.id ASC
")->fetchAll();

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
    
    .m-answer p { margin: 0 0 0.4rem 0; }
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
        <?php if ($kod_editoru): ?>
        <button type="button" id="btnSql" class="btn-action" style="font-size: 1rem; padding: 0.8rem 1.5rem; border-color: var(--warning-color); color: var(--warning-color);">
            <i class="fas fa-terminal"></i> SQL (Root)
        </button>
        <?php endif; ?>

        <button type="button" id="btnYeniModul" class="btn-primary" style="font-size: 1rem; padding: 0.8rem 1.5rem;">
            <i class="fas fa-plus"></i> Yeni Niyet Oluştur
        </button>
    </div>
</div>

<div class="search-box">
    <i class="fas fa-search"></i>
    <input type="text" id="searchInput" placeholder="Modül adı veya kodu ile ara...">
</div>

<div class="modul-grid" id="modulesContainer">
    <?php foreach($moduller as $mod): 
        $is_aktif = (int)$mod['aktif_mi'] === 1;
        $has_plugin = is_file($moduller_dizini . '/' . $mod['modul_kodu'] . '.php');
        $has_link = !empty($mod['link_url']);
    ?>
    <div class="modul-card <?php echo $is_aktif ? '' : 'pasif'; ?>">
        <div class="m-header">
            <div class="m-title-area">
                <div class="m-title">
                    <i class="fas <?php echo $is_aktif ? 'fa-brain' : 'fa-power-off'; ?>"></i> 
                    <span class="searchable-title"><?php echo Guvenlik::e($mod['modul_adi']); ?></span>
                </div>
                <div class="m-code searchable-code">
                    <?php if($has_plugin): ?>
                        <span class="badge" style="background:rgba(139,92,246,0.2); color:var(--accent-color); margin-right:5px;" title="Bu modül dinamik PHP kodu ile çalışır."><i class="fas fa-bolt"></i> PHP</span>
                    <?php endif; ?>
                    _<?php echo Guvenlik::e($mod['modul_kodu']); ?>
                </div>
            </div>
            <div>
                <?php if($is_aktif): ?><span class="badge success">Aktif</span><?php else: ?><span class="badge danger">Pasif</span><?php endif; ?>
            </div>
        </div>
        
        <div class="m-body">
            <div class="m-stat">
                <span><i class="fas fa-graduation-cap" style="color: var(--text-muted);"></i> Öğrenilen Zeka:</span>
                <span class="m-stat-val"><?php echo (int)$mod['kelime_sayisi']; ?> kelime</span>
            </div>
            
            <div style="display:flex; flex-direction:column; gap: 5px;">
                <span style="font-size:0.8rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Botun Varsayılan Aksiyonu (önizleme):</span>
                <div class="m-answer" <?php echo $has_plugin ? 'style="opacity:0.5;"' : ''; ?>>
                    <?php if($has_link): ?> <span class="m-link-badge"><i class="fas fa-link"></i> Linkli</span> <?php endif; ?>
                    <?php echo $mod['cevap_metni'] ? Guvenlik::temizHtml($mod['cevap_metni']) : '<span style="color:var(--text-muted);">[Metin Yok]</span>'; ?>
                    <?php if($has_plugin): ?><br><small style="color:var(--accent-color); font-weight:bold;">(Bu metin yerine özel PHP kodu devreye girecektir)</small><?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="m-footer">
            <span style="font-size: 0.8rem; color: var(--text-muted);">ID: #<?php echo (int)$mod['id']; ?></span>
            
            <div style="display: flex; gap: 6px; align-items: center;">
                <?php if ($kod_editoru): ?>
                <button type="button" class="btn-action to-code js-ide" title="Geliştirici Stüdyosu (IDE)"
                    data-kodu="<?php echo Guvenlik::e($mod['modul_kodu']); ?>"
                    data-adi="<?php echo Guvenlik::e($mod['modul_adi']); ?>">
                    <i class="fas fa-code"></i>
                </button>
                <?php endif; ?>

                <button type="button" class="btn-action to-edit js-duzenle" title="Metinleri Düzenle"
                    data-id="<?php echo (int)$mod['id']; ?>"
                    data-adi="<?php echo Guvenlik::e($mod['modul_adi']); ?>"
                    data-kodu="<?php echo Guvenlik::e($mod['modul_kodu']); ?>"
                    data-cevap="<?php echo Guvenlik::e($mod['cevap_metni'] ?? ''); ?>"
                    data-buton="<?php echo Guvenlik::e($mod['buton_metni'] ?? ''); ?>"
                    data-link="<?php echo Guvenlik::e($mod['link_url'] ?? ''); ?>">
                    <i class="fas fa-edit"></i>
                </button>

                <a href="<?php echo Guvenlik::e(Panel::url('zeka', ['id' => $mod['id']])); ?>" class="btn-action to-teach" title="Zeka Laboratuvarı" style="text-decoration:none;">
                    <i class="fas fa-microchip"></i> Lab
                </a>
                
                <?php if($is_aktif): ?>
                    <button type="button" data-id="<?php echo (int)$mod['id']; ?>" data-durum="0" class="btn-action to-pasif js-durum" title="Devre dışı bırak"><i class="fas fa-pause"></i></button>
                <?php else: ?>
                    <button type="button" data-id="<?php echo (int)$mod['id']; ?>" data-durum="1" class="btn-action to-aktif js-durum" title="Aktifleştir"><i class="fas fa-play"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php 
$page_content = ob_get_clean();

// ======================================================================
// MODALLAR VE JS
// ======================================================================
$alan = 'width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:white; border-radius:0.5rem; outline:none;';
ob_start();
?>
<?php if ($kod_editoru): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.36.2/ace.js"></script>
<?php endif; ?>

<div class="mistik-modal-overlay" id="modulModal">
    <div class="mistik-modal-box">
        <div class="modal-header" style="display:flex; justify-content:space-between; margin-bottom:1rem;">
            <h2 style="font-size: 1.2rem; color: var(--accent-color);"><i class="fas fa-magic"></i> Yeni Niyet (Modül) Ekle</h2>
            <button type="button" data-kapat="modulModal" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="modulForm" class="js-modul-form">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="modul_ekle">
            <div style="margin-bottom: 1.2rem;"><label style="display:block; margin-bottom:0.3rem; color:white;">1. Modül Adı</label><input type="text" name="modul_adi" required maxlength="100" style="<?php echo $alan; ?>"></div>
            <div style="margin-bottom: 1.2rem;"><label style="display:block; margin-bottom:0.3rem; color:white;">2. Modül Kodu <small style="color:var(--text-muted);">(a-z, 0-9, _ / 2-40 karakter)</small></label><input type="text" name="modul_kodu" required maxlength="40" style="<?php echo $alan; ?> color:var(--accent-color); font-family:monospace;"></div>
            <div style="margin-bottom: 1.2rem;"><label style="display:block; margin-bottom:0.3rem; color:white;">3. Botun Metin Cevabı</label><textarea name="cevap_metni" rows="2" style="<?php echo $alan; ?> background:var(--bg-main);"></textarea></div>
            <div style="display:flex; gap:10px; margin-bottom: 1.2rem;">
                <div style="flex:1;"><label style="display:block; margin-bottom:0.3rem; color:white;">Buton Metni <small style="color:var(--text-muted);">(ops.)</small></label><input type="text" name="buton_metni" maxlength="100" style="<?php echo $alan; ?>"></div>
                <div style="flex:1;"><label style="display:block; margin-bottom:0.3rem; color:white;">Bağlantı <small style="color:var(--text-muted);">(ops.)</small></label><input type="url" name="link_url" placeholder="https://" style="<?php echo $alan; ?>"></div>
            </div>
            <button type="submit" style="width:100%; padding:1rem; background:var(--accent-color); color:white; border:none; border-radius:0.5rem; font-weight:bold; cursor:pointer;">Modülü Ekle</button>
            <div id="modulFormMsg" style="margin-top: 1rem; text-align: center;"></div>
        </form>
    </div>
</div>

<div class="mistik-modal-overlay" id="editModal">
    <div class="mistik-modal-box">
        <div class="modal-header" style="display:flex; justify-content:space-between; margin-bottom:1rem;">
            <h2 style="font-size: 1.2rem; color: var(--warning-color);"><i class="fas fa-edit"></i> Modülü Düzenle</h2>
            <button type="button" data-kapat="editModal" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="editForm" class="js-modul-form">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="modul_duzenle">
            <input type="hidden" name="modul_id" id="editId">
            <div style="margin-bottom: 1.2rem;"><label style="display:block; color:white;">Modül Adı</label><input type="text" name="modul_adi" id="editAdi" required maxlength="100" style="<?php echo $alan; ?>"></div>
            <div style="margin-bottom: 1.2rem;"><label style="display:block; color:white;">Modül Kodu</label><input type="text" name="modul_kodu" id="editKodu" required maxlength="40" style="<?php echo $alan; ?> color:var(--warning-color); font-family:monospace;"></div>
            <div style="margin-bottom: 1.2rem;"><label style="display:block; color:white;">Botun Metin Cevabı</label><textarea name="cevap_metni" id="editCevap" rows="3" style="<?php echo $alan; ?> background:var(--bg-main);"></textarea></div>
            <div style="display:flex; gap:10px; margin-bottom: 1.2rem;">
                <div style="flex:1;"><label style="display:block; color:white;">Buton Metni</label><input type="text" name="buton_metni" id="editButon" maxlength="100" style="<?php echo $alan; ?>"></div>
                <div style="flex:1;"><label style="display:block; color:white;">Bağlantı</label><input type="url" name="link_url" id="editLink" placeholder="https://" style="<?php echo $alan; ?>"></div>
            </div>
            <button type="submit" style="width:100%; padding:1rem; background:var(--warning-color); color:#000; border:none; border-radius:0.5rem; font-weight:bold; cursor:pointer;">Kaydet</button>
            <div id="editFormMsg" style="margin-top: 1rem; text-align: center;"></div>
        </form>
    </div>
</div>

<?php if ($kod_editoru): ?>
<div class="mistik-modal-overlay" id="ideModal">
    <div class="mistik-modal-box" style="width:900px; max-width:95%;">
        <div class="modal-header" style="display:flex; justify-content:space-between; margin-bottom:0.5rem;">
            <h2 style="font-size: 1.2rem; color: #10b981;"><i class="fas fa-code"></i> Geliştirici Stüdyosu: <span id="ideTitleName" style="color:white;"></span></h2>
            <button type="button" data-kapat="ideModal" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:10px;">Bu alana yazacağınız PHP kodları motor tarafından çalıştırılır. Değişkenler: <code>$clean_input</code> ve <code>$raw_input</code>. Kaydetmeden önce sözdizimi denetlenir. İşlemi silmek için içeriği tamamen boşaltıp kaydedin.</p>
        <input type="hidden" id="ideModulKodu">
        <div id="ideContainer"></div>
        <button type="button" id="btnSaveIde" style="width:100%; padding:1rem; margin-top:15px; background:#10b981; color:#000; border:none; border-radius:0.5rem; font-weight:bold; cursor:pointer; font-size:1rem;">
            <i class="fas fa-rocket"></i> Kodu Denetle ve Canlıya Al
        </button>
    </div>
</div>

<div class="mistik-modal-overlay" id="sqlModal">
    <div class="mistik-modal-box" style="width:700px;">
        <div class="modal-header" style="display:flex; justify-content:space-between; margin-bottom:1rem;"><h2 style="color:var(--warning-color);"><i class="fas fa-terminal"></i> SQL Terminali</h2><button type="button" data-kapat="sqlModal" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button></div>
        <form id="sqlForm">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="execute_sql">
            <textarea name="sql_query" class="sql-terminal" placeholder="-- Kodlar buraya"></textarea>
            <button type="submit" style="width:100%; padding:1rem; background:var(--warning-color); color:#000; border:none; border-radius:0.5rem; font-weight:bold; cursor:pointer; margin-top:1rem;">Çalıştır</button>
            <pre id="sqlResult" class="sql-result-box"></pre>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    const $ = id => document.getElementById(id);
    const ac = id => $(id).classList.add('active');

    // Arama
    $('searchInput').addEventListener('keyup', function () {
        const aranan = this.value.toLowerCase();
        document.querySelectorAll('.modul-card').forEach(card => {
            const title = card.querySelector('.searchable-title').innerText.toLowerCase();
            const code = card.querySelector('.searchable-code').innerText.toLowerCase();
            card.style.display = (title.includes(aranan) || code.includes(aranan)) ? 'flex' : 'none';
        });
    });

    $('btnYeniModul').addEventListener('click', function () {
        $('modulForm').reset(); $('modulFormMsg').textContent = ''; ac('modulModal');
    });

    document.addEventListener('click', async function (e) {
        if (e.target.classList.contains('mistik-modal-overlay')) { e.target.classList.remove('active'); return; }
        const kapat = e.target.closest('[data-kapat]');
        if (kapat) { $(kapat.dataset.kapat).classList.remove('active'); return; }

        const duzenle = e.target.closest('.js-duzenle');
        if (duzenle) {
            const d = duzenle.dataset;
            $('editFormMsg').textContent = '';
            $('editId').value = d.id; $('editAdi').value = d.adi; $('editKodu').value = d.kodu;
            $('editCevap').value = d.cevap; $('editButon').value = d.buton; $('editLink').value = d.link;
            ac('editModal');
            return;
        }

        const durum = e.target.closest('.js-durum');
        if (durum) {
            if (!confirm(durum.dataset.durum === '0' ? 'Devre dışı bırakılsın mı?' : 'Aktif edilsin mi?')) return;
            const fd = new FormData(); fd.append('action', 'toggle_status'); fd.append('modul_id', durum.dataset.id); fd.append('yeni_durum', durum.dataset.durum);
            const data = await MISTIK.istek('modul', fd);
            if (data.success) location.reload(); else alert(data.message);
            return;
        }

        const ide = e.target.closest('.js-ide');
        if (ide && window.ideAc) { window.ideAc(ide.dataset.kodu, ide.dataset.adi); }
    });

    document.querySelectorAll('.js-modul-form').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const msg = $(form.id + 'Msg');
            const btn = form.querySelector('button[type="submit"]'); const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> İşleniyor...'; btn.disabled = true;
            try {
                const data = await MISTIK.istek('modul', new FormData(form));
                MISTIK.mesaj(msg, data.message, data.success);
                if (data.success) { setTimeout(() => location.reload(), 1000); return; }
            } catch (err) { MISTIK.mesaj(msg, 'Sunucu Hatası!', false); }
            btn.innerHTML = originalText; btn.disabled = false;
        });
    });

<?php if ($kod_editoru): ?>
    // ---- Geliştirici araçları (yalnız $ADMIN_KOD_EDITORU + tam yetki) ----
    $('btnSql').addEventListener('click', function () { $('sqlResult').style.display = 'none'; ac('sqlModal'); });
    $('sqlForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const data = await MISTIK.istek('modul', new FormData(this));
        const rb = $('sqlResult');
        if (data.success && data.type === 'select') { rb.textContent = JSON.stringify(data.data, null, 2); rb.style.display = 'block'; }
        else { alert(data.message); }
    });

    let editor = null;
    window.addEventListener('load', function () {
        if (typeof ace !== 'undefined') {
            editor = ace.edit('ideContainer');
            editor.setTheme('ace/theme/monokai');
            editor.session.setMode('ace/mode/php');
            editor.setOptions({ fontSize: '14px', showPrintMargin: false });
        }
    });

    window.ideAc = async function (modul_kodu, modul_adi) {
        if (!editor) { alert('Kod editörü yüklenemedi.'); return; }
        $('ideTitleName').textContent = modul_adi + ' (' + modul_kodu + '.php)';
        $('ideModulKodu').value = modul_kodu;
        editor.setValue('// Kodlar sunucudan yükleniyor, lütfen bekleyin...', -1);
        ac('ideModal');
        const fd = new FormData(); fd.append('action', 'get_plugin_code'); fd.append('modul_kodu', modul_kodu);
        try {
            const data = await MISTIK.istek('modul', fd);
            editor.setValue(data.success ? data.code : '<?php echo '<?php'; ?>\n// ' + (data.message || 'Hata oluştu.') + '\n', -1);
        } catch (err) {
            editor.setValue('<?php echo '<?php'; ?>\n// Bağlantı hatası!\n', -1);
        }
    };

    $('btnSaveIde').addEventListener('click', async function () {
        const btn = this; const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sunucuya Yazılıyor...'; btn.disabled = true;
        const fd = new FormData();
        fd.append('action', 'save_plugin_code');
        fd.append('modul_kodu', $('ideModulKodu').value);
        fd.append('php_code', editor.getValue());
        try {
            const data = await MISTIK.istek('modul', fd);
            alert(data.message);
            if (data.success) location.reload();
        } catch (err) {
            alert('Sunucu ile iletişim kurulamadı.');
        } finally {
            btn.innerHTML = originalText; btn.disabled = false;
        }
    });
<?php endif; ?>
})();
</script>
<?php
$extra_js = ob_get_clean();

require_once __DIR__ . '/../layouts/master_layouts.php';
