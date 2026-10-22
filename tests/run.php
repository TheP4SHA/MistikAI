<?php
// tests/run.php - Bağımlılıksız test koşucusu (composer gerekmez)
//
//   php tests/run.php            # birim testleri
//   MISTIK_TEST_DB_NAME=... MISTIK_TEST_DB_USER=... MISTIK_TEST_DB_PASS=... [MISTIK_TEST_DB_HOST=127.0.0.1] php tests/run.php
//                                # + veritabanı testleri (her test kendi işlemi içinde çalışır ve geri alınır)
//
// Herhangi bir test başarısız olursa çıkış kodu 1'dir.

error_reporting(E_ALL);
ini_set('display_errors', '1');
// Motorun error_log satırları test çıktısını kirletmesin; geçici dosyaya yazılır.
$HATA_GUNLUGU = tempnam(sys_get_temp_dir(), 'mistik-test-log');
ini_set('error_log', $HATA_GUNLUGU);
register_shutdown_function(fn() => @unlink($HATA_GUNLUGU));
date_default_timezone_set('Europe/Istanbul');
set_error_handler(function (int $no, string $msg, string $file, int $line) {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

$KOK = dirname(__DIR__);
require_once $KOK . '/src/MistikEngine.php';
require_once $KOK . '/src/HizSiniri.php';

$_SESSION = [];

// ----------------------------------------------------------------------
// Küçük test çatısı
// ----------------------------------------------------------------------
final class TestBasarisiz extends Exception {}
final class TestAtlandi extends Exception {}

$SONUC = ['gecen' => 0, 'kalan' => 0, 'atlanan' => 0];

function test(string $ad, callable $f): void {
    global $SONUC;
    try {
        $f();
        $SONUC['gecen']++;
        echo "  \033[32m✓\033[0m {$ad}\n";
    } catch (TestAtlandi $e) {
        $SONUC['atlanan']++;
        echo "  \033[33m-\033[0m {$ad} (atlandı: {$e->getMessage()})\n";
    } catch (Throwable $e) {
        $SONUC['kalan']++;
        $yer = $e instanceof TestBasarisiz ? '' : ' [' . get_class($e) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        echo "  \033[31m✗ {$ad}\033[0m\n      " . str_replace("\n", "\n      ", $e->getMessage()) . $yer . "\n";
    }
}

function grup(string $ad): void {
    echo "\n{$ad}\n";
}

function goster($d): string {
    return is_string($d) ? '"' . $d . '"' : var_export($d, true);
}

function esit($beklenen, $gercek, string $mesaj = ''): void {
    if ($beklenen !== $gercek) {
        throw new TestBasarisiz(($mesaj ? "{$mesaj}: " : '') . 'beklenen ' . goster($beklenen) . ', gelen ' . goster($gercek));
    }
}

function dogru($kosul, string $mesaj = 'koşul sağlanmadı'): void {
    if ($kosul !== true) {
        throw new TestBasarisiz($mesaj);
    }
}

function icerir(string $metin, string $parca, string $mesaj = ''): void {
    if (strpos($metin, $parca) === false) {
        throw new TestBasarisiz(($mesaj ? "{$mesaj}: " : '') . goster($parca) . ' bulunamadı, metin: ' . goster($metin));
    }
}

function icermez(string $metin, string $parca, string $mesaj = ''): void {
    if (stripos($metin, $parca) !== false) {
        throw new TestBasarisiz(($mesaj ? "{$mesaj}: " : '') . goster($parca) . ' bulunmamalıydı, metin: ' . goster($metin));
    }
}

function atla(string $neden): void {
    throw new TestAtlandi($neden);
}

// ----------------------------------------------------------------------
// Ortak test verisi
// ----------------------------------------------------------------------
function ornekModuller(): array {
    $satir = fn($id, $kod, $ad, $aktif = 1, $cevap = '') => ['id' => $id, 'modul_kodu' => $kod, 'modul_adi' => $ad, 'cevap_metni' => $cevap, 'buton_metni' => null, 'aktif_mi' => $aktif];
    return [
        $satir(1, 'iletisim', 'İletişim Bilgileri', 1, 'Telefon: santral'),
        $satir(2, 'yemekhane', 'Yemekhane Asistanı'),
        $satir(3, 'ders_programi', 'Ders Programı Asistanı'),
        $satir(4, 'selamlama', 'Selamlama', 1, 'Merhaba!'),
        $satir(5, 'akademik_takvim', 'Akademik Takvim'),
        $satir(6, 'pasif_modul', 'Pasif Modül', 0, 'görünmemeli'),
    ];
}

function ornekKelimeler(): array {
    $k = fn($kod, $kelime, $oncelik, $override = null) => ['kelime' => $kelime, 'modul_kodu' => $kod, 'oncelik' => $oncelik, 'cevap_override' => $override, 'zaman_kontrol' => null];
    return [
        $k('iletisim', 'iletişim', 90),
        $k('yemekhane', 'yemekhane', 85),
        $k('yemekhane', 'yemek', 85),
        $k('yemekhane', 'menü', 90),
        $k('ders_programi', 'dersler', 80),
        $k('ders_programi', 'ders programı', 95),
        $k('selamlama', 'merhaba', 55, 'Merhaba! <script>alert(1)</script>'),
        $k('akademik_takvim', 'akademik takvim', 100),
        $k('pasif_modul', 'gizli kelime', 100),
    ];
}

function ornekRouter(): RuleBasedRouter {
    return new RuleBasedRouter(null, ornekKelimeler(), ornekModuller());
}

/** Geçici eklenti dizini: allowlist, echo, bekleme ve hata senaryoları için. */
function ornekEklentiDizini(): string {
    static $dizin = null;
    if ($dizin !== null) {
        return $dizin;
    }
    $kok = sys_get_temp_dir() . '/mistik-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($kok . '/modules', 0700, true);
    $dosyalar = [
        'ornek'       => '<?php return $this->response("ornek:" . $clean_input);',
        'pasif_modul' => '<?php return $this->response("PASIF CALISTI");',
        'yankili'     => '<?php echo "SIZINTI"; return $this->response("temiz cevap");',
        'bozuk'       => '<?php throw new RuntimeException("gizli ayrinti");',
        'xss'         => '<?php return $this->response("<b>ok</b><img src=x onerror=alert(1)><script>alert(2)</script>", [["text" => "<i>Git</i>", "payload" => "javascript:alert(1)"], ["text" => "Site", "url" => "javascript:alert(1)"], ["text" => "Resmi", "url" => "https://omyo.edu.tr"]]);',
        'eski_json'   => '<?php return json_encode(["reply" => "eski modül", "buttons" => [["text" => "Menü", "payload" => "flow_ana_menu"]]]);',
        'iki_adim'    => '<?php if ($bekleyen === null) { $this->bekle(["adim" => "isim"]); return $this->response("Kimi arıyorsun?"); } return $this->response("aranan:" . $raw_input . ":" . $bekleyen["adim"]);',
    ];
    foreach ($dosyalar as $ad => $kod) {
        file_put_contents("{$kok}/modules/{$ad}.php", $kod);
    }
    // Eklenti dizini dışına işaret eden sembolik bağ: realpath denetimi reddetmeli.
    file_put_contents("{$kok}/disarida.php", '<?php return $this->response("DISARIDAN");');
    @symlink("{$kok}/disarida.php", "{$kok}/modules/kacak.php");
    register_shutdown_function(function () use ($kok) {
        foreach (array_merge(glob("{$kok}/modules/*") ?: [], glob("{$kok}/*.php") ?: []) as $f) {
            @unlink($f);
        }
        @rmdir("{$kok}/modules");
        @rmdir($kok);
    });
    return $dizin = $kok;
}

function ornekMotor(?PDO $db = null, ?RuleBasedRouter $router = null, ?LLMClient $llm = null): MistikEngine {
    $kok = ornekEklentiDizini();
    $satir = fn($id, $kod) => ['id' => $id, 'modul_kodu' => $kod, 'modul_adi' => ucfirst($kod), 'cevap_metni' => '', 'buton_metni' => null, 'aktif_mi' => 1];
    $moduller = [
        $satir(101, 'ornek'), $satir(102, 'yankili'), $satir(103, 'bozuk'), $satir(104, 'xss'),
        $satir(105, 'eski_json'), $satir(106, 'iki_adim'), $satir(107, 'kacak'),
        ['id' => 108, 'modul_kodu' => 'pasif_modul', 'modul_adi' => 'Pasif', 'cevap_metni' => 'pasif cevap', 'buton_metni' => null, 'aktif_mi' => 0],
    ];
    return new MistikEngine($db, [
        'router'     => $router ?? new RuleBasedRouter(null, [], $moduller),
        'bert'       => new BertClient(''),
        'llm'        => $llm ?? new LLMClient(''),
        'plugin_dir' => $kok . '/modules',
        'session_id' => str_repeat('a', 64),
    ]);
}

// ======================================================================
grup('Guvenlik::temizHtml');
// ======================================================================
test('script etiketi içeriğiyle atılır', function () {
    $c = Guvenlik::temizHtml('<b>ok</b><script>alert(1)</script>');
    icermez($c, '<script');
    icermez($c, 'alert(1)');
    icerir($c, '<b>ok</b>');
});
test('onerror gibi olay öznitelikleri atılır', function () {
    $c = Guvenlik::temizHtml('<img src="https://ornek.edu.tr/a.png" onerror="alert(1)"><div onclick="x()">y</div>');
    icermez($c, 'onerror');
    icermez($c, 'onclick');
    icerir($c, 'src="https://ornek.edu.tr/a.png"');
});
test('javascript: bağlantısı atılır, metin kalır', function () {
    $c = Guvenlik::temizHtml('<a href="javascript:alert(1)">tıkla</a><a href=" JaVaScRiPt:alert(2)">iki</a>');
    icermez($c, 'javascript:');
    icerir($c, 'tıkla');
});
test("style içindeki url() atılır", function () {
    $c = Guvenlik::temizHtml('<div style="background:url(javascript:alert(1))">x</div><span style="color:red">k</span>');
    icermez($c, 'url(');
    icerir($c, 'style="color:red"');
});
test('img src yalnız https; target her zaman _blank + noopener', function () {
    icermez(Guvenlik::temizHtml('<img src="http://ornek.com/a.png">'), 'src=');
    $a = Guvenlik::temizHtml('<a href="https://omyo.edu.tr" target="_self">x</a>');
    icerir($a, 'target="_blank"');
    icerir($a, 'rel="noopener noreferrer"');
});
test('svg/iframe içerikleriyle atılır', function () {
    $c = Guvenlik::temizHtml('<svg onload="alert(1)"><circle/></svg><iframe src="https://x.com"></iframe>son');
    icermez($c, 'svg');
    icermez($c, 'iframe');
    icerir($c, 'son');
});

// ======================================================================
grup('Metin normalleştirme');
// ======================================================================
test('Türkçe küçük harf: İ→i, I→ı', function () {
    esit('iletişim', Metin::kucukHarf('İLETİŞİM'));
    esit('ılık', Metin::kucukHarf('ILIK'));
    esit('istanbul', Metin::kucukHarf('İstanbul'));
});
test('noktalama atılır, boşluk sadeleşir', function () {
    esit('yemekhane menü', Metin::normalize("  Yemekhane,   menü?!\n"));
    esit('lab 2 nerede', Metin::normalize('Lab-2 nerede?'));
});
test('ASCII katlama', function () {
    esit('carsamba gunu soyle ogrenci isleri', Metin::temiz('Çarşamba günü ŞÖYLE: Öğrenci İşleri'));
    esit('yarin', Metin::temiz('Yarın?'));
});

// ======================================================================
grup('RuleBasedRouter');
// ======================================================================
test('"İLETİŞİM" büyük harfle eşleşir (tam eşleşme)', function () {
    $r = ornekRouter()->analyze('İLETİŞİM');
    esit('matched', $r['status']);
    esit('iletisim', $r['module_code']);
    esit(true, $r['exact']);
});
test('ASCII yazılmış "iletisim" ve "ILETISIM" eşleşir', function () {
    $router = ornekRouter();
    foreach (['iletisim bilgileri', 'ILETISIM', 'İletisim?'] as $girdi) {
        $r = $router->analyze($girdi);
        esit('iletisim', $r['module_code'], $girdi);
        esit(false, $r['exact'], $girdi . ' tam eşleşme sayılmamalı');
    }
});
test('ek toleransı: "yemekhanede" → yemekhane, "menüsü" → yemekhane', function () {
    esit('yemekhane', ornekRouter()->analyze('Yemekhanede bugün ne var')['module_code']);
    esit('yemekhane', ornekRouter()->analyze('menüsü nedir')['module_code']);
});
test('çok kelimeli ifade ekli kelimeyle eşleşir: "ders programını"', function () {
    $r = ornekRouter()->analyze('ders programını göster');
    esit('matched', $r['status']);
    esit('ders_programi', $r['module_code']);
});
test('kelime ortasında eşleşme yok ("ayemek", "lokmenü")', function () {
    esit('no_match', ornekRouter()->analyze('ayemek lokmenü')['status']);
});
test('yazım hatası toleransı (6+ harf, Levenshtein ≤ 1)', function () {
    esit('akademik_takvim', ornekRouter()->analyze('akademk takvim')['module_code']);
    esit('iletisim', ornekRouter()->analyze('iletişm')['module_code']);
    esit('no_match', ornekRouter()->analyze('akdmk takvim')['status'], 'iki harf hata eşleşmemeli');
    esit('no_match', ornekRouter()->analyze('yemk')['status'], '6 harften kısa kelimede yazım toleransı yok');
});
test('kelimeEslesir türleri', function () {
    esit('tam', RuleBasedRouter::kelimeEslesir('yemek', 'yemek'));
    esit('ek', RuleBasedRouter::kelimeEslesir('yemekhane', 'yemekhanede'));
    esit(null, RuleBasedRouter::kelimeEslesir('ne', 'neden'), 'kısa kelimede ek toleransı yok');
    esit('yazim', RuleBasedRouter::kelimeEslesir('takvim', 'takvm'));
    esit(null, RuleBasedRouter::kelimeEslesir('takvim', 'takvm', false));
});
test('belirsizlikte adaylar döner', function () {
    $r = ornekRouter()->analyze('dersler ve menü');
    esit('ambiguous', $r['status']);
    $kodlar = array_column($r['candidates'], 'code');
    dogru(in_array('ders_programi', $kodlar, true) && in_array('yemekhane', $kodlar, true), 'adaylar: ' . implode(',', $kodlar));
    dogru(count($r['candidates']) <= 3);
});
test('güven skoru 0-100 aralığında', function () {
    foreach (['akademik takvim', 'İLETİŞİM', 'yemek', 'merhaba', 'dersler ve menü'] as $girdi) {
        $s = ornekRouter()->analyze($girdi)['score'];
        dogru($s >= 0 && $s <= 100, "{$girdi}: {$s}");
    }
});
test('cevap_override ve pasif modül', function () {
    $r = ornekRouter()->analyze('merhaba');
    icerir((string)$r['override'], 'Merhaba!');
    esit('no_match', ornekRouter()->analyze('gizli kelime')['status'], 'pasif modülün kelimesi eşleşmemeli');
    dogru(!isset(ornekRouter()->getModules()['pasif_modul']));
});

// ======================================================================
grup('MistikEngine::executeModule allowlist');
// ======================================================================
test('yol gezintisi ve geçersiz kodlar reddedilir', function () {
    $m = ornekMotor();
    foreach (['../x', '../../config/db', 'ORNEK', 'a', 'ornek.php', 'ornek/../ornek', "ornek\0", str_repeat('a', 41)] as $kod) {
        esit(null, $m->executeModule($kod, '', ''), "kod {$kod}");
        esit(null, $m->modulDosyasi($kod), "dosya {$kod}");
    }
});
test('bilinmeyen modül reddedilir', function () {
    esit(null, ornekMotor()->executeModule('bilinmeyen', '', ''));
});
test('pasif modül (dosyası olsa bile) reddedilir', function () {
    $m = ornekMotor();
    esit(false, $m->modulIzinli('pasif_modul'));
    esit(null, $m->executeModule('pasif_modul', '', ''));
});
test('eklenti dizini dışına işaret eden dosya reddedilir (realpath)', function () {
    if (!is_link(ornekEklentiDizini() . '/modules/kacak.php')) {
        atla('sembolik bağ oluşturulamadı');
    }
    $m = ornekMotor();
    esit(true, $m->modulIzinli('kacak'));
    esit(null, $m->modulDosyasi('kacak'));
    esit(null, $m->executeModule('kacak', '', ''));
});
test('aktif modül çalışır', function () {
    $c = ornekMotor()->executeModule('ornek', 'yarin', 'Yarın');
    dogru($c instanceof MistikCevap);
    esit('ornek:yarin', $c->metin);
});
test('modül çıktısı (echo) atılır, dönen cevap kullanılır', function () {
    $c = ornekMotor()->process('', 'modul_yankili');
    esit('temiz cevap', $c->metin);
});
test('modül istisnası kullanıcıya sızmaz, anlasildi=0', function () {
    $c = ornekMotor()->process('', 'modul_bozuk');
    icermez($c->metin, 'gizli ayrinti');
    esit(false, $c->anlasildi);
});
test('modul_ komutu pasif/bilinmeyen modülde dostça hata verir', function () {
    $c = ornekMotor()->process('x', 'modul_pasif_modul');
    icermez($c->metin, 'PASIF');
    icerir($c->metin, 'kullanılamıyor');
});
test('modül HTML\'i ve butonları sunucuda süzülür', function () {
    $c = ornekMotor()->process('', 'modul_xss');
    icerir($c->metin, '<b>ok</b>');
    icermez($c->metin, 'onerror');
    icermez($c->metin, '<script');
    esit([['text' => 'Git', 'payload' => 'javascript:alert(1)'], ['text' => 'Resmi', 'url' => 'https://omyo.edu.tr']], $c->butonlar,
        'javascript: url düşmeli; payload düz metindir ve istemcide komut/mesaj olarak gönderilir');
});
test('eski JSON döndüren modül desteklenir', function () {
    $c = ornekMotor()->process('', 'modul_eski_json');
    esit('eski modül', $c->metin);
    esit('flow_ana_menu', $c->butonlar[0]['payload']);
});
test('bekleyen modül: ikinci mesaj aynı modüle döner', function () {
    $_SESSION = [];
    $m = ornekMotor();
    esit('Kimi arıyorsun?', $m->process('', 'modul_iki_adim')->metin);
    $c = $m->process('Ahmet Yılmaz');
    esit('aranan:Ahmet Yılmaz:isim', $c->metin);
    esit('akis', $c->katman);
    // Slot tek kullanımlık
    dogru(!isset($_SESSION['mistik']['bekleyen']));
});
test('bekleyen modül "iptal" ile düşer', function () {
    $_SESSION = [];
    $m = ornekMotor();
    $m->process('', 'modul_iki_adim');
    icerir($m->process('İptal')->metin, 'vazgeçtik');
    dogru(!isset($_SESSION['mistik']['bekleyen']));
});
test('takip sorusu ("peki yarın?") son modüle gider, ilgisiz kelime gitmez', function () {
    $_SESSION = [];
    $m = ornekMotor();
    $m->process('', 'modul_ornek');
    $c = $m->process('Peki yarın?');
    esit('ornek:peki yarin', $c->metin);
    esit('akis', $c->katman);
    esit('ornek:ya cuma', $m->process('ya cuma')->metin);
    dogru(strpos($m->process('pazarlık dünya')->metin, 'ornek:') !== 0, '"pazarlık dünya" zaman ifadesi değil');
});
test('tüm katmanlar başarısızsa dostça mesaj + menü butonu, anlasildi=0', function () {
    $_SESSION = [];
    $c = ornekMotor()->process('kuantum fiziği');
    icerir($c->metin, 'yanıtlayamıyorum');
    esit('flow_ana_menu', $c->butonlar[0]['payload'] ?? null);
    esit(false, $c->anlasildi);
    esit('llm', $c->katman);
});
test('belirsiz soruda "Bunu mu demek istedin?" + en fazla 3 modül butonu', function () {
    $_SESSION = [];
    $m = new MistikEngine(null, ['router' => ornekRouter(), 'bert' => new BertClient(''), 'llm' => new LLMClient(''),
                                 'plugin_dir' => ornekEklentiDizini() . '/modules', 'session_id' => 'x']);
    $c = $m->process('dersler ve menü');
    icerir($c->metin, 'Bunu mu demek istedin?');
    dogru(count($c->butonlar) >= 2 && count($c->butonlar) <= 3);
    foreach ($c->butonlar as $b) {
        dogru((bool)preg_match('/^modul_[a-z0-9_]+$/', $b['payload']), 'payload: ' . $b['payload']);
    }
});
test('override cevabı süzülür', function () {
    $_SESSION = [];
    $m = new MistikEngine(null, ['router' => ornekRouter(), 'bert' => new BertClient(''), 'llm' => new LLMClient(''),
                                 'plugin_dir' => ornekEklentiDizini() . '/modules', 'session_id' => 'x']);
    $c = $m->process('Merhaba');
    icerir($c->metin, 'Merhaba!');
    icermez($c->metin, '<script');
    esit('kural', $c->katman);
    esit(true, $c->anlasildi);
});

// ======================================================================
grup('Gün ayrıştırma (ders programı / yemekhane)');
// ======================================================================
test('cumartesi, cuma ile karışmaz', function () {
    esit('Cumartesi', TarihYardimcisi::gunBul('Cumartesi dersim var mı?'));
    esit('Cuma', TarihYardimcisi::gunBul('cuma günü ders'));
    esit('Cuma', TarihYardimcisi::gunBul('cumaya ne var'));
});
test('Türkçe yazım doğru: Pazartesi (Pazartesı değil), Salı, Çarşamba, Perşembe', function () {
    esit('Pazartesi', TarihYardimcisi::gunBul('PAZARTESİ'));
    esit('Salı', TarihYardimcisi::gunBul('salı'));
    esit('Salı', TarihYardimcisi::gunBul('sali dersleri'));
    esit('Salı', TarihYardimcisi::gunBul('Salıya'));
    esit('Çarşamba', TarihYardimcisi::gunBul('carsamba'));
    esit('Perşembe', TarihYardimcisi::gunBul('PERŞEMBE günü'));
    esit('Pazar', TarihYardimcisi::gunBul('pazar'));
});
test('kelime sınırı: "salim", "pazarlık", "cumhuriyet" gün değildir', function () {
    esit(null, TarihYardimcisi::gunBul('salim bey'));
    esit(null, TarihYardimcisi::gunBul('pazarlık'));
    esit(null, TarihYardimcisi::gunBul('cumhuriyet bayramı'));
});
test('göreli gün: yarın / bugün / dün / öbür gün', function () {
    esit(1, TarihYardimcisi::goreliGun('Yarın yemekte ne var?'));
    esit(0, TarihYardimcisi::goreliGun('bugün'));
    esit(-1, TarihYardimcisi::goreliGun('dünkü menü'));
    esit(2, TarihYardimcisi::goreliGun('öbür gün'));
    esit(null, TarihYardimcisi::goreliGun('dünya'));
});
test('sıradaki tarih', function () {
    $persembe = new DateTimeImmutable('2026-10-22');
    esit('2026-10-23', TarihYardimcisi::siradakiTarih('Cuma', $persembe)->format('Y-m-d'));
    esit('2026-10-22', TarihYardimcisi::siradakiTarih('Perşembe', $persembe)->format('Y-m-d'));
    esit('2026-10-26', TarihYardimcisi::siradakiTarih('Pazartesi', $persembe)->format('Y-m-d'));
});

// ======================================================================
grup('LLMClient');
// ======================================================================
$izinli = ['yemekhane', 'ders_programi', LLMClient::MENU_ARACI];
test('araç çağrısı çözülür', function () use ($izinli) {
    $json = json_decode('{"choices":[{"message":{"role":"assistant","content":null,"tool_calls":[{"id":"c1","type":"function","function":{"name":"yemekhane","arguments":"{\"soru\":\"yarın\"}"}}]}}]}', true);
    $s = LLMClient::yanitCoz($json, $izinli);
    esit('arac', $s['durum']);
    esit('yemekhane', $s['arac']);
    esit('yarın', $s['arguman']);
});
test('izin listesinde olmayan araç reddedilir', function () use ($izinli) {
    foreach (['../config', 'pasif_modul', 'system'] as $ad) {
        $json = ['choices' => [['message' => ['content' => null, 'tool_calls' => [['function' => ['name' => $ad, 'arguments' => '{}']]]]]]];
        esit('hata', LLMClient::yanitCoz($json, $izinli)['durum'], $ad);
    }
    $json = ['choices' => [['message' => ['content' => 'Bilmiyorum.', 'tool_calls' => [['function' => ['name' => 'rm_rf', 'arguments' => '{}']]]]]]];
    $s = LLMClient::yanitCoz($json, $izinli);
    esit('metin', $s['durum']);
    esit('Bilmiyorum.', $s['metin']);
});
test('düz metin cevabı ve <think> temizliği', function () use ($izinli) {
    $s = LLMClient::yanitCoz(['choices' => [['message' => ['content' => "<think>iç ses</think>\nMerhaba!"]]]], $izinli);
    esit('metin', $s['durum']);
    esit('Merhaba!', $s['metin']);
    esit('hata', LLMClient::yanitCoz(['choices' => [['message' => ['content' => '  ']]]], $izinli)['durum']);
    esit('hata', LLMClient::yanitCoz(['error' => ['message' => 'x']], $izinli)['durum']);
});
test('metin içine yazılmış araç (<function=...>) ve Groq tool_use_failed kurtarılır', function () use ($izinli) {
    $s = LLMClient::yanitCoz(['choices' => [['message' => ['content' => '<function=ders_programi>{"soru": "salı"}</function>']]]], $izinli);
    esit('arac', $s['durum']);
    esit('ders_programi', $s['arac']);
    esit('salı', $s['arguman']);
    $hata = ['error' => ['code' => 'tool_use_failed', 'failed_generation' => '<function=yemekhane>{"soru":"bugün"}</function>']];
    esit('yemekhane', LLMClient::basarisizUretimCoz($hata, $izinli)['arac'] ?? null);
    $kotu = ['error' => ['code' => 'tool_use_failed', 'failed_generation' => '<function=shell>{}</function>']];
    esit(null, LLMClient::basarisizUretimCoz($kotu, $izinli));
});
test('araçlar yalnız geçerli modül kodlarından üretilir', function () {
    [$araclar, $izin] = LLMClient::araclariHazirla([
        ['modul_kodu' => 'yemekhane', 'modul_adi' => 'Yemekhane Asistanı', 'buton_metni' => null],
        ['modul_kodu' => '../kotu', 'modul_adi' => 'Kötü'],
    ]);
    esit(['yemekhane', LLMClient::MENU_ARACI], $izin);
    esit('yemekhane', $araclar[0]['function']['name']);
    icerir($araclar[0]['function']['description'], 'Yemekhane');
    esit('{}', json_encode($araclar[1]['function']['parameters']['properties']));
});
test('sistem mesajı: Türkçe tarih, okul bilgisi ve kurallar', function () {
    $m = LLMClient::sistemMesaji('Test MYO', new DateTimeImmutable('2026-10-22 14:05', new DateTimeZone('Europe/Istanbul')));
    icerir($m, '22 Ekim 2026 Perşembe');
    icerir($m, 'Test MYO');
    icerir($m, 'Bunu bilmiyorum');
    icerir($m, 'telefon numarası');
    icerir($m, 'Türkçe');
});
test('API anahtarı yoksa ağa çıkmadan hata döner', function () {
    esit('hata', (new LLMClient(''))->chat('selam', [], [])['durum']);
});

// ======================================================================
grup('Bicim::markdownLite (LLM çıktısı)');
// ======================================================================
test('HTML kaçışlanır', function () {
    $c = Bicim::markdownLite('<script>alert(1)</script><img src=x onerror=alert(1)>');
    icermez($c, '<script');
    icermez($c, '<img');
    icerir($c, '&lt;script&gt;');
});
test('kalın, satır sonu ve listeler', function () {
    esit('<strong>Yemekhane</strong> açık', Bicim::markdownLite('**Yemekhane** açık'));
    esit('a<br>b', Bicim::markdownLite("a\nb"));
    esit('Liste:<br><ul><li>bir</li><li>iki</li></ul>', Bicim::markdownLite("Liste:\n- bir\n- iki"));
    esit('<ol><li>a</li><li>b</li></ol>', Bicim::markdownLite("1. a\n2. b"));
    esit('<strong>&lt;b&gt;x&lt;/b&gt;</strong>', Bicim::markdownLite('**<b>x</b>**'));
});
test('yalnız https bağlantıları', function () {
    $c = Bicim::markdownLite('Bak: https://omyo.edu.tr/a?b=1&c=2.');
    icerir($c, '<a href="https://omyo.edu.tr/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">');
    dogru(substr($c, -5) === '</a>.', 'sondaki nokta bağlantı dışında: ' . $c);
    icermez(Bicim::markdownLite('http://ornek.com'), '<a');
    icerir(Bicim::markdownLite('[site](https://omyo.edu.tr)'), '>site</a>');
});
test('javascript: ve öznitelik kaçışı denemeleri etkisiz', function () {
    $c = Bicim::markdownLite('[tıkla](javascript:alert(1)) ve [x](http://a.com)');
    icermez($c, 'href');
    $c = Bicim::markdownLite('https://x.com/"onmouseover=alert(1)');
    icerir($c, 'href="https://x.com/"');
    icerir($c, '&quot;onmouseover=alert(1)');
    icermez(Guvenlik::temizHtml($c), ' onmouseover=');
});

// ======================================================================
grup('BertClient');
// ======================================================================
test('kapsam_disi, bilinmiyor ve düşük güven "niyet yok" sayılır', function () {
    esit(null, BertClient::sonucuYorumla(['niyet' => 'kapsam_disi', 'eminlik' => 0.99], 0.65));
    esit(null, BertClient::sonucuYorumla(['niyet' => 'bilinmiyor', 'eminlik' => 0.9], 0.65));
    esit(null, BertClient::sonucuYorumla(['niyet' => 'yemekhane', 'eminlik' => 0.5], 0.65));
    esit(null, BertClient::sonucuYorumla(['niyet' => '../x', 'eminlik' => 0.9], 0.65));
    esit(['niyet' => 'yemekhane', 'eminlik' => 0.91, 'guven' => 91], BertClient::sonucuYorumla(['niyet' => 'yemekhane', 'eminlik' => 0.91], 0.65));
});

// ======================================================================
grup('Veritabanı testleri');
// ======================================================================
$db = null;
if (getenv('MISTIK_TEST_DB_NAME') && getenv('MISTIK_TEST_DB_USER')) {
    try {
        $db = new PDO(
            'mysql:host=' . (getenv('MISTIK_TEST_DB_HOST') ?: '127.0.0.1') . ';dbname=' . getenv('MISTIK_TEST_DB_NAME') . ';charset=utf8mb4',
            getenv('MISTIK_TEST_DB_USER'), (string)getenv('MISTIK_TEST_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (PDOException $e) {
        echo "  (veritabanına bağlanılamadı: {$e->getMessage()})\n";
        $SONUC['kalan']++;
    }
}

/** Testi bir işlem içinde çalıştırır ve geri alır. */
function dbTest(string $ad, callable $f): void {
    global $db;
    test($ad, function () use ($f, $db) {
        if (!$db) {
            atla('MISTIK_TEST_DB_* ortam değişkenleri yok');
        }
        RuleBasedRouter::onbellekTemizle();
        $db->beginTransaction();
        try {
            $f($db);
        } finally {
            $db->rollBack();
            RuleBasedRouter::onbellekTemizle();
        }
    });
}

dbTest('hız sınırı: 21. istek sınırı aşar, pencere sayacı doğru', function (PDO $db) {
    $anahtar = hash('sha256', 'test-' . random_bytes(8));
    $hiz = new HizSiniri($db);
    for ($i = 1; $i <= 21; $i++) {
        $s = $hiz->say($anahtar);
    }
    esit(21, $s['sayi']);
    dogru($s['kalan_sn'] >= 1 && $s['kalan_sn'] <= 60);
    $db->prepare("UPDATE bot_api_rate_limit SET last_request = NOW() - INTERVAL 61 SECOND WHERE ip_address = ?")->execute([$anahtar]);
    esit(1, $hiz->say($anahtar)['sayi'], 'pencere dolunca sayaç sıfırlanmalı');
});
dbTest('pasif modül DB\'de de reddedilir (eklenti dosyası olsa bile)', function (PDO $db) {
    $db->exec("INSERT INTO bot_moduller (modul_kodu, modul_adi, cevap_metni, aktif_mi) VALUES ('kampus_rehberi', 'Kampüs Rehberi', '', 0)
               ON DUPLICATE KEY UPDATE aktif_mi = 0");
    $m = new MistikEngine($db, ['bert' => new BertClient(''), 'llm' => new LLMClient(''), 'session_id' => 'test',
                               'plugin_dir' => dirname(__DIR__) . '/modules']);
    dogru(is_file(dirname(__DIR__) . '/modules/kampus_rehberi.php'));
    esit(false, $m->modulIzinli('kampus_rehberi'));
    esit(null, $m->executeModule('kampus_rehberi', 'lab', 'lab'));
});
dbTest('ders programı: salı / cumartesi / cuma gerçek modülle', function (PDO $db) {
    if ((int)$db->query("SELECT COUNT(*) FROM bot_ders_programi WHERE bolum_id = 1 AND sinif = 1")->fetchColumn() === 0
        || !$db->query("SELECT 1 FROM bot_moduller WHERE modul_kodu = 'ders_programi' AND aktif_mi = 1")->fetchColumn()) {
        atla('örnek ders programı (seed.sql) yok');
    }
    $_SESSION = ['context' => ['bolum_id' => 1, 'sinif' => 1]];
    $m = new MistikEngine($db, ['bert' => new BertClient(''), 'llm' => new LLMClient(''), 'session_id' => 'test',
                               'plugin_dir' => dirname(__DIR__) . '/modules']);
    $sali = $m->executeModule('ders_programi', Metin::temiz('Salı dersim var mı'), 'Salı dersim var mı');
    icerir($sali->metin, 'Salı Programı');
    icerir($sali->metin, 'BIL118');
    $cmt = $m->executeModule('ders_programi', Metin::temiz('cumartesi dersim var mı'), 'cumartesi dersim var mı');
    icerir($cmt->metin, 'Cumartesi');
    icermez($cmt->metin, 'BIL120');
    $cuma = $m->executeModule('ders_programi', Metin::temiz('CUMA'), 'CUMA');
    icerir($cuma->metin, 'BIL120');
    $pzt = $m->executeModule('ders_programi', Metin::temiz('pazartesi'), 'pazartesi');
    icerir($pzt->metin, 'Pazartesi Programı');
    icermez($pzt->metin, 'Pazartesı');
});
dbTest('log: katman, gecikme ve 0-100 güven yazılır; eski kayıtlar silinir', function (PDO $db) {
    try {
        $db->query("SELECT katman, gecikme_ms FROM bot_sohbet_loglari LIMIT 0");
    } catch (PDOException $e) {
        atla('katman/gecikme_ms sütunları yok (şema güncel değil)');
    }
    $oturum = hash('sha256', 'log-' . random_bytes(8));
    $logger = new Logger($db, []);
    $logger->log($oturum, 'test', null, 250, '<b>cevap</b>', true, 'kural', 12);
    $satir = $db->query("SELECT * FROM bot_sohbet_loglari WHERE session_id = " . $db->quote($oturum))->fetch();
    esit('kural', $satir['katman']);
    esit(12, (int)$satir['gecikme_ms']);
    esit(100, (int)$satir['guven_skoru']);
    esit('cevap', $satir['bot_cevabi']);
    $db->prepare("UPDATE bot_sohbet_loglari SET islem_tarihi = NOW() - INTERVAL 400 DAY WHERE session_id = ?")->execute([$oturum]);
    dogru($logger->eskiKayitlariSil(180) >= 1);
    esit(0, (int)$db->query("SELECT COUNT(*) FROM bot_sohbet_loglari WHERE session_id = " . $db->quote($oturum))->fetchColumn());
});

// ----------------------------------------------------------------------
echo "\n{$SONUC['gecen']} geçti, {$SONUC['kalan']} başarısız, {$SONUC['atlanan']} atlandı\n";
exit($SONUC['kalan'] > 0 ? 1 : 0);
