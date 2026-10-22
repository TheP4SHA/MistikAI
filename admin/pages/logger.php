<?php
// admin/pages/logger.php - Blackbox: sohbet logları ve logdan öğretme
// Log içeriği ziyaretçilerden gelir; sayfadaki her değer kaçışlanır, JS'e yalnız data-* öznitelikleriyle aktarılır.
require_once __DIR__ . '/../_baslat.php';

// ======================================================================
// AJAX: Logdan / elle kelime öğret
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ogret') {
    $kelimeler = array_filter(array_map(
        fn($k) => mb_strtolower(trim($k), 'UTF-8'),
        explode(',', (string)($_POST['kelime'] ?? ''))
    ), fn($k) => $k !== '');
    $modul_id = (int)($_POST['modul_id'] ?? 0);
    $override = trim((string)($_POST['cevap_override'] ?? ''));
    $oncelik = 50;

    if (!$kelimeler || $modul_id <= 0) {
        Panel::json(['success' => false, 'message' => 'Kelime ve Modül alanları zorunludur.']);
    }
    foreach ($kelimeler as $k) {
        if (mb_strlen($k, 'UTF-8') > 100) {
            Panel::json(['success' => false, 'message' => 'Her kelime/cümle en fazla 100 karakter olabilir.']);
        }
    }
    $varMi = $db->prepare("SELECT 1 FROM bot_moduller WHERE id = ?");
    $varMi->execute([$modul_id]);
    if (!$varMi->fetchColumn()) {
        Panel::json(['success' => false, 'message' => 'Seçilen modül bulunamadı.']);
    }

    try {
        $eklenen = 0;
        $esgecilen = 0;
        $check = $db->prepare("SELECT id FROM bot_anahtar_kelimeler WHERE kelime = ? AND modul_id = ?");
        $insert = $db->prepare("INSERT INTO bot_anahtar_kelimeler (modul_id, kelime, oncelik, cevap_override, aktif_mi) VALUES (?, ?, ?, ?, 1)");
        $update_logs = $db->prepare("UPDATE bot_sohbet_loglari SET anlasildi_mi = 1, eslesen_modul_id = ? WHERE kullanici_mesaji = ? AND anlasildi_mi = 0");
        $db->beginTransaction();
        foreach (array_unique($kelimeler) as $k) {
            $check->execute([$k, $modul_id]);
            if ($check->fetch()) {
                $esgecilen++;
                continue;
            }
            $insert->execute([$modul_id, $k, $oncelik, $override === '' ? null : $override]);
            $update_logs->execute([$modul_id, $k]);
            $eklenen++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        Panel::json(['success' => false, 'message' => Panel::hata($e, 'logdan öğretme')]);
    }

    if ($eklenen > 0) {
        Panel::json(['success' => true, 'message' => "$eklenen kelime başarıyla öğretildi! ($esgecilen zaten vardı)"]);
    }
    Panel::json(['success' => false, 'message' => 'Yazdığınız kelimeler zaten bu modülde kayıtlı!']);
}

// ======================================================================
// FİLTRELEME VE SAYFALAMA
// ======================================================================
$limit = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$filter_status = in_array($_GET['status'] ?? '', ['success', 'fail'], true) ? $_GET['status'] : 'all';
$filter_search = trim((string)($_GET['q'] ?? ''));
$filter_session = trim((string)($_GET['sess'] ?? ''));

$where_clauses = ["1=1"];
$params = [];

if ($filter_status === 'success') { $where_clauses[] = "l.anlasildi_mi = 1"; }
elseif ($filter_status === 'fail') { $where_clauses[] = "l.anlasildi_mi = 0"; }

if ($filter_search !== '') {
    $where_clauses[] = "l.kullanici_mesaji LIKE :q";
    $params[':q'] = '%' . addcslashes($filter_search, '%_\\') . '%';
}

if ($filter_session !== '') {
    $where_clauses[] = "l.session_id = :sess";
    $params[':sess'] = $filter_session;
}

$where_sql = implode(' AND ', $where_clauses);

// Bir fazlasını çek: sonraki sayfa var mı?
$stmt_data = $db->prepare("
    SELECT l.*, m.modul_adi
    FROM bot_sohbet_loglari l
    LEFT JOIN bot_moduller m ON l.eslesen_modul_id = m.id
    WHERE $where_sql
    ORDER BY l.islem_tarihi DESC, l.id DESC
    LIMIT " . ($limit + 1) . " OFFSET " . (int)$offset);
$stmt_data->execute($params);
$raw_loglar = $stmt_data->fetchAll();
$sonraki_var = count($raw_loglar) > $limit;
$raw_loglar = array_slice($raw_loglar, 0, $limit);

// Ziyaretçiye (IP özeti) göre gruplama
$grouped_logs = [];
foreach ($raw_loglar as $log) {
    $grouped_logs[$log['session_id']][] = $log;
}

$tum_moduller = $db->query("SELECT id, modul_adi FROM bot_moduller WHERE aktif_mi = 1 ORDER BY modul_adi")->fetchAll();

/** Sayfalama bağlantısı: mevcut filtreler korunur. */
function loggerUrl(array $degisen): string {
    $sorgu = array_filter([
        'status' => $GLOBALS['filter_status'] !== 'all' ? $GLOBALS['filter_status'] : null,
        'q'      => $GLOBALS['filter_search'] ?: null,
        'sess'   => $GLOBALS['filter_session'] ?: null,
    ]);
    return Panel::url('logger', array_filter(array_merge($sorgu, $degisen), fn($v) => $v !== null && $v !== ''));
}

/** Tek log satırı. Tüm değerler Guvenlik::e() ile kaçışlanır; kullanıcı mesajı JS'e data-kelime ile gider. */
function renderLogRow(array $log, bool $is_main, int $group_id, int $log_count = 1): void {
    $e = [Guvenlik::class, 'e'];
    $is_success = (int)$log['anlasildi_mi'] === 1;
    $bot_cevabi = $log['bot_cevabi'] !== null ? trim(strip_tags($log['bot_cevabi'])) : 'Cevap loglanmadı.';
    if (mb_strlen($bot_cevabi, 'UTF-8') > 120) {
        $bot_cevabi = mb_substr($bot_cevabi, 0, 120, 'UTF-8') . '...';
    }
    $zaman = strtotime($log['islem_tarihi']);
    $row_class = $is_main ? 'main-row' : 'sub-row sub-group-' . $group_id;
    if (!$is_success) { $row_class .= ' row-fail'; }

    echo '<tr class="' . $row_class . '">';

    // 1. Tarih
    echo '<td><div style="font-weight:500;">' . date('d.m.Y', $zaman) . '</div>'
       . '<div style="font-size:0.8rem; color:var(--text-muted);"><i class="far fa-clock"></i> ' . date('H:i:s', $zaman) . '</div></td>';

    // 2. Ziyaretçi (IP özeti)
    echo '<td>';
    if ($is_main) {
        $sess = (string)$log['session_id'];
        echo '<a href="' . $e(Panel::url('logger', ['sess' => $sess])) . '" class="ip-badge" title="' . $e($sess) . '"><i class="fas fa-user-secret"></i> '
           . $e(mb_substr($sess, 0, 12, 'UTF-8')) . (mb_strlen($sess, 'UTF-8') > 12 ? '…' : '') . '</a>';
        if ($log_count > 1) {
            echo '<div style="margin-top:8px;"><button type="button" class="toggle-btn js-grup" data-grup="' . $group_id . '">'
               . '<i class="fas fa-chevron-down" id="icon-' . $group_id . '"></i> +' . ($log_count - 1) . ' Geçmiş</button></div>';
        }
    } else {
        echo '<span style="color:var(--text-muted); opacity:0.5;"><i class="fas fa-level-up-alt fa-rotate-90"></i> Önceki</span>';
    }
    echo '</td>';

    // 3. Yazışma
    echo '<td><div class="chat-user">"' . $e($log['kullanici_mesaji']) . '"</div>'
       . '<div class="chat-bot"><i class="fas fa-robot"></i> ' . $e($bot_cevabi) . '</div></td>';

    // 4. Niyet ve güven
    echo '<td>';
    $niyet = (string)($log['algilanan_niyet'] ?? '');
    if ($is_success) {
        if (!empty($log['modul_adi'])) {
            $isim = $log['modul_adi'];
        } elseif ($niyet !== '' && $niyet !== 'bilinmiyor') {
            $isim = ucwords(str_replace('_', ' ', $niyet));
        } else {
            $isim = 'Genel Sohbet';
        }
        echo '<div style="color: var(--accent-color); font-weight:500; margin-bottom:5px; font-size:0.9rem;"><i class="fas fa-check-circle"></i> ' . $e($isim) . '</div>';
    } else {
        $hata_mesaji = ($niyet !== '' && $niyet !== 'bilinmiyor') ? 'Modül Yok (' . $niyet . ')' : 'Bulunamadı';
        echo '<div style="color: var(--danger-color); font-size:0.85rem; margin-bottom:5px;"><i class="fas fa-exclamation-triangle"></i> ' . $e($hata_mesaji) . '</div>';
    }
    $skor = (int)$log['guven_skoru'];
    $rozet = $skor >= 70 ? 'success' : ($skor >= 30 ? 'warning' : 'danger');
    echo '<span class="badge ' . $rozet . '">%' . $skor . '</span>';
    echo '</td>';

    // 5. Aksiyon (mesaj data-kelime özniteliğinde; tıklama addEventListener ile yakalanır)
    echo '<td>';
    if (!$is_success) {
        echo '<button type="button" data-kelime="' . $e($log['kullanici_mesaji']) . '" class="btn-primary js-ogret" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;"><i class="fas fa-graduation-cap"></i> Öğret</button>';
    } else {
        echo '<button type="button" data-kelime="' . $e($log['kullanici_mesaji']) . '" class="btn-outline js-ogret" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;"><i class="fas fa-edit"></i> Düzelt</button>';
    }
    echo '</td>';

    echo '</tr>';
}

// ======================================================================
// SAYFA BİLGİLERİ VE CSS
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
    .pager { display: flex; gap: 10px; justify-content: flex-end; margin-bottom: 2rem; }
    .pager a { color: var(--text-main); background: var(--bg-panel); border: 1px solid var(--border-color); padding: 0.5rem 1rem; border-radius: 0.5rem; text-decoration: none; }
    .form-text { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem; display: block; }
";

ob_start(); 
?>

<div class="page-header">
    <div>
        <h1>Blackbox <span style="color: var(--text-muted); font-weight:400; font-size: 1.2rem;">/ Derin Log Analizi</span></h1>
        <p style="color: var(--text-muted); margin-top: 5px;">Ziyaretçi bazlı derin analiz ve yapay zeka eğitim modülü. Ziyaretçiler IP yerine tuzlu IP özetiyle tutulur.</p>
    </div>
    <div>
        <button type="button" id="btnManuel" class="btn-primary" style="font-size: 1rem; padding: 0.8rem 1.5rem;">
            <i class="fas fa-plus"></i> Manuel Öğret
        </button>
    </div>
</div>

<form method="GET" class="filter-bar" action="<?php echo Guvenlik::e(Panel::url('logger')); ?>">
    <div class="filter-group" style="flex: 0 0 150px;">
        <select name="status" class="filter-select">
            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>Tüm Loglar</option>
            <option value="success" <?php echo $filter_status === 'success' ? 'selected' : ''; ?>>✅ Anlaşılanlar</option>
            <option value="fail" <?php echo $filter_status === 'fail' ? 'selected' : ''; ?>>❌ Kör Noktalar</option>
        </select>
    </div>
    <div class="filter-group">
        <i class="fas fa-search" style="color: var(--text-muted); position:absolute; margin-left:10px;"></i>
        <input type="text" name="q" class="filter-input" placeholder="Kelime veya cümle ara..." value="<?php echo Guvenlik::e($filter_search); ?>" style="padding-left: 35px;">
    </div>
    <?php if ($filter_session !== ''): ?>
    <div class="filter-group" style="flex: 0 0 auto;">
        <span class="badge warning"><i class="fas fa-user-secret"></i> Ziyaretçi: <?php echo Guvenlik::e(mb_substr($filter_session, 0, 12, 'UTF-8')); ?>… <a href="<?php echo Guvenlik::e(Panel::url('logger')); ?>" style="color:white; margin-left:5px;"><i class="fas fa-times"></i></a></span>
        <input type="hidden" name="sess" value="<?php echo Guvenlik::e($filter_session); ?>">
    </div>
    <?php endif; ?>
    <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Filtrele</button>
</form>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th width="140">Tarih / Saat</th>
                <th width="150">Ziyaretçi (IP özeti)</th>
                <th>Yazışma (Kullanıcı & Bot)</th>
                <th width="160">Niyet & Güven</th>
                <th width="120">Aksiyon</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($grouped_logs)): ?>
                <tr><td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-muted);">Log bulunamadı.</td></tr>
            <?php else:
                $group_id = 0;
                foreach ($grouped_logs as $logs) {
                    $group_id++;
                    $log_count = count($logs);
                    renderLogRow($logs[0], true, $group_id, $log_count);
                    for ($i = 1; $i < $log_count; $i++) {
                        renderLogRow($logs[$i], false, $group_id);
                    }
                }
            endif; ?>
        </tbody>
    </table>
</div>

<div class="pager">
    <?php if ($page > 1): ?><a href="<?php echo Guvenlik::e(loggerUrl(['page' => $page - 1])); ?>"><i class="fas fa-chevron-left"></i> Önceki</a><?php endif; ?>
    <?php if ($sonraki_var): ?><a href="<?php echo Guvenlik::e(loggerUrl(['page' => $page + 1])); ?>">Sonraki <i class="fas fa-chevron-right"></i></a><?php endif; ?>
</div>

<?php 
$page_content = ob_get_clean();

// ======================================================================
// EĞİTİM MODALI VE JS
// ======================================================================
ob_start();
?>
<div class="mistik-modal-overlay" id="ogretModal">
    <div class="mistik-modal-box">
        <div class="modal-header" style="display:flex; justify-content:space-between; margin-bottom:1.5rem;">
            <h2 id="modalTitle" style="font-size: 1.2rem; color: var(--accent-color);"><i class="fas fa-microchip"></i> Bota Kelime Öğret</h2>
            <button type="button" id="btnModalKapat" style="background:none; border:none; color:white; font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="ogretForm">
            <?php echo Guvenlik::csrfAlani(); ?>
            <input type="hidden" name="action" value="ogret">
            
            <div class="form-group" style="margin-bottom: 1.2rem;">
                <label style="display:block; margin-bottom:0.5rem; color:var(--text-muted);">Öğretilecek Kelimeler / Cümleler</label>
                <textarea id="modalKelime" name="kelime" required rows="3" style="width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:white; border-radius:0.5rem; outline:none; font-family:monospace; resize:none;" placeholder="Örn: vizeler ne zaman, sınavlar, ara sınav"></textarea>
                <span class="form-text">Birden fazla kelime eklemek için aralarına <b>virgül (,)</b> koyun.</span>
            </div>
            
            <div class="form-group" style="margin-bottom: 1.2rem;">
                <label style="display:block; margin-bottom:0.5rem; color:var(--text-muted);">Hangi Modüle (Niyete) Bağlansın?</label>
                <select name="modul_id" required style="width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:white; border-radius:0.5rem; outline:none;">
                    <option value="">-- Modül Seçiniz --</option>
                    <?php foreach ($tum_moduller as $mod): ?>
                        <option value="<?php echo (int)$mod['id']; ?>"><?php echo Guvenlik::e($mod['modul_adi']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 1.2rem; background: rgba(139, 92, 246, 0.05); padding: 1rem; border-radius: 0.5rem; border: 1px dashed var(--accent-color);">
                <label style="display:block; margin-bottom:0.5rem; color:var(--accent-color);"><i class="fas fa-bolt"></i> Özel Cevap (Override - Opsiyonel)</label>
                <textarea name="cevap_override" rows="2" style="width:100%; padding:0.8rem; background:var(--bg-input); border:1px solid var(--border-color); color:white; border-radius:0.5rem; outline:none; resize:none;" placeholder="Bu kelime yazıldığında verilecek özel cevap..."></textarea>
            </div>
            
            <button type="submit" id="btnKaydet" style="width:100%; padding:1rem; background:var(--accent-color); color:white; border:none; border-radius:0.5rem; font-weight:bold; cursor:pointer;"><i class="fas fa-upload"></i> Zekayı Güncelle</button>
            <div id="ogretFormMsg" style="margin-top: 1rem; text-align: center; font-size: 0.95rem;"></div>
        </form>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('ogretModal');
    const inputKelimeler = document.getElementById('modalKelime');
    const formMsg = document.getElementById('ogretFormMsg');
    const modalTitle = document.getElementById('modalTitle');

    function baslik(ikon, metin) {
        modalTitle.textContent = '';
        const i = document.createElement('i');
        i.className = 'fas ' + ikon;
        modalTitle.append(i, ' ' + metin);
    }
    function ac(kelime, manuel) {
        baslik(manuel ? 'fa-plus' : 'fa-microchip', manuel ? 'Çoklu Kelime / Örüntü Ekle' : 'Logdan Zeka Öğret');
        inputKelimeler.value = kelime;   // değer olarak atanır, HTML olarak yorumlanmaz
        formMsg.textContent = '';
        modal.classList.add('active');
        inputKelimeler.focus();
    }
    function kapat() { modal.classList.remove('active'); }

    // Öğret / Düzelt ve grup aç-kapa: satır içi onclick yok, olay yetkilendirmesi var.
    document.addEventListener('click', function (e) {
        const ogret = e.target.closest('.js-ogret');
        if (ogret) { ac(ogret.dataset.kelime || '', false); return; }
        const grup = e.target.closest('.js-grup');
        if (grup) {
            const id = grup.dataset.grup;
            document.querySelectorAll('.sub-group-' + id).forEach(r => r.classList.toggle('open'));
            const icon = document.getElementById('icon-' + id);
            if (icon) { icon.classList.toggle('fa-chevron-down'); icon.classList.toggle('fa-chevron-up'); }
        }
    });
    document.getElementById('btnManuel').addEventListener('click', () => ac('', true));
    document.getElementById('btnModalKapat').addEventListener('click', kapat);
    modal.addEventListener('click', e => { if (e.target === modal) kapat(); });

    document.getElementById('ogretForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = document.getElementById('btnKaydet');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Kaydediliyor...';
        btn.disabled = true;
        try {
            const data = await MISTIK.istek('logger', new FormData(this));
            MISTIK.mesaj(formMsg, data.message, data.success);
            if (data.success) { setTimeout(() => location.reload(), 1500); return; }
        } catch (error) {
            MISTIK.mesaj(formMsg, 'Sunucu hatası!', false);
        }
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
})();
</script>
<?php
$extra_js = ob_get_clean();

require_once __DIR__ . '/../layouts/master_layouts.php';
