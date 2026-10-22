<?php
// admin/pages/dashboard.php - Komuta Merkezi
require_once __DIR__ . '/../_baslat.php';

// ======================================================================
// 2. ULTRA DETAYLI İSTATİSTİKLER (SQL)
// ======================================================================

// --- ZAMAN VE KPI (Temel Göstergeler) ---
$toplam_mesaj = (int)$db->query("SELECT COUNT(*) FROM bot_sohbet_loglari")->fetchColumn();
$basarili_genel = (int)$db->query("SELECT COUNT(*) FROM bot_sohbet_loglari WHERE anlasildi_mi = 1")->fetchColumn();
$basarisiz_genel = $toplam_mesaj - $basarili_genel;
$basari_orani_genel = $toplam_mesaj > 0 ? round(($basarili_genel / $toplam_mesaj) * 100) : 0;

$bugun_mesaj = (int)$db->query("SELECT COUNT(*) FROM bot_sohbet_loglari WHERE DATE(islem_tarihi) = CURDATE()")->fetchColumn();
$basarili_bugun = (int)$db->query("SELECT COUNT(*) FROM bot_sohbet_loglari WHERE anlasildi_mi = 1 AND DATE(islem_tarihi) = CURDATE()")->fetchColumn();
$basarisiz_bugun = $bugun_mesaj - $basarili_bugun;
$basari_orani_bugun = $bugun_mesaj > 0 ? round(($basarili_bugun / $bugun_mesaj) * 100) : 0;

// --- SİSTEM KAPASİTESİ ---
$aktif_modul_sayisi = (int)$db->query("SELECT COUNT(*) FROM bot_moduller WHERE aktif_mi = 1")->fetchColumn();
$ogrenilen_kelime = (int)$db->query("SELECT COUNT(*) FROM bot_anahtar_kelimeler WHERE aktif_mi = 1")->fetchColumn();

// --- SON CANLI AKIŞ (Son 6 Mesaj) ---
$son_loglar = $db->query("
    SELECT l.*, m.modul_adi 
    FROM bot_sohbet_loglari l 
    LEFT JOIN bot_moduller m ON l.eslesen_modul_id = m.id 
    ORDER BY l.islem_tarihi DESC LIMIT 6
")->fetchAll();

// --- MODÜL DAĞILIMI (Pasta Dilimi verisi) ---
$modul_dagilimi = $db->query("
    SELECT m.modul_adi, COUNT(l.id) as adet 
    FROM bot_sohbet_loglari l 
    JOIN bot_moduller m ON l.eslesen_modul_id = m.id 
    GROUP BY m.id ORDER BY adet DESC LIMIT 5
")->fetchAll();

// --- TOP 5 BAŞARILI / BAŞARISIZ ---
$top_basarili = $db->query("SELECT kullanici_mesaji, COUNT(*) as adet FROM bot_sohbet_loglari WHERE anlasildi_mi = 1 GROUP BY kullanici_mesaji ORDER BY adet DESC LIMIT 5")->fetchAll();
$top_basarisiz = $db->query("SELECT kullanici_mesaji, COUNT(*) as adet FROM bot_sohbet_loglari WHERE anlasildi_mi = 0 GROUP BY kullanici_mesaji ORDER BY adet DESC LIMIT 5")->fetchAll();

// 3. SAYFA BİLGİLERİNİ AYARLA
$page_title = "Komuta Merkezi";
$active_menu = "dashboard";

// İskelete gönderilecek Jilet Gibi CSS (Bento Grid Mimarisi)
$extra_css = "
    /* BENTO GRID SİSTEMİ */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    
    /* KPI KARTLARI (4 Sütun) */
    .kpi-card { background: var(--bg-panel); padding: 1.5rem; border-radius: 1rem; border: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.3s; }
    .kpi-card:hover { transform: translateY(-3px); border-color: var(--accent-color); }
    .kpi-header { display: flex; justify-content: space-between; align-items: center; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; font-weight: 600; margin-bottom: 1rem;}
    .kpi-icon { width: 35px; height: 35px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
    .kpi-value { font-size: 2.2rem; font-weight: 700; color: var(--text-main); line-height: 1; }
    .kpi-sub { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem; display: flex; align-items: center; gap: 5px; }

    /* RENK TEMALARI */
    .bg-primary-light { background: rgba(139, 92, 246, 0.15); color: var(--accent-color); }
    .bg-success-light { background: rgba(16, 185, 129, 0.15); color: var(--success-color); }
    .bg-danger-light { background: rgba(239, 68, 68, 0.15); color: var(--danger-color); }
    .bg-warning-light { background: rgba(245, 158, 11, 0.15); color: var(--warning-color); }

    /* İÇERİK KUTULARI (Bento Box) */
    .bento-box { background: var(--bg-panel); border-radius: 1rem; border: 1px solid var(--border-color); padding: 1.5rem; display: flex; flex-direction: column;}
    .box-title { font-size: 1rem; font-weight: 600; margin-bottom: 1.2rem; display: flex; align-items: center; justify-content: space-between; padding-bottom: 0.8rem; border-bottom: 1px solid rgba(255,255,255,0.05);}
    
    /* PROGRESS BAR */
    .progress-track { width: 100%; height: 6px; background: rgba(255,255,255,0.05); border-radius: 3px; margin-top: 5px; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 3px; }
    
    /* LİSTELER */
    .data-list { display: flex; flex-direction: column; gap: 0.5rem; flex: 1; overflow-y: auto; }
    .data-item { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem; background: rgba(255,255,255,0.02); border-radius: 0.5rem; border: 1px solid transparent;}
    .data-item:hover { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.1); }
    .item-text { font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; gap: 10px; }
    .item-count { font-size: 0.8rem; font-weight: 600; padding: 0.2rem 0.6rem; background: rgba(255,255,255,0.1); border-radius: 1rem; }

    /* CANLI AKIŞ ÖZEL */
    .live-feed-item { padding: 1rem; border-left: 3px solid var(--border-color); margin-left: 5px; position: relative; }
    .live-feed-item::before { content: ''; position: absolute; left: -6px; top: 1.2rem; width: 9px; height: 9px; border-radius: 50%; background: var(--bg-main); border: 2px solid var(--border-color); }
    .live-feed-item.success::before { border-color: var(--success-color); background: var(--success-color); box-shadow: 0 0 8px rgba(16,185,129,0.5); }
    .live-feed-item.danger::before { border-color: var(--danger-color); background: var(--danger-color); box-shadow: 0 0 8px rgba(239,68,68,0.5); }
    .live-time { font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.3rem; }
    .live-msg { font-weight: 500; font-size: 0.95rem; margin-bottom: 0.3rem;}
    .live-intent { font-size: 0.8rem; color: var(--accent-color); }

    /* RESPONSIVE GRID KONTROLLERİ */
    .col-3 { grid-column: span 3; } .col-4 { grid-column: span 4; } .col-6 { grid-column: span 6; } .col-8 { grid-column: span 8; } .col-12 { grid-column: span 12; }
    @media (max-width: 1200px) { .col-3 { grid-column: span 6; } .col-4 { grid-column: span 6; } .col-8 { grid-column: span 12; } }
    @media (max-width: 768px) { .col-3, .col-4, .col-6, .col-8 { grid-column: span 12; } }
";

ob_start(); 
?>

<div class="page-header">
    <div>
        <h1>MistikAI Komuta Merkezi 🚀</h1>
        <p style="color: var(--text-muted); margin-top: 5px;">Gerçek zamanlı yapay zeka performansı ve kullanıcı etkileşimleri.</p>
    </div>
    <div style="text-align: right;">
        <span class="badge" style="background: var(--bg-input); font-size: 0.9rem; padding: 0.5rem 1rem;">
            <i class="fas fa-clock" style="color:var(--accent-color);"></i> <?php echo date('d.m.Y H:i'); ?>
        </span>
    </div>
</div>

<div class="dashboard-grid">
    <div class="kpi-card col-3">
        <div class="kpi-header"><span>Bugünkü Etkileşim</span> <div class="kpi-icon bg-primary-light"><i class="fas fa-comment-dots"></i></div></div>
        <div>
            <div class="kpi-value"><?php echo number_format($bugun_mesaj); ?></div>
            <div class="kpi-sub"><span style="color: var(--text-main);">Genel: <?php echo number_format($toplam_mesaj); ?></span></div>
        </div>
    </div>
    
    <div class="kpi-card col-3">
        <div class="kpi-header"><span>Bugünkü Zeka Başarısı</span> <div class="kpi-icon bg-success-light"><i class="fas fa-bolt"></i></div></div>
        <div>
            <div class="kpi-value" style="color: var(--success-color);">%<?php echo $basari_orani_bugun; ?></div>
            <div class="progress-track"><div class="progress-fill" style="width: <?php echo $basari_orani_bugun; ?>%; background: var(--success-color);"></div></div>
            <div class="kpi-sub">Genel Başarı: %<?php echo $basari_orani_genel; ?></div>
        </div>
    </div>

    <div class="kpi-card col-3">
        <div class="kpi-header"><span>Bugünkü Kör Nokta</span> <div class="kpi-icon bg-danger-light"><i class="fas fa-bug"></i></div></div>
        <div>
            <div class="kpi-value" style="color: var(--danger-color);"><?php echo number_format($basarisiz_bugun); ?></div>
            <div class="kpi-sub"><i class="fas fa-exclamation-triangle"></i> Acil eğitilmesi gerekenler</div>
        </div>
    </div>

    <div class="kpi-card col-3">
        <div class="kpi-header"><span>Sistem Kapasitesi</span> <div class="kpi-icon bg-warning-light"><i class="fas fa-database"></i></div></div>
        <div>
            <div class="kpi-value" style="color: var(--warning-color);"><?php echo $aktif_modul_sayisi; ?></div>
            <div class="kpi-sub"><strong><?php echo number_format($ogrenilen_kelime); ?></strong> öğrenilmiş kelime</div>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="bento-box col-8">
        <div class="box-title">
            <span><i class="fas fa-stream" style="color: var(--accent-color);"></i> Son Canlı Log Akışı</span>
            <a href="<?php echo Guvenlik::e(Panel::url('logger')); ?>" style="font-size: 0.8rem; color: var(--text-muted); text-decoration: none;">Tümünü Gör <i class="fas fa-arrow-right"></i></a>
        </div>
        <div style="padding-left: 10px;">
            <?php if(empty($son_loglar)): ?>
                <div style="color: var(--text-muted); text-align: center; padding: 2rem;">Henüz hiç kayıt yok.</div>
            <?php else: ?>
                <?php foreach($son_loglar as $log): 
                    $is_success = $log['anlasildi_mi'] == 1;
                    $status_class = $is_success ? 'success' : 'danger';
                    $saf_mesaj = Guvenlik::e($log['kullanici_mesaji']);
                ?>
                <div class="live-feed-item <?php echo $status_class; ?>">
                    <div class="live-time"><?php echo date('H:i:s', strtotime($log['islem_tarihi'])); ?> - <span style="opacity:0.7;">Güven: %<?php echo (int)$log['guven_skoru']; ?></span></div>
                    <div class="live-msg">"<?php echo $saf_mesaj; ?>"</div>
                    <div class="live-intent">
                        <?php if($is_success): ?>
                            <i class="fas fa-robot"></i> Algılanan Niyet: <?php echo Guvenlik::e($log['modul_adi']); ?>
                        <?php else: ?>
                            <i class="fas fa-times-circle" style="color: var(--danger-color);"></i> Sistem bu cümleyi anlayamadı.
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="bento-box col-4">
        <div class="box-title"><span><i class="fas fa-chart-pie" style="color: var(--warning-color);"></i> En Çok Kullanılan Modüller</span></div>
        <div class="data-list">
            <?php if(empty($modul_dagilimi)): ?>
                <div style="color: var(--text-muted); text-align: center; padding: 2rem;">Veri yok.</div>
            <?php else: ?>
                <?php 
                // En yüksek değeri bulalım ki barları orantılı çizelim
                $max_val = $modul_dagilimi[0]['adet'];
                foreach($modul_dagilimi as $index => $mod): 
                    $yuzde = ($mod['adet'] / $max_val) * 100;
                    // İlk 3'e özel renk, diğerlerine standart
                    $renk = ($index == 0) ? 'var(--accent-color)' : (($index == 1) ? 'var(--success-color)' : (($index == 2) ? 'var(--warning-color)' : 'var(--text-muted)'));
                ?>
                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 5px;">
                        <span><?php echo Guvenlik::e($mod['modul_adi']); ?></span>
                        <span style="font-weight: 600;"><?php echo (int)$mod['adet']; ?> Kez</span>
                    </div>
                    <div class="progress-track"><div class="progress-fill" style="width: <?php echo $yuzde; ?>%; background: <?php echo $renk; ?>;"></div></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="bento-box col-6">
        <div class="box-title" style="border-bottom-color: rgba(16,185,129,0.3);">
            <span style="color: var(--success-color);"><i class="fas fa-trophy"></i> En Sık Anlaşılan Cümleler (Top 5)</span>
        </div>
        <div class="data-list">
            <?php foreach($top_basarili as $item): ?>
            <div class="data-item">
                <div class="item-text"><i class="fas fa-check" style="color: var(--success-color);"></i> "<?php echo Guvenlik::e($item['kullanici_mesaji']); ?>"</div>
                <div class="item-count" style="color: var(--success-color);"><?php echo (int)$item['adet']; ?></div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($top_basarili)) echo '<div style="color: var(--text-muted); padding: 1rem;">Veri yok.</div>'; ?>
        </div>
    </div>

    <div class="bento-box col-6" style="border-color: rgba(239, 68, 68, 0.3);">
        <div class="box-title" style="border-bottom-color: rgba(239,68,68,0.3);">
            <span style="color: var(--danger-color);"><i class="fas fa-skull-crossbones"></i> En Çok Takılan Kelimeler (Kör Nokta)</span>
            <a href="<?php echo Guvenlik::e(Panel::url('logger')); ?>" class="btn-outline" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;"><i class="fas fa-graduation-cap"></i> Eğit</a>
        </div>
        <div class="data-list">
            <?php foreach($top_basarisiz as $item): ?>
            <div class="data-item" style="background: rgba(239,68,68,0.05);">
                <div class="item-text"><i class="fas fa-exclamation-circle" style="color: var(--danger-color);"></i> "<?php echo Guvenlik::e($item['kullanici_mesaji']); ?>"</div>
                <div class="item-count" style="background: var(--danger-color); color: white;"><?php echo (int)$item['adet']; ?></div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($top_basarisiz)) echo '<div style="color: var(--success-color); padding: 1rem;"><i class="fas fa-check-circle"></i> Sistem kusursuz, tekrarlayan hata yok!</div>'; ?>
        </div>
    </div>
</div>

<?php 
$page_content = ob_get_clean();

require_once __DIR__ . '/../layouts/master_layouts.php';
