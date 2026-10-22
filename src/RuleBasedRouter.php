<?php
// src/RuleBasedRouter.php - Kural Tabanlı Niyet Motoru
// Anahtar kelime + öncelik skoru ile kullanıcı niyetini belirler.
// Eşleşme kelime sınırlarına göre yapılır; iki geçiş vardır:
//   1) Türkçe küçük harfe çevrilmiş metin ("İLETİŞİM" → "iletişim")
//   2) ASCII katlanmış metin ("iletisim" yazan kullanıcı için) + 6+ harfli kelimelerde tek harf yazım hatası toleransı
// 4+ harfli anahtar kelime, kelimenin başıyla da eşleşir (ek toleransı: "yemekhanede" → "yemekhane").
// İlk iki modülün skoru birbirine çok yakınsa "belirsiz" döner ve adayları verir.

require_once __DIR__ . '/Metin.php';

class RuleBasedRouter {
    private const ONBELLEK_SURE = 60;          // saniye; admin değişiklikleri en geç bu sürede yansır
    private const ONBELLEK_ANAHTARI = 'mistikai_router_v2';
    private const EK_MIN_UZUNLUK = 4;          // ek toleransı için anahtar kelimenin en kısa hali
    private const EK_MAX_FAZLA = 8;            // ek toleransında kelimeye eklenebilecek en fazla harf
    private const YAZIM_MIN_UZUNLUK = 6;       // yazım hatası toleransı için en kısa kelime
    private const HAM_SKOR_TAVANI = 120;       // öncelik (≤100) + uzunluk bonusu (≤20)

    private ?PDO $db;
    private array $keywords = [];     // Önbelleklenmiş, önceden normalleştirilmiş anahtar kelimeler
    private array $modules = [];      // Aktif modüller (modul_kodu => satır)
    private int $ambiguityThreshold = 15; // Ham skor farkı bu değerin altındaysa "belirsiz"

    /**
     * @param PDO|null   $db        Veritabanı (testlerde null olabilir)
     * @param array|null $kelimeler Testler için hazır anahtar kelime satırları (verilirse DB okunmaz)
     * @param array|null $moduller  Testler için hazır aktif modül satırları
     */
    public function __construct(?PDO $db, ?array $kelimeler = null, ?array $moduller = null) {
        $this->db = $db;
        if ($kelimeler !== null || $moduller !== null) {
            $this->veriyiKur($kelimeler ?? [], $moduller ?? []);
            return;
        }
        $veri = $this->onbellektenOku();
        if ($veri === null) {
            $veri = $this->veritabanindanOku();
            $this->onbellegeYaz($veri);
        }
        $this->keywords = $veri['kelimeler'];
        $this->modules = $veri['moduller'];
    }

    // ------------------------------------------------------------------
    // Veri yükleme ve önbellek
    // ------------------------------------------------------------------

    private function veritabanindanOku(): array {
        if (!$this->db) {
            return ['kelimeler' => [], 'moduller' => []];
        }
        $kelimeler = $this->db->query("
            SELECT k.kelime, k.modul_id, k.oncelik, k.cevap_override, k.zaman_kontrol,
                   m.modul_kodu, m.modul_adi
            FROM bot_anahtar_kelimeler k
            JOIN bot_moduller m ON k.modul_id = m.id
            WHERE k.aktif_mi = 1 AND m.aktif_mi = 1
            ORDER BY k.oncelik DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $moduller = $this->db->query("SELECT * FROM bot_moduller WHERE aktif_mi = 1")->fetchAll(PDO::FETCH_ASSOC);

        $this->veriyiKur($kelimeler, $moduller);
        return ['kelimeler' => $this->keywords, 'moduller' => $this->modules];
    }

    /** Satırları normalleştirip iç yapıya çevirir (kelime listeleri önceden hesaplanır). */
    private function veriyiKur(array $kelimeler, array $moduller): void {
        $this->modules = [];
        foreach ($moduller as $m) {
            if (isset($m['aktif_mi']) && !(int)$m['aktif_mi']) {
                continue; // pasif modül yönlendirilemez ve çalıştırılamaz
            }
            $this->modules[$m['modul_kodu']] = $m;
        }
        $this->keywords = [];
        foreach ($kelimeler as $k) {
            $tr = Metin::normalize((string)$k['kelime']);
            if ($tr === '' || !isset($this->modules[$k['modul_kodu']]) || (isset($k['aktif_mi']) && !(int)$k['aktif_mi'])) {
                continue;
            }
            $k['tr'] = explode(' ', $tr);
            $k['ascii'] = explode(' ', Metin::katla($tr));
            $k['uzunluk'] = mb_strlen($tr);
            $this->keywords[] = $k;
        }
    }

    private static function onbellekAnahtari(): string {
        return self::ONBELLEK_ANAHTARI . '_' . substr(md5(__DIR__ . '|' . ($GLOBALS['DB_NAME'] ?? '')), 0, 12);
    }

    private static function onbellekDizini(): ?string {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : 'x';
        $dizin = sys_get_temp_dir() . '/mistikai-onbellek-' . $uid;
        if (!is_dir($dizin) && !@mkdir($dizin, 0700) && !is_dir($dizin)) {
            return null;
        }
        // Başkasının oluşturduğu ya da herkese yazılabilir dizine güvenme.
        if ((fileperms($dizin) & 0022) !== 0 || (function_exists('posix_geteuid') && fileowner($dizin) !== posix_geteuid())) {
            return null;
        }
        return $dizin;
    }

    private function onbellektenOku(): ?array {
        $anahtar = self::onbellekAnahtari();
        if (function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            $veri = apcu_fetch($anahtar, $basarili);
            return ($basarili && is_array($veri)) ? $veri : null;
        }
        $dizin = self::onbellekDizini();
        $dosya = $dizin ? "{$dizin}/{$anahtar}.json" : null;
        if (!$dosya || !is_file($dosya) || (time() - (int)@filemtime($dosya)) > self::ONBELLEK_SURE) {
            return null;
        }
        $veri = json_decode((string)@file_get_contents($dosya), true);
        return (is_array($veri) && isset($veri['kelimeler'], $veri['moduller'])) ? $veri : null;
    }

    private function onbellegeYaz(array $veri): void {
        $anahtar = self::onbellekAnahtari();
        if (function_exists('apcu_store') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            apcu_store($anahtar, $veri, self::ONBELLEK_SURE);
            return;
        }
        $dizin = self::onbellekDizini();
        if (!$dizin) {
            return;
        }
        $gecici = @tempnam($dizin, 'rt');
        if ($gecici && @file_put_contents($gecici, json_encode($veri, JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($gecici, "{$dizin}/{$anahtar}.json");
        } elseif ($gecici) {
            @unlink($gecici);
        }
    }

    /** Anahtar kelime / modül değişikliğinden sonra çağrılabilir (admin paneli). */
    public static function onbellekTemizle(): void {
        if (function_exists('apcu_delete')) {
            @apcu_delete(self::onbellekAnahtari());
        }
        $dizin = self::onbellekDizini();
        if ($dizin) {
            foreach ((array)glob($dizin . '/' . self::ONBELLEK_ANAHTARI . '_*.json') as $f) {
                @unlink($f);
            }
        }
    }

    // ------------------------------------------------------------------
    // Analiz
    // ------------------------------------------------------------------

    /**
     * Kullanıcı mesajını analiz et ve niyet belirle.
     *
     * @return array [
     *   'status'      => 'matched'|'ambiguous'|'no_match',
     *   'module_code' => string|null,
     *   'score'       => int (0-100),
     *   'override'    => string|null,
     *   'exact'       => bool  (en iyi eşleşme ek/yazım toleransı olmadan mı bulundu),
     *   'candidates'  => [['code' => ..., 'name' => ..., 'score' => 0-100], ...] (en fazla 3)
     * ]
     */
    public function analyze(string $userInput): array {
        $tr = Metin::normalize($userInput);
        $bos = ['status' => 'no_match', 'module_code' => null, 'score' => 0, 'override' => null, 'exact' => false, 'candidates' => []];
        if ($tr === '') {
            return $bos;
        }
        $girdiTr = explode(' ', $tr);
        $girdiAscii = explode(' ', Metin::katla($tr));

        $scores = []; // modul_kodu => ['score' => int, 'matches' => int, 'override' => ?string, 'exact' => bool]
        foreach ($this->keywords as $kw) {
            $tur = $this->ifadeEslesir($kw['tr'], $girdiTr, false);
            $asciiGecis = false;
            if ($tur === null) {
                $tur = $this->ifadeEslesir($kw['ascii'], $girdiAscii, true);
                $asciiGecis = true;
            }
            if ($tur === null) {
                continue;
            }

            $modulKodu = $kw['modul_kodu'];
            $oncelik = (int)$kw['oncelik'];

            // Zaman kontrolü (sabah/akşam gibi bağlam)
            if (!empty($kw['zaman_kontrol']) && !$this->checkTimeContext($kw['zaman_kontrol'])) {
                $oncelik = max(1, $oncelik - 20); // Zaman uyumsuzsa skoru düşür
            }

            // Skor: öncelik + kelime uzunluğu bonusu (daha spesifik = daha iyi); tolerans kullanıldıysa küçük ceza.
            $skor = $oncelik + min(20, $kw['uzunluk'] * 2);
            $skor -= ['tam' => 0, 'ek' => 3, 'yazim' => 8][$tur] + ($asciiGecis ? 1 : 0);

            if (!isset($scores[$modulKodu])) {
                $scores[$modulKodu] = ['score' => 0, 'matches' => 0, 'override' => null, 'exact' => false];
            }
            $scores[$modulKodu]['matches']++;
            if ($skor > $scores[$modulKodu]['score']) {
                $scores[$modulKodu]['score'] = $skor;
                // cevap_override en iyi skoru veren kelimeden gelir
                $scores[$modulKodu]['override'] = $kw['cevap_override'] ?: null;
                $scores[$modulKodu]['exact'] = ($tur === 'tam' && !$asciiGecis);
            }
        }

        if (empty($scores)) {
            return $bos;
        }

        // Aynı modüle işaret eden birden fazla kelime küçük bir bonus getirir.
        foreach ($scores as &$s) {
            $s['score'] += min(10, 3 * ($s['matches'] - 1));
        }
        unset($s);

        uasort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        $kodlar = array_keys($scores);
        $bestCode = $kodlar[0];
        $best = $scores[$bestCode];

        $adaylar = [];
        foreach (array_slice($kodlar, 0, 3) as $kod) {
            $adaylar[] = [
                'code'  => $kod,
                'name'  => $this->modules[$kod]['modul_adi'] ?? $kod,
                'score' => self::guvenSkoru($scores[$kod]['score']),
            ];
        }

        // Belirsizlik: ilk iki sonuç arasındaki fark çok azsa adayları döndür.
        if (count($kodlar) >= 2 && ($best['score'] - $scores[$kodlar[1]]['score']) < $this->ambiguityThreshold) {
            return [
                'status'      => 'ambiguous',
                'module_code' => $bestCode,
                'score'       => self::guvenSkoru($best['score']),
                'override'    => null,
                'exact'       => $best['exact'],
                'candidates'  => $adaylar,
            ];
        }

        return [
            'status'      => 'matched',
            'module_code' => $bestCode,
            'score'       => self::guvenSkoru($best['score']),
            'override'    => $best['override'],
            'exact'       => $best['exact'],
            'candidates'  => $adaylar,
        ];
    }

    /** Ham skoru (≈0-120) 0-100 güven skoruna çevirir. */
    public static function guvenSkoru(int $ham): int {
        return (int)max(0, min(100, round($ham * 100 / self::HAM_SKOR_TAVANI)));
    }

    /**
     * Anahtar ifade (kelime dizisi) girdide ardışık kelimeler olarak geçiyor mu?
     * @return string|null 'tam' | 'ek' | 'yazim' (en zayıf kelime eşleşmesi) ya da null
     */
    private function ifadeEslesir(array $ifade, array $girdi, bool $yazimToleransi): ?string {
        $n = count($ifade);
        $sinir = count($girdi) - $n;
        $sira = ['tam' => 0, 'ek' => 1, 'yazim' => 2];
        for ($i = 0; $i <= $sinir; $i++) {
            $enKotu = 'tam';
            for ($j = 0; $j < $n; $j++) {
                $tur = self::kelimeEslesir($ifade[$j], $girdi[$i + $j], $yazimToleransi);
                if ($tur === null) {
                    continue 2;
                }
                if ($sira[$tur] > $sira[$enKotu]) {
                    $enKotu = $tur;
                }
            }
            return $enKotu;
        }
        return null;
    }

    /** Tek kelime eşleşmesi: tam, ek toleranslı (kelimenin başı) ya da tek harf yazım hatası. */
    public static function kelimeEslesir(string $anahtar, string $kelime, bool $yazimToleransi = true): ?string {
        if ($anahtar === $kelime) {
            return 'tam';
        }
        $ka = mb_strlen($anahtar);
        $kk = mb_strlen($kelime);
        if ($ka >= self::EK_MIN_UZUNLUK && $kk > $ka && $kk - $ka <= self::EK_MAX_FAZLA && strncmp($kelime, $anahtar, strlen($anahtar)) === 0) {
            return 'ek';
        }
        if ($yazimToleransi && $ka >= self::YAZIM_MIN_UZUNLUK && $kk >= self::YAZIM_MIN_UZUNLUK - 1 && abs($ka - $kk) <= 1
            && levenshtein($anahtar, $kelime) <= 1) {
            return 'yazim';
        }
        return null;
    }

    private function checkTimeContext(string $context): bool {
        $hour = (int)date('H');
        return match($context) {
            'sabah' => $hour >= 5 && $hour < 12,
            'gun'   => $hour >= 12 && $hour < 17,
            'aksam' => $hour >= 17 && $hour < 22,
            'gece'  => $hour >= 22 || $hour < 5,
            default => true
        };
    }

    /** Aktif modüller (modul_kodu => satır). */
    public function getModules(): array {
        return $this->modules;
    }
}
