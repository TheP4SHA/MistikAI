<?php
// admin/pages/zeka.php - Zeka Laboratuvarı: bir modülün anahtar kelime kuralları
// Kurallar loglardan (ziyaretçi metni) da gelebilir; çıktıda her değer kaçışlanır, JS'e data-* ile aktarılır.
require_once __DIR__ . '/../_baslat.php';

const ZAMAN_KURALLARI = ['sabah', 'gun', 'aksam', 'gece'];

// Hangi modüldeyiz?
$modul_id = (int)($_GET['id'] ?? 0);
if ($modul_id <= 0) { Panel::yonlendir('modul'); }

$stmt = $db->prepare("SELECT * FROM bot_moduller WHERE id = ?");
$stmt->execute([$modul_id]);
$modul = $stmt->fetch();
if (!$modul) { Panel::yonlendir('modul'); }

/** Formdan gelen kural alanlarını doğrular: [öncelik, zaman, override]. */
function kuralAlanlari(): array {
    $oncelik = max(1, min(100, (int)($_POST['oncelik'] ?? 50)));
    $zaman = (string)($_POST['zaman_kontrol'] ?? '');
    $zaman = in_array($zaman, ZAMAN_KURALLARI, true) ? $zaman : null;
    $override = trim((string)($_POST['cevap_override'] ?? ''));
    return [$oncelik, $zaman, $override === '' ? null : $override];
}

// ======================================================================
// AJAX İŞLEMLERİ (tüm işlemler bu modülün kurallarıyla sınırlı)
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Çoklu kelime ekleme (virgülle ayrılmış)
    if ($_POST['action'] === 'zeka_ekle') {
        $kelimeler = array_unique(array_filter(array_map(
            fn($k) => mb_strtolower(trim($k), 'UTF-8'),
            explode(',', (string)($_POST['kelime'] ?? ''))
        ), fn($k) => $k !== ''));
        [$oncelik, $zaman, $override] = kuralAlanlari();

        if (!$kelimeler) {
            Panel::json(['success' => false, 'message' => 'Kelime alanı boş olamaz.']);
        }
        foreach ($kelimeler as $k) {
            if (mb_strlen($k, 'UTF-8') > 100) {
                Panel::json(['success' => false, 'message' => 'Her kelime/cümle en fazla 100 karakter olabilir.']);
            }
        }
        try {
            $eklenen = 0;
            $esgecilen = 0;
            $check = $db->prepare("SELECT id FROM bot_anahtar_kelimeler WHERE kelime = ? AND modul_id = ?");
            $insert = $db->prepare("INSERT INTO bot_anahtar_kelimeler (modul_id, kelime, oncelik, zaman_kontrol, cevap_override, aktif_mi) VALUES (?, ?, ?, ?, ?, 1)");
            foreach ($kelimeler as $k) {
                $check->execute([$k, $modul_id]);
                if ($check->fetch()) {
                    $esgecilen++;
                    continue;
                }
                $insert->execute([$modul_id, $k, $oncelik, $zaman, $override]);
                $eklenen++;
            }
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'zeka ekleme')]);
        }
        if ($eklenen > 0) {
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true, 'message' => "$eklenen yeni zeka kuralı başarıyla eklendi! ($esgecilen zaten vardı)"]);
        }
        Panel::json(['success' => false, 'message' => 'Yazdığınız kelimeler zaten sistemde mevcut.']);
    }

    if ($_POST['action'] === 'zeka_duzenle') {
        $kelime_id = (int)($_POST['kelime_id'] ?? 0);
        $kelime = mb_strtolower(trim((string)($_POST['kelime'] ?? '')), 'UTF-8');
        [$oncelik, $zaman, $override] = kuralAlanlari();
        if ($kelime === '' || mb_strlen($kelime, 'UTF-8') > 100) {
            Panel::json(['success' => false, 'message' => 'Kelime 1-100 karakter olmalı.']);
        }
        try {
            $update = $db->prepare("UPDATE bot_anahtar_kelimeler SET kelime = ?, oncelik = ?, zaman_kontrol = ?, cevap_override = ? WHERE id = ? AND modul_id = ?");
            $update->execute([$kelime, $oncelik, $zaman, $override, $kelime_id, $modul_id]);
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true, 'message' => 'Güncellendi!']);
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'zeka düzenleme')]);
        }
    }

    if ($_POST['action'] === 'zeka_sil') {
        try {
            $db->prepare("DELETE FROM bot_anahtar_kelimeler WHERE id = ? AND modul_id = ?")->execute([(int)($_POST['kelime_id'] ?? 0), $modul_id]);
            Panel::kuralOnbelleginiTemizle();
            Panel::json(['success' => true]);
        } catch (Throwable $e) {
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'zeka silme')]);
        }
    }

    Panel::json(['success' => false, 'message' => 'Bilinmeyen işlem.'], 400);
}

$kelimeler = $db->prepare("SELECT * FROM bot_anahtar_kelimeler WHERE modul_id = ? ORDER BY oncelik DESC, id DESC");
$kelimeler->execute([$modul_id]);
$kelimeler = $kelimeler->fetchAll();

$page_title = "Zeka Laboratuvarı";
$active_menu = "moduller";

// ======================================================================
// 4. CSS BÖLÜMÜ
// ======================================================================
ob_start();
?>
    .modern-panel { background: rgba(var(--bg-panel-rgb), 0.6); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.05); border-radius: 1.25rem; box-shadow: 0 10px 30px rgba(0,0,0,0.2); overflow: hidden; transition: 0.3s; }
    .modern-panel:hover { border-color: rgba(139, 92, 246, 0.3); box-shadow: 0 15px 40px rgba(0,0,0,0.3); }

    .panel-header { padding: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); background: rgba(0,0,0,0.1); }
    .panel-title { font-size: 1.1rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 10px; text-transform: uppercase; letter-spacing: 1px; }
    .panel-body { padding: 2rem; }

    .form-group { margin-bottom: 1.5rem; }
    .form-label { display:block; margin-bottom:0.6rem; color:var(--text-muted); font-size:0.85rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;}
    .modern-input { width:100%; padding:1rem; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.08); color:white; border-radius:0.75rem; outline:none; font-size:0.95rem; transition: 0.3s; }
    .modern-input:focus { border-color: var(--accent-color); background: rgba(0,0,0,0.3); box-shadow: 0 0 15px rgba(139, 92, 246, 0.15); }
    
    .advanced-settings-box { border: 1px solid rgba(245, 158, 11, 0.2); padding: 1.5rem; border-radius: 1rem; background: rgba(245, 158, 11, 0.02); margin-bottom: 2rem; position: relative; }
    .advanced-settings-box::before { content:''; position:absolute; top:0; left:1.5rem; right:1.5rem; height:2px; background: linear-gradient(90deg, transparent, var(--warning-color), transparent); }
    .advanced-title { font-size: 0.95rem; font-weight: 700; color: var(--warning-color); margin-bottom: 1.2rem; display: flex; align-items: center; gap: 8px; }

    .rule-list { display: flex; flex-direction: column; gap: 1rem; padding: 1.5rem; }
    .rule-item { display: grid; grid-template-columns: 1fr auto auto auto; gap: 1.5rem; align-items: center; padding: 1.2rem; background: rgba(255,255,255,0.02); border-radius: 1rem; border: 1px solid transparent; transition: 0.3s; }
    .rule-item:hover { background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.08); transform: scale(1.01); }
    
    .rule-word { font-weight: 700; font-size: 1.1rem; color: var(--text-main); }
    
    .badge-modern { padding: 0.4rem 0.8rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 5px; border: 1px solid transparent;}
    .badge-time { background: rgba(139, 92, 246, 0.1); color: var(--accent-color); border-color: rgba(139, 92, 246, 0.2); }
    .badge-prio-high { background: rgba(16, 185, 129, 0.1); color: var(--success-color); border-color: rgba(16, 185, 129, 0.2); }
    .badge-prio-med { background: rgba(245, 158, 11, 0.1); color: var(--warning-color); border-color: rgba(245, 158, 11, 0.2); }
    
    .override-onizleme { display: inline; max-height: 4.5em; overflow: hidden; }
    .override-onizleme p, .override-onizleme div { display: inline; margin: 0; }
    .override-balloon { font-size: 0.8rem; color: var(--text-muted); background: rgba(0,0,0,0.2); padding: 0.8rem; border-radius: 0.75rem; margin-top: 8px; border-left: 3px solid var(--warning-color); }
    
    .action-group { display: flex; gap: 8px; }
    .btn-circle { width: 36px; height: 36px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: white; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.3s; font-size: 0.9rem;}
    .btn-circle:hover { transform: translateY(-3px); }
    .btn-circle.edit { color: var(--warning-color); border-color: rgba(245, 158, 11, 0.3); }
    .btn-circle.edit:hover { background: var(--warning-color); color: #000; box-shadow: 0 0 15px rgba(245, 158, 11, 0.3); }
    .btn-circle.delete { color: var(--danger-color); border-color: rgba(239, 68, 68, 0.3); }
    .btn-circle.delete:hover { background: var(--danger-color); color: white; box-shadow: 0 0 15px rgba(239, 68, 68, 0.3); }

    /* Modal CSS */
    .mistik-modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.8); display: none; align-items: center; justify-content: center; z-index: 9999; opacity: 0; transition: opacity 0.3s ease; }
    .mistik-modal-overlay.active { display: flex; opacity: 1; }
    .mistik-modal-box { background: var(--bg-panel); border: 1px solid var(--border-color); border-radius: 1rem; width: 550px; padding: 2rem; transform: translateY(-20px); transition: transform 0.3s ease; max-height:90vh; overflow-y:auto; }
    .mistik-modal-overlay.active .mistik-modal-box { transform: translateY(0); }
<?php
$extra_css = ob_get_clean();

// ======================================================================
// 5. HTML GÖVDE (PAGE CONTENT)
// ======================================================================
ob_start(); 
?>

<div class="page-header" style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 15px;">
    <div>
        <a href="<?php echo Guvenlik::e(Panel::url('modul')); ?>" class="badge-modern badge-time" style="text-decoration:none; margin-bottom: 10px;"><i class="fas fa-arrow-left"></i> Modüllere Dön</a>
        <h1 style="margin-top: 5px; font-size: 2rem;">Zeka Laboratuvarı</h1>
        <p style="color: var(--text-main); font-size: 1.1rem; margin-top: 5px;">Hedef Niyet: <strong style="color:var(--accent-color); background:rgba(139, 92, 246, 0.1); padding: 2px 8px; border-radius:4px;">_<?php echo Guvenlik::e($modul['modul_kodu']); ?></strong> (<?php echo Guvenlik::e($modul['modul_adi']); ?>)</p>
    </div>
    <div>
        <button type="button" id="btnYeniKural" style="background: var(--success-color); color: #000; border: none; padding: 1rem 1.5rem; border-radius: 1rem; font-size: 1rem; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 10px; box-shadow: 0 5px 20px rgba(16, 185, 129, 0.4); transition: 0.3s;">
            <i class="fas fa-plus-circle" style="font-size: 1.2rem;"></i> Yeni Zeka Kuralı Ekle
        </button>
    </div>
</div>

<div class="modern-panel">
    <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
        <div class="panel-title"><i class="fas fa-stream" style="color:var(--accent-color);"></i> Mevcut Zeka Kuralları</div>
        <div class="badge-modern badge-time"><?php echo count($kelimeler); ?> Kural Tanımlı</div>
    </div>
    
    <div class="rule-list">
        <?php if(empty($kelimeler)): ?>
            <div style="text-align:center; padding: 4rem 2rem; color:var(--text-muted); background:rgba(0,0,0,0.2); border-radius:1rem;">
                <i class="fas fa-ghost" style="font-size:3rem; margin-bottom:1rem; opacity:0.3;"></i><br>
                Bu niyet için henüz bir zeka kuralı tanımlanmamış.<br>Sağ üstteki yeşil butonu kullanarak ilk kuralı ekleyin.
            </div>
        <?php endif; ?>
        
        <?php foreach($kelimeler as $k): 
            $prio_badge = $k['oncelik'] >= 80 ? 'badge-prio-high' : ($k['oncelik'] >= 50 ? 'badge-prio-med' : '');
        ?>
        <div class="rule-item">
            <div>
                <div class="rule-word">"<?php echo Guvenlik::e($k['kelime']); ?>"</div>
                <?php if($k['cevap_override']): ?>
                    <div class="override-balloon" title="Özel Cevap Tanımlı (önizleme, güvenli HTML)">
                        <i class="fas fa-comment-dots" style="color:var(--warning-color); margin-right:5px;"></i>
                        <div class="override-onizleme"><?php echo Guvenlik::temizHtml($k['cevap_override']); ?></div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="badge-modern <?php echo $prio_badge; ?>" style="justify-content:center; width:60px;">P: <?php echo (int)$k['oncelik']; ?></div>

            <div>
                <?php if($k['zaman_kontrol']): ?>
                    <span class="badge-modern badge-time"><i class="far fa-clock"></i> <?php echo Guvenlik::e(ucfirst($k['zaman_kontrol'])); ?></span>
                <?php else: ?>
                    <span style="color:var(--text-muted); font-size:0.8rem; opacity:0.5;">--</span>
                <?php endif; ?>
            </div>
            
            <div class="action-group">
                <button type="button" class="btn-circle edit js-duzenle" title="Düzenle"
                    data-id="<?php echo (int)$k['id']; ?>"
                    data-kelime="<?php echo Guvenlik::e($k['kelime']); ?>"
                    data-oncelik="<?php echo (int)$k['oncelik']; ?>"
                    data-zaman="<?php echo Guvenlik::e($k['zaman_kontrol'] ?? ''); ?>"
                    data-override="<?php echo Guvenlik::e($k['cevap_override'] ?? ''); ?>"><i class="fas fa-pencil-alt"></i></button>
                <button type="button" class="btn-circle delete js-sil" data-id="<?php echo (int)$k['id']; ?>" title="Sil"><i class="fas fa-trash-alt"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php 
$page_content = ob_get_clean();

// ======================================================================
// 6. GİZLİ MODALLAR (EKLEME VE DÜZENLEME) VE JS 
// ======================================================================
ob_start();
?>
<div class="mistik-modal-overlay" id="addModal">
    <div class="mistik-modal-box modern-panel" style="background: var(--bg-panel); padding:0; width:550px;">
        <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
            <div class="panel-title"><i class="fas fa-plus-circle" style="color:var(--success-color);"></i> Yeni Zeka Kuralı Ekle</div>
            <button type="button" data-kapat="addModal" class="btn-circle" style="border:none; background:none;"><i class="fas fa-times"></i></button>
        </div>
        <div class="panel-body">
            <form id="addForm" class="js-kural-form">
                <?php echo Guvenlik::csrfAlani(); ?>
                <input type="hidden" name="action" value="zeka_ekle">
                
                <div class="form-group">
                    <label class="form-label">Tetikleyici Kelime / Örüntü</label>
                    <input type="text" name="kelime" id="add_kelime" required class="modern-input" placeholder="Örn: günaydın, merhaba, selam">
                    <span style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem; display: block;">Birden fazla kelime eklemek için aralarına virgül (,) koyunuz.</span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 1.5rem;">
                    <div>
                        <label class="form-label">Öncelik (1-100)</label>
                        <input type="number" name="oncelik" value="50" min="1" max="100" class="modern-input" style="color:var(--success-color); font-weight:bold; text-align:center;">
                    </div>
                    <div>
                        <label class="form-label">Zaman Kuralı</label>
                        <select name="zaman_kontrol" class="modern-input">
                            <option value="">-- Yok --</option>
                            <option value="sabah">Sabah (05-12)</option>
                            <option value="gun">Gün İçi (12-18)</option>
                            <option value="aksam">Akşam (18-24)</option>
                            <option value="gece">Gece (00-05)</option>
                        </select>
                    </div>
                </div>

                <div class="advanced-settings-box" style="border-color: rgba(16, 185, 129, 0.2); background: rgba(16, 185, 129, 0.02); margin-bottom:1.5rem;">
                    <div class="advanced-title" style="color:var(--success-color);"><i class="fas fa-bolt"></i> Özel Cevap (Override)</div>
                    <textarea name="cevap_override" rows="3" class="modern-input" style="background:var(--bg-main); resize:vertical;" placeholder="Boş bırakılırsa modülün varsayılan cevabı verilir..."></textarea>
                </div>
                
                <button type="submit" class="btn-primary" style="width:100%; padding:1rem; justify-content:center; background:var(--success-color); color:#000; border-radius:0.75rem;"><i class="fas fa-save"></i> Kuralları Veritabanına Yaz</button>
                <div id="addFormMsg" style="margin-top: 1rem; text-align: center; font-size: 0.9rem;"></div>
            </form>
        </div>
    </div>
</div>

<div class="mistik-modal-overlay" id="editModal">
    <div class="mistik-modal-box modern-panel" style="background: var(--bg-panel); padding:0; width:550px;">
        <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
            <div class="panel-title"><i class="fas fa-edit" style="color:var(--warning-color);"></i> Kuralı Düzenle</div>
            <button type="button" data-kapat="editModal" class="btn-circle" style="border:none; background:none;"><i class="fas fa-times"></i></button>
        </div>
        <div class="panel-body">
            <form id="editForm" class="js-kural-form">
                <?php echo Guvenlik::csrfAlani(); ?>
                <input type="hidden" name="action" value="zeka_duzenle">
                <input type="hidden" name="kelime_id" id="edit_id">
                
                <div class="form-group">
                    <label class="form-label">Kelime / Örüntü</label>
                    <input type="text" name="kelime" id="edit_kelime" required class="modern-input" style="color:var(--warning-color); font-weight:bold;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 1.5rem;">
                    <div>
                        <label class="form-label">Öncelik (1-100)</label>
                        <input type="number" name="oncelik" id="edit_oncelik" min="1" max="100" class="modern-input" style="text-align:center;">
                    </div>
                    <div>
                        <label class="form-label">Zaman Kuralı</label>
                        <select name="zaman_kontrol" id="edit_zaman" class="modern-input">
                            <option value="">-- Yok --</option>
                            <option value="sabah">Sabah</option>
                            <option value="gun">Gün İçi</option>
                            <option value="aksam">Akşam</option>
                            <option value="gece">Gece</option>
                        </select>
                    </div>
                </div>

                <div class="advanced-settings-box" style="margin-bottom:1.5rem;">
                    <div class="advanced-title"><i class="fas fa-bolt"></i> Özel Cevap (Override)</div>
                    <textarea name="cevap_override" id="edit_override" rows="4" class="modern-input" style="background:var(--bg-main); resize:vertical;"></textarea>
                </div>
                
                <button type="submit" class="btn-primary" style="width:100%; padding:1rem; justify-content:center; background:var(--warning-color); color:#000; border-radius:0.75rem;"><i class="fas fa-check"></i> Değişiklikleri Kaydet</button>
                <div id="editFormMsg" style="margin-top: 1rem; text-align: center; font-size: 0.9rem;"></div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const MODUL_ID = <?php echo (int)$modul_id; ?>;
    const $ = id => document.getElementById(id);

    $('btnYeniKural').addEventListener('click', function () {
        $('addForm').reset();
        $('addFormMsg').textContent = '';
        $('addModal').classList.add('active');
        setTimeout(() => $('add_kelime').focus(), 100);
    });

    document.addEventListener('click', async function (e) {
        const kapat = e.target.closest('[data-kapat]');
        if (kapat) { $(kapat.dataset.kapat).classList.remove('active'); return; }

        // Değerler data-* özniteliklerinden okunur ve form alanlarına değer olarak yazılır.
        const duzenle = e.target.closest('.js-duzenle');
        if (duzenle) {
            const d = duzenle.dataset;
            $('edit_id').value = d.id;
            $('edit_kelime').value = d.kelime;
            $('edit_oncelik').value = d.oncelik;
            $('edit_zaman').value = d.zaman;
            $('edit_override').value = d.override;
            $('editFormMsg').textContent = '';
            $('editModal').classList.add('active');
            return;
        }

        const sil = e.target.closest('.js-sil');
        if (sil) {
            if (!confirm('Bu zeka kuralını silmek istediğinize emin misiniz? Bot bu kelimeyi unutacak.')) return;
            const fd = new FormData(); fd.append('action', 'zeka_sil'); fd.append('kelime_id', sil.dataset.id);
            const data = await MISTIK.istek('zeka', fd, {id: MODUL_ID});
            if (data.success) location.reload(); else alert(data.message || 'Silme esnasında bir hata oluştu.');
        }
    });

    document.querySelectorAll('.js-kural-form').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const msg = $(form.id + 'Msg');
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> İşleniyor...'; btn.disabled = true;
            try {
                const data = await MISTIK.istek('zeka', new FormData(form), {id: MODUL_ID});
                MISTIK.mesaj(msg, data.message, data.success);
                if (data.success) { setTimeout(() => location.reload(), 1000); return; }
            } catch (err) {
                MISTIK.mesaj(msg, 'Sunucu Hatası!', false);
            }
            btn.innerHTML = originalText; btn.disabled = false;
        });
    });
})();
</script>
<?php
$extra_js = ob_get_clean();

require_once __DIR__ . '/../layouts/master_layouts.php';
