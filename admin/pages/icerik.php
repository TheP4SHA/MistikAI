<?php
// admin/pages/icerik.php - Veri havuzu ve kazıyıcı (yemek menüsü, akademik takvim)
// Kazıyıcı yalnız config/db.php'deki $SCRAPER_IZINLI_HOSTLAR listesindeki alan adlarına gider (liste boşsa kapalı).
// Her istek ve her yönlendirme adımı Guvenlik::disUrlGuvenli() ile denetlenir; bağlantı doğrulanmış IP'ye sabitlenir.
// Kaynak adresleri Sistem Ayarları'ndan girilir (yemekhane_url, takvim_url); boşsa kazıma yapılmaz.
//
// yemekAyristir() ve takvimAyristir() örnek ayrıştırıcılardır: örnek bir site yapısındaki HTML tablolarına göre
// yazıldılar (aşağıdaki açıklamalara bakın). Kendi kurumunuzun sayfalarına göre uyarlayın.
require_once __DIR__ . '/../_baslat.php';

const KAZIMA_MAKS_BAYT = 2 * 1024 * 1024;   // 2 MB
const KAZIMA_MAKS_YONLENDIRME = 3;
const TR_AYLAR = [
    'ocak' => 1, 'subat' => 2, 'mart' => 3, 'nisan' => 4, 'mayis' => 5, 'haziran' => 6,
    'temmuz' => 7, 'agustos' => 8, 'eylul' => 9, 'ekim' => 10, 'kasim' => 11, 'aralik' => 12,
];

/** Kullanıcıya olduğu gibi gösterilebilecek kazıma hatası (iç ayrıntı içermez). */
final class KazimaHatasi extends RuntimeException {}

function kazimaHostlari(): array {
    return array_values(array_filter(array_map('strval', (array)($GLOBALS['SCRAPER_IZINLI_HOSTLAR'] ?? []))));
}

/** Hostun genel (özel/ayrılmış olmayan) bir IP'si; bağlantı bu IP'ye sabitlenir (DNS rebinding'e karşı). */
function kazimaIp(string $host): ?string {
    $genel = fn($ip) => (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return $genel($host) ? $host : null;
    }
    $adaylar = array_merge((array)@gethostbynamel($host), array_column((array)@dns_get_record($host, DNS_AAAA), 'ipv6'));
    foreach (array_filter($adaylar) as $ip) {
        if ($genel($ip)) {
            return $ip;
        }
    }
    return null;
}

/**
 * SSRF'e karşı korumalı GET: yalnız http/https, otomatik yönlendirme yok (her adım elle yeniden denetlenir),
 * SSL doğrulaması açık, zaman aşımı ve en fazla KAZIMA_MAKS_BAYT yanıt.
 */
function guvenliGetir(string $url): string {
    $hostlar = kazimaHostlari();
    if (!$hostlar) {
        throw new KazimaHatasi('Veri kazıyıcı kapalı: config/db.php içindeki $SCRAPER_IZINLI_HOSTLAR listesine izinli alan adlarını ekleyin.');
    }
    for ($adim = 0; $adim <= KAZIMA_MAKS_YONLENDIRME; $adim++) {
        if (!Guvenlik::disUrlGuvenli($url, $hostlar)) {
            throw new KazimaHatasi('Bu adres kazıma için izinli değil. Yalnız http/https, $SCRAPER_IZINLI_HOSTLAR listesindeki alan adları ve genel IP adresleri kabul edilir.');
        }
        $p = parse_url($url);
        $host = (string)$p['host'];
        $port = (int)($p['port'] ?? (strtolower((string)$p['scheme']) === 'https' ? 443 : 80));
        $ip = kazimaIp($host);
        if ($ip === null) {
            throw new KazimaHatasi('Adresin IP\'si doğrulanamadı.');
        }

        $govde = '';
        $asim = false;
        $ch = curl_init($url);
        $ayarlar = [
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 20,
            CURLOPT_USERAGENT       => 'MistikAI-Kaziyici/1.1',
            CURLOPT_ENCODING        => '',
            CURLOPT_WRITEFUNCTION   => function ($ch, string $parca) use (&$govde, &$asim): int {
                if (strlen($govde) + strlen($parca) > KAZIMA_MAKS_BAYT) {
                    $asim = true;
                    return 0; // aktarımı keser
                }
                $govde .= $parca;
                return strlen($parca);
            },
        ];
        if (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            $ayarlar[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip)];
        }
        curl_setopt_array($ch, $ayarlar);
        $sonuc = curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $sonraki = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $curlHata = curl_error($ch);
        curl_close($ch);

        if ($asim) {
            throw new KazimaHatasi('Yanıt çok büyük (en fazla ' . (KAZIMA_MAKS_BAYT >> 20) . ' MB).');
        }
        if ($sonuc === false) {
            error_log('[MistikAI admin] kazıma hatası (' . $url . '): ' . $curlHata);
            throw new KazimaHatasi('Adrese bağlanılamadı (zaman aşımı, SSL ya da ağ hatası). Ayrıntı sunucu günlüğünde.');
        }
        if ($kod >= 300 && $kod < 400 && $sonraki !== '') {
            $url = $sonraki; // bir sonraki turda yeniden denetlenir
            continue;
        }
        if ($kod !== 200) {
            throw new KazimaHatasi('Kaynak sunucu HTTP ' . $kod . ' döndürdü.');
        }
        return $govde;
    }
    throw new KazimaHatasi('Çok fazla yönlendirme.');
}

/** Ay adını karşılaştırma anahtarına çevirir: Türkçe büyük/küçük harf + ASCII katlama ("MAYIS", "Mayıs" → "mayis"). */
function ayAnahtari(string $s): string {
    $s = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $s), 'UTF-8');
    return strtr($s, ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c']);
}

/**
 * Türkçe tarih metnini ("28 Temmuz 2025 Pazartesi") 'YYYY-MM-DD' yapar.
 * Metinde tarih yoksa null; ay adı tanınmıyorsa ya da tarih geçersizse false (satır atlanır ve raporlanır).
 */
function parseTrDate(string $text) {
    if (!preg_match('/(\d{1,2})\s+([a-zA-ZçÇğĞıİöÖşŞüÜ]+)\s+(\d{4})/u', $text, $m)) {
        return null;
    }
    $ay = TR_AYLAR[ayAnahtari($m[2])] ?? null;
    $gun = (int)$m[1];
    $yil = (int)$m[3];
    if ($ay === null || !checkdate($ay, $gun, $yil)) {
        return false;
    }
    return sprintf('%04d-%02d-%02d', $yil, $ay, $gun);
}

/**
 * Akademik takvim tablosunu ayrıştırır: [kayıtlar, atlanan satırlar]. Veritabanına dokunmaz.
 * Örnek ayrıştırıcı. Beklenen yapı: her <tr> içinde en az üç <td> (başlangıç tarihi, bitiş tarihi, faaliyet),
 * tarihler "28 Temmuz 2025 Pazartesi" biçiminde; "GÜZ YARIYILI" / "BAHAR YARIYILI" yazan satırlar dönemi değiştirir.
 * Kurumunuzun takvim sayfası farklıysa bu işlevi uyarlayın.
 */
function takvimAyristir(string $html): array {
    $dom = new DOMDocument();
    $onceki = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($onceki);
    $xpath = new DOMXPath($dom);

    $kayitlar = [];
    $atlananlar = [];
    $yariyil = 'Güz';
    foreach ($xpath->query('//table//tr') as $row) {
        $cells = $xpath->query('.//td', $row);
        if ($cells->length >= 1) {
            $ilk = trim($cells->item(0)->textContent);
            if (mb_stripos($ilk, 'GÜZ YARIYILI') !== false) { $yariyil = 'Güz'; continue; }
            if (mb_stripos($ilk, 'BAHAR YARIYILI') !== false) { $yariyil = 'Bahar'; continue; }
        }
        if ($cells->length < 3) {
            continue;
        }
        $bas_text = trim($cells->item(0)->textContent);
        $bit_text = trim($cells->item(1)->textContent);
        $faaliyet = trim(preg_replace('/\s+/u', ' ', $cells->item(2)->textContent));
        if ($faaliyet === '' || mb_stripos($bas_text, 'BAŞLANGIÇ') !== false) {
            continue;
        }

        $bas = parseTrDate($bas_text);
        $bit = parseTrDate($bit_text);
        if ($bas === false || $bit === false) {
            $atlananlar[] = mb_substr($faaliyet, 0, 60, 'UTF-8') . ' (' . trim($bas_text . ' / ' . $bit_text, ' /') . ')';
            continue;
        }
        if (!$bas && $bit) { $bas = $bit; }
        if ($bas && !$bit) { $bit = $bas; }
        if (!$bas) {
            continue;
        }
        $kayitlar[] = [$yariyil, $bas, $bit, mb_substr($faaliyet, 0, 255, 'UTF-8')];
    }
    return [$kayitlar, $atlananlar];
}

/**
 * Yemek menüsü tablosunu ayrıştırır: [[tarih, başlık, detay], ...].
 * Örnek ayrıştırıcı. Beklenen yapı: altı hücreli satırlar — tarih (GG.AA.YYYY), ana yemek, üç yan yemek, kalori.
 * Kurumunuzun menü sayfası farklıysa bu işlevi uyarlayın.
 */
function yemekAyristir(string $html): array {
    $temiz = preg_replace('/\s+/', ' ', $html);
    preg_match_all('/<tr[^>]*>\s*<td>(\d{2}\.\d{2}\.\d{4})<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<\/tr>/is', (string)$temiz, $eslesmeler, PREG_SET_ORDER);
    $metin = fn($h) => trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $sonuc = [];
    foreach ($eslesmeler as $row) {
        [$g, $a, $y] = array_map('intval', explode('.', $row[1]));
        if (!checkdate($a, $g, $y)) {
            continue;
        }
        $sonuc[] = [
            sprintf('%04d-%02d-%02d', $y, $a, $g),
            mb_substr($metin($row[2]), 0, 255, 'UTF-8'),
            $metin($row[3]) . ', ' . $metin($row[4]) . ', ' . $metin($row[5]) . ' (' . $metin($row[6]) . ' kcal)',
        ];
    }
    return $sonuc;
}

// ======================================================================
// AJAX İŞLEMLERİ (YEMEK VE TAKVİM)
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // 1. Yemek senkronizasyonu: önce ayrıştır, sonra tek işlemde yaz.
    if ($_POST['action'] === 'sync_yemek') {
        try {
            $url = trim((string)Panel::ayar($db, 'yemekhane_url', ''));
            if ($url === '') {
                Panel::json(['success' => false, 'message' => 'Yemek menüsü için kaynak adresi tanımlı değil. Sistem Ayarları\'ndan yemekhane menüsü adresini girin.']);
            }
            $menuler = yemekAyristir(guvenliGetir($url));
            if (!$menuler) {
                Panel::json(['success' => false, 'message' => 'Sayfadaki menü tablosu çözülemedi; mevcut veriler korunuyor.']);
            }
            $added = 0; $updated = 0;
            $check = $db->prepare("SELECT id FROM bot_zaman_cizelgesi WHERE tip = 'yemek' AND tarih = ?");
            $upd = $db->prepare("UPDATE bot_zaman_cizelgesi SET baslik = ?, detay = ? WHERE id = ?");
            $ins = $db->prepare("INSERT INTO bot_zaman_cizelgesi (tip, baslik, detay, tarih) VALUES ('yemek', ?, ?, ?)");
            $db->beginTransaction();
            foreach ($menuler as [$tarih, $baslik, $detay]) {
                $check->execute([$tarih]);
                $id = $check->fetchColumn();
                if ($id) { $upd->execute([$baslik, $detay, $id]); $updated++; }
                else { $ins->execute([$baslik, $detay, $tarih]); $added++; }
            }
            $db->commit();
            Panel::json(['success' => true, 'message' => "Ziyafet listesi güncellendi! ($added yeni, $updated güncel)"]);
        } catch (KazimaHatasi $e) {
            Panel::json(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'yemek kazıma')]);
        }
    }

    // 2. Akademik takvim: önce ayrıştır; en az bir kayıt varsa eski takvimi tek işlemde değiştir.
    if ($_POST['action'] === 'sync_takvim') {
        $url = trim((string)($_POST['takvim_url'] ?? ''));
        if ($url === '') {
            Panel::json(['success' => false, 'message' => 'Akademik takvim için kaynak adresi tanımlı değil. Takvim sayfasının adresini girin.']);
        }
        try {
            [$kayitlar, $atlananlar] = takvimAyristir(guvenliGetir($url));
            if (!$kayitlar) {
                Panel::json(['success' => false, 'message' => 'Sayfada tarihli bir takvim tablosu bulunamadı; mevcut takvim korunuyor.', 'atlananlar' => $atlananlar]);
            }
            $ins = $db->prepare("INSERT INTO bot_akademik_takvim (yariyil, baslangic_tarihi, bitis_tarihi, faaliyet) VALUES (?, ?, ?, ?)");
            $db->beginTransaction();
            $db->exec("DELETE FROM bot_akademik_takvim"); // TRUNCATE işlemi örtük olarak commit eder; DELETE geri alınabilir.
            foreach ($kayitlar as $k) {
                $ins->execute($k);
            }
            $db->commit();
            $mesaj = 'Takvim başarıyla tarandı! Toplam ' . count($kayitlar) . ' etkinlik sisteme işlendi.';
            if ($atlananlar) {
                $mesaj .= "\n" . count($atlananlar) . " satır tanınmayan ay adı ya da geçersiz tarih yüzünden atlandı:\n- "
                        . implode("\n- ", array_slice($atlananlar, 0, 10)) . (count($atlananlar) > 10 ? "\n- ..." : '');
            }
            Panel::json(['success' => true, 'message' => $mesaj, 'atlananlar' => $atlananlar]);
        } catch (KazimaHatasi $e) {
            Panel::json(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            Panel::json(['success' => false, 'message' => Panel::hata($e, 'takvim kazıma')]);
        }
    }

    Panel::json(['success' => false, 'message' => 'Bilinmeyen işlem.'], 400);
}

$kazima_acik = (bool)kazimaHostlari();

$page_title = "Veri Havuzu & Scraper";
$active_menu = "icerik";
$tab = ($_GET['tab'] ?? '') === 'takvim' ? 'takvim' : 'yemek';

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

    <?php if (!$kazima_acik): ?>
    <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); color: var(--warning-color); padding: 12px 18px; border-radius: 10px; margin-bottom: 1.5rem;">
        <i class="fas fa-shield-alt"></i> Veri kazıyıcı kapalı: config/db.php içindeki <code>$SCRAPER_IZINLI_HOSTLAR</code> listesine kazınacak alan adlarını ekleyin (örn. <code>['www.example.edu.tr']</code>).
    </div>
    <?php endif; ?>

    <div class="hub-nav">
        <a href="<?php echo Guvenlik::e(Panel::url('icerik', ['tab' => 'yemek'])); ?>" class="hub-tab <?php echo $tab == 'yemek' ? 'active' : ''; ?>">🍱 YEMEKHANE</a>
        <a href="<?php echo Guvenlik::e(Panel::url('icerik', ['tab' => 'takvim'])); ?>" class="hub-tab <?php echo $tab == 'takvim' ? 'active' : ''; ?>">📅 AKADEMİK TAKVİM</a>
        <a href="<?php echo Guvenlik::e(Panel::url('ders')); ?>" class="hub-tab">📚 DERS PROGRAMI</a>
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
                            <td style="font-weight:700;"><?php echo Guvenlik::e($l['baslik']); ?></td>
                            <td style="color:var(--text-muted); font-size:0.85rem;"><?php echo Guvenlik::e($l['detay']); ?></td>
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
                <input type="url" id="takvimUrl" class="modern-input" value="<?php echo Guvenlik::e($takvim_url_ayar ?: ''); ?>" placeholder="Akademik takvim sayfasının adresi, ör. https://www.example.edu.tr/akademik-takvim">
                <button onclick="syncTakvim()" class="btn-pasha btn-pasha-blue" id="takvimBtn">
                    <i class="fas fa-spider"></i> VERİYİ KAZI
                </button>
            </div>
            <small style="color:var(--text-muted); margin-top:10px; display:block;">Bot, bu URL'ye giderek HTML tablolarını okur ve tarihleri ayıklar. Ay adı tanınmayan satırlar atlanır ve raporlanır. Ayrıştırıcı örnek bir site yapısına göre yazıldı; kendi sayfanıza göre <code>takvimAyristir()</code> işlevini uyarlayın.</small>
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
                            <td><span class="badge <?php echo $badge; ?>"><?php echo Guvenlik::e($l['yariyil']); ?> Yarıyılı</span></td>
                            <td style="color:#ddd; font-weight:600; white-space:nowrap;"><?php echo $tarih_text; ?></td>
                            <td style="color:var(--text-muted); font-size:0.95rem; font-weight:500;"><?php echo Guvenlik::e($l['faaliyet']); ?></td>
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
        const data = await MISTIK.istek('icerik', fd, {tab: 'yemek'});
        if (data.success) { alert('✅ ' + data.message); location.reload(); } 
        else { alert('❌ ' + data.message); }
    } catch (e) { alert('Sunucu hatası!'); } 
    finally { btn.disabled = false; btn.innerHTML = originalText; }
}

async function syncTakvim() {
    const url = document.getElementById('takvimUrl').value;
    if(!url) { alert("Akademik takvim için kaynak adresi tanımlı değil. Lütfen bir URL girin."); return; }

    const btn = document.getElementById('takvimBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> KAZILIYOR...';
    
    const fd = new FormData(); 
    fd.append('action', 'sync_takvim');
    fd.append('takvim_url', url);

    try {
        const data = await MISTIK.istek('icerik', fd, {tab: 'takvim'});
        if (data.success) { alert('✅ ' + data.message); location.reload(); } 
        else { alert('❌ ' + data.message); }
    } catch (e) { alert('Sunucu veya Parse hatası! HTML yapısı değişmiş olabilir.'); } 
    finally { btn.disabled = false; btn.innerHTML = originalText; }
}
</script>

<?php 
$page_content = ob_get_clean();

require_once __DIR__ . '/../layouts/master_layouts.php';
