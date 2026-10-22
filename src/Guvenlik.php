<?php
// src/Guvenlik.php - Ortak güvenlik yardımcıları
// Oturum sertleştirme, CSRF, HTML/JS kaçışı, izinli etiket listesiyle HTML süzgeci,
// istemci IP'si (güvenilir vekil desteğiyle), IP özeti (KVKK) ve dış URL denetimi (SSRF).

final class Guvenlik {

    /** Modül/akış cevaplarında izin verilen etiketler ve öznitelikleri. */
    private const IZINLI = [
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 'br' => [], 'p' => ['style', 'class'],
        'ul' => ['style', 'class'], 'ol' => ['style', 'class'], 'li' => ['style', 'class'], 'small' => ['style', 'class'],
        'span' => ['style', 'class'], 'div' => ['style', 'class'], 'code' => [], 'hr' => ['style'],
        'table' => ['style', 'class'], 'thead' => [], 'tbody' => [], 'tr' => ['style'], 'th' => ['style'], 'td' => ['style', 'colspan'],
        'a' => ['href', 'target', 'style', 'class'], 'img' => ['src', 'alt', 'style', 'width', 'height'],
    ];

    /** Oturumu güvenli çerez ayarlarıyla başlatır (HttpOnly, SameSite=Lax, HTTPS'te Secure, strict mode). */
    public static function oturumBaslat(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('MISTIKSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Girişten sonra oturum kimliğini yeniler (oturum sabitleme saldırısına karşı). */
    public static function oturumYenile(): void {
        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
    }

    public static function csrfToken(): string {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function csrfDogrula(?string $token): bool {
        return is_string($token) && $token !== '' && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    /** Formlara eklenecek gizli alan: <?= Guvenlik::csrfAlani() ?> */
    public static function csrfAlani(): string {
        return '<input type="hidden" name="_csrf" value="' . self::e(self::csrfToken()) . '">';
    }

    /** İstekteki CSRF jetonu: form alanı _csrf ya da X-CSRF-Token başlığı. */
    public static function istekCsrf(): ?string {
        return $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    }

    /** HTML metni/özniteliği için kaçış. */
    public static function e(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Satır içi JS özniteliğine (onclick="f(<?= Guvenlik::js($x) ?>)") güvenle konacak değer.
     * json_encode JS sabiti üretir, htmlspecialchars öznitelik bağlamını korur.
     */
    public static function js($deger): string {
        return htmlspecialchars(
            json_encode($deger, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8'
        );
    }

    /**
     * İstemci IP'si. X-Forwarded-For yalnız istek $GUVENILIR_VEKILLER listesindeki bir vekilden
     * geldiyse okunur; aksi halde başlık sahtelenebileceği için REMOTE_ADDR kullanılır.
     */
    public static function istemciIp(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $vekiller = (array)($GLOBALS['GUVENILIR_VEKILLER'] ?? []);
        if ($vekiller && in_array($ip, $vekiller, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ilk = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($ilk, FILTER_VALIDATE_IP)) {
                return $ilk;
            }
        }
        return $ip;
    }

    /** Loglarda ve hız sınırında IP yerine tutulan tuzlu özet (64 karakter hex). */
    public static function ipOzeti(?string $ip = null): string {
        return hash('sha256', (string)($GLOBALS['IP_TUZU'] ?? '') . '|' . ($ip ?? self::istemciIp()));
    }

    /**
     * Modül/akış HTML'ini izinli etiket listesine göre süzer. Betik, olay öznitelikleri,
     * javascript:/data: bağlantıları ve tehlikeli CSS atılır; metin korunur.
     */
    public static function temizHtml(string $html): string {
        if ($html === '' || strpos($html, '<') === false) {
            return $html;
        }
        $kaynak = new DOMDocument('1.0', 'UTF-8');
        $onceki = libxml_use_internal_errors(true);
        $kaynak->loadHTML('<?xml encoding="UTF-8"><div id="__kok">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($onceki);

        $kok = $kaynak->getElementById('__kok');
        if (!$kok) {
            return self::e(strip_tags($html));
        }
        $hedef = new DOMDocument('1.0', 'UTF-8');
        $kap = $hedef->createElement('div');
        $hedef->appendChild($kap);
        foreach (iterator_to_array($kok->childNodes) as $cocuk) {
            self::kopyala($cocuk, $kap, $hedef);
        }
        $cikti = '';
        foreach ($kap->childNodes as $n) {
            $cikti .= $hedef->saveHTML($n);
        }
        return $cikti;
    }

    private static function kopyala(DOMNode $dugum, DOMNode $ebeveyn, DOMDocument $hedef): void {
        if ($dugum instanceof DOMText) {
            $ebeveyn->appendChild($hedef->createTextNode($dugum->nodeValue ?? ''));
            return;
        }
        if (!$dugum instanceof DOMElement) {
            return; // yorum, işlem talimatı vb. atılır
        }
        $ad = strtolower($dugum->nodeName);
        if (in_array($ad, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript'], true)) {
            return; // içerikleriyle birlikte atılır
        }
        if (!isset(self::IZINLI[$ad])) {
            foreach (iterator_to_array($dugum->childNodes) as $cocuk) {
                self::kopyala($cocuk, $ebeveyn, $hedef); // etiket atılır, içerik kalır
            }
            return;
        }
        $yeni = $hedef->createElement($ad);
        foreach (self::IZINLI[$ad] as $oz) {
            if (!$dugum->hasAttribute($oz)) {
                continue;
            }
            $deger = trim($dugum->getAttribute($oz));
            if ($oz === 'href' && !self::guvenliBaglanti($deger, ['http', 'https', 'mailto', 'tel'])) {
                continue;
            }
            if ($oz === 'src' && !self::guvenliBaglanti($deger, ['https'])) {
                continue;
            }
            if ($oz === 'style' && preg_match('/url\s*\(|expression|@import|javascript:|behavior\s*:/i', $deger)) {
                continue;
            }
            if ($oz === 'target') {
                $deger = '_blank';
            }
            $yeni->setAttribute($oz, $deger);
        }
        if ($ad === 'a' && $yeni->hasAttribute('target')) {
            $yeni->setAttribute('rel', 'noopener noreferrer');
        }
        $ebeveyn->appendChild($yeni);
        foreach (iterator_to_array($dugum->childNodes) as $cocuk) {
            self::kopyala($cocuk, $yeni, $hedef);
        }
    }

    private static function guvenliBaglanti(string $url, array $semalar): bool {
        if ($url === '' || preg_match('/[\x00-\x1F]/', $url)) {
            return false;
        }
        $sema = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return $sema !== '' && in_array($sema, $semalar, true);
    }

    /**
     * Sunucunun istek atacağı dış URL'yi denetler (SSRF): yalnız http/https, verilmişse izinli
     * host listesi, ve hostun bütün IP'leri genel internette olmalı (özel/ayrılmış aralık yok).
     */
    public static function disUrlGuvenli(string $url, array $izinliHostlar = []): bool {
        $p = parse_url($url);
        $sema = strtolower((string)($p['scheme'] ?? ''));
        $host = strtolower((string)($p['host'] ?? ''));
        if (!in_array($sema, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        if ($izinliHostlar) {
            $uygun = false;
            foreach ($izinliHostlar as $h) {
                $h = strtolower(ltrim((string)$h, '.'));
                if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) {
                    $uygun = true;
                    break;
                }
            }
            if (!$uygun) {
                return false;
            }
        }
        $ipler = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            (array)@gethostbynamel($host),
            array_column((array)@dns_get_record($host, DNS_AAAA), 'ipv6')
        );
        $ipler = array_filter($ipler);
        if (!$ipler) {
            return false;
        }
        foreach ($ipler as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }
}
