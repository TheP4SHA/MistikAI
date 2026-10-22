<?php
// admin/_baslat.php - Admin panelinin ortak başlangıcı
// Her admin sayfası ilk iş olarak bunu çağırır:
//   yapılandırma + veritabanı, güvenlik başlıkları, güvenli oturum, her POST'ta CSRF denetimi,
//   giriş zorunluluğu (PANEL_GIRISSIZ tanımlı sayfalar hariç) ve $BASE_PATH tabanlı URL yardımcıları.
//
// Kullanım:  require_once __DIR__ . '/../_baslat.php';            (admin/pages/*.php)
//            define('PANEL_GIRISSIZ', true); require_once __DIR__ . '/_baslat.php';   (login, kurulum)

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

$__yapilandirma = __DIR__ . '/../config/db.php';
if (!is_file($__yapilandirma)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Yapılandırma bulunamadı: config/db.php.example dosyasını config/db.php olarak kopyalayıp düzenleyin.\n");
}
require_once $__yapilandirma;
require_once __DIR__ . '/../src/Guvenlik.php';
global $db;

final class Panel {

    /** Panelin oturum açmış kullanıcısı (id, kullanici_adi, ad_soyad, yetki). */
    private static ?array $kullanici = null;

    /** Kök dizindeki (admin/*.php) giriş sayfaları; geri kalanlar admin/pages/ altındadır. */
    private const KOK_SAYFALAR = ['login', 'logout', 'register', 'kurulum'];

    /** Paneldeki tüm sayfalar (JS tarafına URL haritası olarak verilir). */
    public const SAYFALAR = ['login', 'logout', 'register', 'kurulum', 'dashboard', 'logger', 'modul', 'zeka', 'akis', 'icerik', 'ders', 'ayarlar'];

    /**
     * Panel sayfasının URL'si. $BASE_PATH projenin web kökü ('' ya da '/MistikAI').
     * $ADMIN_TEMIZ_URL true ise .htaccess temiz adresleri (/admin/dashboard), değilse doğrudan dosya yolu
     * (/admin/pages/dashboard.php) üretilir; ikincisi yeniden yazma kuralı olmadan da çalışır.
     */
    public static function url(string $sayfa, array $sorgu = []): string {
        $sayfa = preg_replace('/[^a-z0-9_]/', '', strtolower($sayfa));
        $kok = rtrim((string)($GLOBALS['BASE_PATH'] ?? ''), '/') . '/admin/';
        if (!empty($GLOBALS['ADMIN_TEMIZ_URL'])) {
            $yol = $kok . $sayfa;
        } else {
            $yol = $kok . (in_array($sayfa, self::KOK_SAYFALAR, true) ? '' : 'pages/') . $sayfa . '.php';
        }
        return $sorgu ? $yol . '?' . http_build_query($sorgu) : $yol;
    }

    public static function yonlendir(string $sayfa, array $sorgu = []): void {
        header('Location: ' . self::url($sayfa, $sorgu));
        exit;
    }

    /** Her admin yanıtında gönderilen güvenlik başlıkları. */
    public static function basliklariGonder(): void {
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
    }

    /** İstek JS'ten (fetch) mi geldi? Öyleyse hata yanıtları JSON olur. */
    public static function ajaxMi(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
            || strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
            || isset($_POST['action']);
    }

    /** JSON yanıtı gönderip betiği bitirir. */
    public static function json(array $veri, int $kod = 200): void {
        http_response_code($kod);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($veri, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** İsteği reddeder (403 varsayılan); AJAX isteklerine JSON, diğerlerine sade HTML döner. */
    public static function reddet(string $mesaj, int $kod = 403): void {
        if (self::ajaxMi()) {
            self::json(['success' => false, 'message' => $mesaj], $kod);
        }
        http_response_code($kod);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>MistikAI</title></head>'
           . '<body style="background:#0f172a;color:#f8fafc;font-family:sans-serif;text-align:center;padding-top:15vh;">'
           . '<h2 style="color:#ef4444;">' . Guvenlik::e($mesaj) . '</h2>'
           . '<p><a style="color:#8b5cf6;" href="' . Guvenlik::e(self::url('dashboard')) . '">Panele dön</a></p></body></html>';
        exit;
    }

    /**
     * İstisnayı sunucu günlüğüne yazar, kullanıcıya gösterilecek genel mesajı döner.
     * İstisna mesajları (SQL, dosya yolu vb.) asla tarayıcıya gönderilmez.
     */
    public static function hata(Throwable $e, string $baglam = ''): string {
        error_log(sprintf('[MistikAI admin]%s %s: %s @ %s:%d',
            $baglam !== '' ? ' ' . $baglam : '', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        return 'Beklenmeyen bir hata oluştu. Ayrıntılar sunucu günlüğüne yazıldı.';
    }

    /** Yakalanmamış istisnalar için son çare: günlüğe yaz, genel mesaj göster. */
    public static function yakalanmamis(Throwable $e): void {
        $mesaj = self::hata($e, 'yakalanmamış');
        if (!headers_sent()) {
            self::reddet($mesaj, 500);
        }
        echo '<p style="color:#ef4444;">' . Guvenlik::e($mesaj) . '</p>';
    }

    /** Oturum yoksa girişe yönlendirir; varsa kullanıcıyı veritabanından tazeler. */
    public static function girisZorunlu(PDO $db): void {
        $id = (int)($_SESSION['admin_id'] ?? 0);
        $kullanici = null;
        if ($id > 0) {
            $stmt = $db->prepare("SELECT id, kullanici_adi, ad_soyad, yetki FROM bot_admin_users WHERE id = ?");
            $stmt->execute([$id]);
            $kullanici = $stmt->fetch() ?: null;
        }
        if (!$kullanici) {
            // Silinmiş kullanıcının oturumu da düşer.
            unset($_SESSION['admin_id'], $_SESSION['admin_ad']);
            if (self::ajaxMi()) {
                self::json(['success' => false, 'message' => 'Oturumunuz sona erdi. Lütfen yeniden giriş yapın.'], 401);
            }
            self::yonlendir('login');
        }
        self::$kullanici = $kullanici;
        $_SESSION['admin_ad'] = $kullanici['ad_soyad'];
    }

    public static function kullanici(): ?array {
        return self::$kullanici;
    }

    public static function tamYetkili(): bool {
        return (self::$kullanici['yetki'] ?? '') === 'tam';
    }

    /** Tarayıcı içi PHP editörü ve SQL terminali: yalnız yapılandırmada açıksa VE kullanıcı tam yetkiliyse. */
    public static function kodEditoruAcik(): bool {
        return ($GLOBALS['ADMIN_KOD_EDITORU'] ?? false) === true && self::tamYetkili();
    }

    /** Sistem ayarı okur (bot_sistem_ayarlari). */
    public static function ayar(PDO $db, string $ad, ?string $varsayilan = null): ?string {
        $stmt = $db->prepare("SELECT ayar_degeri FROM bot_sistem_ayarlari WHERE ayar_adi = ?");
        $stmt->execute([$ad]);
        $deger = $stmt->fetchColumn();
        return $deger === false ? $varsayilan : (string)$deger;
    }

    /** bot_sohbet_loglari.katman değerleri: cevabı hangi katman üretti. [etiket, renk] */
    public const KATMANLAR = [
        'kural' => ['Kural', '#10b981'],
        'bert'  => ['BERT', '#3b82f6'],
        'llm'   => ['LLM', '#8b5cf6'],
        'akis'  => ['Akış', '#f59e0b'],
        'komut' => ['Komut', '#14b8a6'],
        'sinir' => ['Sınır', '#ef4444'],
    ];

    /** Katman rozeti (HTML). Bilinmeyen/boş katman (eski kayıtlar) gri gösterilir. */
    public static function katmanRozeti(?string $katman): string {
        [$etiket, $renk] = self::KATMANLAR[$katman ?? ''] ?? [$katman ? $katman : '—', '#64748b'];
        return '<span class="badge" style="background:' . $renk . '22; color:' . $renk . '; border:1px solid ' . $renk . '55;">'
             . Guvenlik::e($etiket) . '</span>';
    }

    /** JS'in kullandığı panel bilgileri: CSRF jetonu ve sayfa URL'leri (layout <head> içinde basılır). */
    public static function jsAyarlari(): string {
        $urller = [];
        foreach (self::SAYFALAR as $s) {
            $urller[$s] = self::url($s);
        }
        return json_encode(
            ['csrf' => Guvenlik::csrfToken(), 'urller' => $urller],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}

set_exception_handler([Panel::class, 'yakalanmamis']);

Panel::basliklariGonder();
Guvenlik::oturumBaslat();

// Her POST isteği (formlar ve fetch) geçerli CSRF jetonu taşımalı: form alanı _csrf ya da X-CSRF-Token başlığı.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !Guvenlik::csrfDogrula(Guvenlik::istekCsrf())) {
    Panel::reddet('Güvenlik doğrulaması başarısız (CSRF). Oturumunuz sona ermiş olabilir; sayfayı yenileyip tekrar deneyin.', 403);
}

if (!defined('PANEL_GIRISSIZ')) {
    Panel::girisZorunlu($db);
}
