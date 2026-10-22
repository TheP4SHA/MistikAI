<?php
// /data/admin/front/ders.php
require_once __DIR__ . '/../_baslat.php';

// ======================================================================
// AJAX İŞLEMLERİ
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // TOPLU METİN (KOMUT) KAYIT İŞLEMİ
    if ($_POST['action'] === 'save_bulk_command') {
        try {
            $bolum_id = (int)$_POST['bolum_id'];
            $sinif = (int)$_POST['sinif'];
            $payload = json_decode($_POST['payload'], true);

            if (empty($payload)) {
                throw new Exception("Kaydedilecek geçerli bir ders bulunamadı. Lütfen formatı kontrol edin.");
            }

            // 1. ADIM: Bölüme ait Fakülte ID'sini bul (Veritabanı kısıtlamasını aşmak için)
            $fak_stmt = $db->prepare("SELECT fakulte_id FROM bot_bolumler WHERE id = ?");
            $fak_stmt->execute([$bolum_id]);
            $fakulte_id = $fak_stmt->fetchColumn();

            if (!$fakulte_id) {
                // Eğer bolum_id'nin fakültesi yoksa manuel 1 vererek patlamasını önleyelim
                $fakulte_id = 1; 
            }

            // 2. ADIM: Seçilen bölüm ve sınıfın eski derslerini sil
            $db->prepare("DELETE FROM bot_ders_programi WHERE bolum_id = ? AND sinif = ?")->execute([$bolum_id, $sinif]);
            
            // 3. ADIM: Fakülte ID'sini de dahil ederek SQL sorgusunu hazırla
            $ins = $db->prepare("INSERT INTO bot_ders_programi (bolum_id, fakulte_id, sinif, gun, baslangic_saati, bitis_saati, ders_adi, ogretim_uyesi, derslik) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $eklenen = 0;
            foreach ($payload as $p) {
                // Saat formatlarını veritabanına (TIME) uygun hale getir (Örn: 10:15 -> 10:15:00)
                $baslangic = trim($p['baslangic']) . ':00';
                $bitis = trim($p['bitis']) . ':00';

                $ins->execute([
                    $bolum_id, 
                    $fakulte_id,
                    $sinif, 
                    trim($p['gun']), 
                    $baslangic, 
                    $bitis, 
                    trim($p['ders_adi']), 
                    trim($p['hoca']), 
                    trim($p['mekan'])
                ]);
                $eklenen++;
            }

            echo json_encode(['success' => true, 'message' => "$eklenen adet ders $sinif. Sınıf programına başarıyla işlendi!"]);
        } catch (Exception $e) { 
            echo json_encode(['success' => false, 'message' => $e->getMessage()]); 
        }
        exit;
    }

    // TEK KAYIT SİLME
    if ($_POST['action'] === 'delete_item') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM bot_ders_programi WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
}

$page_title = "Command Center | Ders Programı";
$active_menu = "ders";
ob_start();
?>
    /* --- MODERN TERMINAL CSS --- */
    .hub-nav { display: flex; gap: 10px; margin-bottom: 2rem; background: rgba(255,255,255,0.05); padding: 10px; border-radius: 15px; }
    .hub-tab { flex:1; padding: 15px; border-radius: 10px; cursor: pointer; text-align: center; font-weight: 800; color: #888; transition: 0.3s; text-decoration: none; display: block; border: 1px solid transparent; }
    .hub-tab.active { background: var(--accent-color); color: #fff; box-shadow: 0 5px 15px rgba(139, 92, 246, 0.2); border-color:rgba(139,92,246,0.3); }
    .hub-tab:not(.active):hover { background: rgba(255,255,255,0.1); color: #ddd; }
    
    .card-pasha { background: #1a1d21; border-radius: 20px; border: 1px solid #333; overflow: hidden; margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    .card-pasha-head { padding: 20px 25px; border-bottom: 1px solid #333; background: rgba(255,255,255,0.02); display: flex; justify-content: space-between; align-items: center; }
    
    .modern-input { background: #0d0f12; border: 1px solid #444; color: white; padding: 12px 15px; border-radius: 8px; width: 100%; font-size: 1rem; transition: 0.3s; }
    .modern-input:focus { border-color: var(--accent-color); outline:none; }
    .input-label { display: block; margin-bottom: 8px; color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; }

    .terminal-textarea { width: 100%; height: 300px; background: #050505; color: #10b981; border: 1px solid #333; border-radius: 10px; padding: 15px; font-family: monospace; font-size: 0.95rem; resize: vertical; line-height: 1.6; }
    .terminal-textarea:focus { border-color: #10b981; outline: none; box-shadow: 0 0 15px rgba(16, 185, 129, 0.1); }

    .btn-pasha { border:none; padding: 15px 25px; border-radius: 10px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-size: 1rem; transition: 0.2s; }
    .btn-pasha:hover { transform: translateY(-2px); }
    .btn-pasha-green { background: var(--success-color); color: #000; }
    .btn-pasha-blue { background: var(--accent-color); color: #fff; }
    .btn-pasha-danger { background: rgba(239, 68, 68, 0.15); color: var(--danger-color); border: 1px solid rgba(239, 68, 68, 0.3); padding: 8px 12px; }
    
    .modern-table { width: 100%; border-collapse: collapse; }
    .modern-table th { text-align: left; padding: 15px; font-size: 0.8rem; color: #666; border-bottom: 1px solid #333; text-transform:uppercase; background: rgba(0,0,0,0.2); }
    .modern-table td { padding: 15px; border-bottom: 1px solid #222; vertical-align: middle; }

    .info-box { background: rgba(139, 92, 246, 0.05); border: 1px dashed var(--accent-color); padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; color: #ccc; }

    #mistikOverlay { position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:9999; display:none; align-items:center; justify-content:center; backdrop-filter: blur(10px); }
<?php
$extra_css = ob_get_clean();

ob_start(); 
?>

<div class="hub-container" style="max-width:1400px; margin:0 auto;">
    <div class="page-header" style="margin-bottom: 2rem;">
        <h1>Mistik Command Center <span style="color:var(--accent-color);">/</span> Pasha</h1>
        <p style="color:var(--text-muted);">Yapay Zeka Destekli Toplu Metin (XML/JSON Tipi) Giriş Terminali</p>
    </div>

    <div class="hub-nav">
        <a href="<?php echo Guvenlik::e(Panel::url('icerik', ['tab' => 'yemek'])); ?>" class="hub-tab">🍱 YEMEKHANE</a>
        <a href="<?php echo Guvenlik::e(Panel::url('icerik', ['tab' => 'takvim'])); ?>" class="hub-tab">📅 AKADEMİK TAKVİM</a>
        <a href="<?php echo Guvenlik::e(Panel::url('ders')); ?>" class="hub-tab active">📚 DERS PROGRAMI</a>
    </div>

    <div class="card-pasha">
        <div class="card-pasha-head">
            <h3 style="margin:0; color:var(--accent-color);"><i class="fas fa-terminal"></i> AI Komut Ekranı</h3>
        </div>
        <div class="card-body" style="padding:25px;">
            
            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <div style="flex: 2;">
                    <label class="input-label">Hedef Bölüm / Program</label>
                    <select id="cmd_bolum" class="modern-input">
                        <?php 
                        $bolumler = $db->query("SELECT * FROM bot_bolumler ORDER BY ad")->fetchAll();
                        foreach($bolumler as $b) echo "<option value='{$b['id']}'>{$b['ad']}</option>";
                        ?>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label class="input-label">Hedef Sınıf</label>
                    <select id="cmd_sinif" class="modern-input" style="border-color: var(--accent-color);">
                        <option value="1">1. Sınıf Dersleri</option>
                        <option value="2">2. Sınıf Dersleri</option>
                    </select>
                </div>
            </div>

            <div class="info-box">
                <strong style="color:var(--accent-color);">💡 Sistem Formatı:</strong> MistikAI'dan aldığın metni aşağıya yapıştır. Her ders bloğunu ayırmak için araya bir boşluk veya <code>---</code> koyabilirsin.
            </div>

            <label class="input-label">MistikAI Çıktısını Buraya Yapıştırın</label>
            <textarea id="bulkText" class="terminal-textarea" placeholder="Ders Adı: BIL114 Bilgisayar Donanımı
Akademisyeni: Öğr. Gör. Mehmet KELEŞ
Mekan: Bilgisayar Laboratuvarı 2
Başlama/Bitiş Tarihi: 10:15 - 12:00
Günü: Pazartesi
---
Ders Adı: BIL112 Görsel Programlama 1
Akademisyeni: Dr. Öğr. Üyesi Emre YILDIRIM
Mekan: Bilgisayar Laboratuvarı 1
Başlama/Bitiş Tarihi: 14:15 - 17:00
Günü: Pazartesi"></textarea>

            <button onclick="parseCommandText()" class="btn-pasha btn-pasha-blue" style="width:100%; margin-top:20px;">
                <i class="fas fa-magic"></i> METNİ AYRIŞTIR VE TABLOYA DÖK
            </button>

            <div id="previewArea" style="display:none; margin-top:30px; border:2px solid var(--success-color); border-radius:15px; padding:20px; background:rgba(16,185,129,0.05);">
                <h3 style="color:var(--success-color); margin-bottom:15px;"><i class="fas fa-check-circle"></i> Komutlar Başarıyla Çözümlendi</h3>
                <div style="max-height:400px; overflow-y:auto; border-radius:10px; background:#000;">
                    <table class="modern-table">
                        <thead><tr><th>Gün</th><th>Saat</th><th>Ders Adı</th><th>Akademisyen / Mekan</th></tr></thead>
                        <tbody id="previewTableBody"></tbody>
                    </table>
                </div>
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button onclick="saveParsedData()" id="saveBtn" class="btn-pasha btn-pasha-green" style="flex:2;"><i class="fas fa-database"></i> ONAYLA VE VERİTABANINA YAZ</button>
                    <button onclick="document.getElementById('previewArea').style.display='none'" class="btn-pasha" style="background:#333; color:#fff; flex:1;">İPTAL</button>
                </div>
            </div>

        </div>
    </div>

    <div class="card-pasha">
         <div class="card-pasha-head">
             <h3 style="margin:0;"><i class="fas fa-server" style="color:var(--accent-color);"></i> Sisteme Kayıtlı Dersler</h3>
         </div>
         <div class="card-body" style="padding:0; overflow-x: auto;">
            <table class="modern-table">
                <thead><tr><th>Bölüm & Sınıf</th><th>Gün</th><th>Saat</th><th>Ders Adı</th><th>Akademisyen / Mekan</th><th style="text-align:right;">İşlem</th></tr></thead>
                <tbody>
                    <?php
                    $exist = $db->query("
                        SELECT d.*, b.ad as bolum_adi 
                        FROM bot_ders_programi d
                        LEFT JOIN bot_bolumler b ON d.bolum_id = b.id
                        ORDER BY b.ad, d.sinif, FIELD(d.gun, 'Pazartesi','Salı','Çarşamba','Perşembe','Cuma'), d.baslangic_saati
                    ")->fetchAll();
                    
                    if(empty($exist)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:30px; color:#666;">Sistemde kayıtlı ders bulunmuyor.</td></tr>
                    <?php else:
                        foreach($exist as $e): ?>
                        <tr id="row_<?php echo $e['id']; ?>">
                            <td>
                                <div style="font-size:0.8rem; color:#888; margin-bottom:4px;"><?php echo mb_substr($e['bolum_adi'] ?? 'Bilinmiyor', 0, 25); ?>...</div>
                                <span style="background:rgba(139,92,246,0.1); color:var(--accent-color); padding:3px 8px; border-radius:6px; font-weight:bold; font-size:0.75rem;"><?php echo $e['sinif']; ?>. SINIF</span>
                            </td>
                            <td style="font-weight:700; color:#ddd;"><?php echo $e['gun']; ?></td>
                            <td>
                                <div style="color:var(--success-color); font-weight:bold; font-family:monospace;"><?php echo substr($e['baslangic_saati'],0,5); ?></div>
                                <div style="color:var(--danger-color); font-weight:bold; font-family:monospace;"><?php echo substr($e['bitis_saati'],0,5); ?></div>
                            </td>
                            <td><b style="font-size:1.05rem;"><?php echo htmlspecialchars($e['ders_adi']); ?></b></td>
                            <td>
                                <?php echo htmlspecialchars($e['ogretim_uyesi']); ?> 
                                <?php if(!empty($e['derslik'])) echo "<br><small style='color:var(--text-muted);'><i class='fas fa-map-marker-alt'></i> ".htmlspecialchars($e['derslik'])."</small>"; ?>
                            </td>
                            <td style="text-align:right;"><button onclick="askDel(<?php echo $e['id']; ?>)" class="btn-pasha btn-pasha-danger"><i class="fas fa-trash"></i></button></td>
                        </tr>
                        <?php endforeach; 
                    endif; ?>
                </tbody>
            </table>
         </div>
    </div>
</div>

<div id="mistikOverlay">
    <div style="background:#111; border:1px solid #333; padding:40px; border-radius:25px; width:400px; text-align:center;">
        <i class="fas fa-exclamation-triangle" style="font-size:3.5rem; color:var(--danger-color); margin-bottom:20px;"></i>
        <h3>Emin misin Pasha?</h3>
        <p style="color:#888; margin-bottom:30px;">Ders sonsuza dek veritabanından silinecek.</p>
        <div style="display:flex; gap:10px; justify-content:center;">
            <button id="delBtn" class="btn-pasha btn-pasha-green" style="padding: 12px 40px;">SİL</button>
            <button onclick="document.getElementById('mistikOverlay').style.display='none'" class="btn-pasha" style="background:#222; color:#fff;">VAZGEÇ</button>
        </div>
    </div>
</div>

<script>
let parsedData = [];

function parseCommandText() {
    const rawText = document.getElementById('bulkText').value;
    if(rawText.trim() === '') {
        alert("Pasha, önce terminale verileri yapıştır!");
        return;
    }

    parsedData = [];
    const blocks = rawText.split(/\n\s*\n|---+/);

    blocks.forEach(block => {
        if(!block.trim()) return;
        
        const dersMatch = block.match(/Ders Ad[ıi]:\s*(.+)/i);
        const hocaMatch = block.match(/Akademisyen[i]?:\s*(.+)/i);
        const mekanMatch = block.match(/(?:Mekan|Derslik|Laboratuvar):\s*(.+)/i);
        const saatMatch = block.match(/(?:Ba[şs]lama\/Biti[şs] Tarihi|Saat):\s*(\d{1,2}[:.]\d{2})\s*[-/]\s*(\d{1,2}[:.]\d{2})/i);
        const gunMatch = block.match(/G[üu]n[ü]?:\s*(.+)/i);

        if(dersMatch && saatMatch && gunMatch) {
            parsedData.push({
                ders_adi: dersMatch[1].trim(),
                hoca: hocaMatch ? hocaMatch[1].trim() : '',
                mekan: mekanMatch ? mekanMatch[1].trim() : '',
                baslangic: saatMatch[1].replace('.', ':').trim(),
                bitis: saatMatch[2].replace('.', ':').trim(),
                gun: gunMatch[1].trim()
            });
        }
    });

    if(parsedData.length === 0) {
        alert("Metinden hiçbir veri algılanamadı. Lütfen formata uygun yapıştırdığından emin ol.");
        return;
    }

    const tbody = document.getElementById('previewTableBody');
    tbody.innerHTML = '';
    
    parsedData.forEach(d => {
        tbody.innerHTML += `
            <tr>
                <td style="font-weight:bold;">${d.gun}</td>
                <td><span style="color:var(--success-color); font-weight:bold;">${d.baslangic}</span> - <span style="color:var(--danger-color); font-weight:bold;">${d.bitis}</span></td>
                <td><b>${d.ders_adi}</b></td>
                <td>${d.hoca} <br> <small style="color:#aaa;">${d.mekan}</small></td>
            </tr>
        `;
    });

    document.getElementById('previewArea').style.display = 'block';
}

async function saveParsedData() {
    const btn = document.getElementById('saveBtn');
    const bolum_id = document.getElementById('cmd_bolum').value;
    const sinif = document.getElementById('cmd_sinif').value;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> KAYDEDİLİYOR...';

    const fd = new FormData();
    fd.append('action', 'save_bulk_command');
    fd.append('bolum_id', bolum_id);
    fd.append('sinif', sinif);
    fd.append('payload', JSON.stringify(parsedData));

    try {
        const data = await MISTIK.istek('ders', fd);
        
        if(data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert("Hata: " + data.message);
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-database"></i> ONAYLA VE VERİTABANINA YAZ';
        }
    } catch(err) {
        alert("Sunucu bağlantı hatası!");
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-database"></i> ONAYLA VE VERİTABANINA YAZ';
    }
}

let deleteTarget = null;
function askDel(id) { deleteTarget = id; document.getElementById('mistikOverlay').style.display = 'flex'; }
document.getElementById('delBtn').onclick = async () => {
    const fd = new FormData(); fd.append('action', 'delete_item'); fd.append('id', deleteTarget);
    const data = await MISTIK.istek('ders', fd);
    if (data.success) { location.reload(); } else { alert(data.message); }
};
</script>

<?php 
$page_content = ob_get_clean();

require_once __DIR__ . '/../layouts/master_layouts.php';
