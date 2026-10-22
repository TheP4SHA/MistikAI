<?php
// src/MistikEngine.php - MistikAI Ana Motor (Hibrit AI Orkestratörü)
// 3 katmanlı mimariyi yönetir: Rule-Based → BERT → LLM
// Her katman sırasıyla denenir, ilk başarılı sonuç kullanılır.
//
// Oturumda küçük bir konuşma durumu tutulur ($_SESSION['mistik']):
//   bekleyen  : çok adımlı modülün bir sonraki mesajı beklediği slot (örn. rehberde "kimi arıyorsun?")
//   son_modul : son çalışan modül; "peki yarın?" gibi takip soruları ona gider
//   gecmis    : LLM'e giden son mesajlar (modül cevapları kısa özet olarak)
//
// Motor iç cevap olarak MistikCevap döndürür; JSON'a yalnız public/api.php çevirir.

require_once __DIR__ . '/Guvenlik.php';
require_once __DIR__ . '/Metin.php';
require_once __DIR__ . '/MistikCevap.php';
require_once __DIR__ . '/Bicim.php';
require_once __DIR__ . '/TarihYardimcisi.php';
require_once __DIR__ . '/RuleBasedRouter.php';
require_once __DIR__ . '/BertClient.php';
require_once __DIR__ . '/LLMClient.php';
require_once __DIR__ . '/Logger.php';

class MistikEngine {
    private const MODUL_KODU = '/^[a-z0-9_]{2,40}$/';
    private const KOMUT = '/^(flow|modul|set_program)_[a-z0-9_]{1,60}$/';
    private const BEKLEME_SURESI = 300;     // bekleyen modül slotunun ömrü (sn)
    private const SON_MODUL_SURESI = 600;   // takip sorusunun son modüle gidebileceği süre (sn)
    private const GECMIS_MESAJ = 6;         // LLM'e giden son mesaj sayısı
    private const IPTAL = '/^(iptal|vazgec|vazgectim|bosver|bos ver|kapat)$/';
    private const BILMIYORUM = '/\b(bilmiyorum|emin degilim|bilgim yok|bilgiye sahip degilim|bu konuda bilgim)\b/';

    // LLM'in kendi olasılık değeri yok; günlükte 0-100 ölçeğinde sabit güven kullanılır.
    private const LLM_GUVEN_ARAC = 75;
    private const LLM_GUVEN_METIN = 60;
    private const LLM_GUVEN_BILMIYOR = 10;
    private const TAKIP_GUVEN = 70;

    private ?PDO $db;
    private RuleBasedRouter $router;
    private BertClient $bert;
    private LLMClient $llm;
    private Logger $logger;
    private string $pluginDir;
    private string $sessionId;
    private int $logSaklamaGun;
    private ?string $aktifModul = null;

    /**
     * @param PDO|null $db
     * @param array    $config groq_api_key, bert_api_url, bert_api_key, llm_model, llm_yedek_model, llm_max_tokens,
     *                         llm_api_url, okul_bilgisi, log_saklama_gun, plugin_dir, session_id
     *                         (testler için: router, bert, llm nesneleri doğrudan verilebilir)
     */
    public function __construct(?PDO $db, array $config = []) {
        $this->db = $db;
        $this->pluginDir = rtrim((string)($config['plugin_dir'] ?? __DIR__ . '/../modules'), '/\\');
        $this->sessionId = (string)($config['session_id'] ?? Guvenlik::ipOzeti());
        $this->logSaklamaGun = (int)($config['log_saklama_gun'] ?? 180);

        // Alt bileşenleri başlat
        $this->router = $config['router'] ?? new RuleBasedRouter($db);
        $this->bert   = $config['bert'] ?? new BertClient(
            (string)($config['bert_api_url'] ?? ''),
            (string)($config['bert_api_key'] ?? ''),
            (float)($config['bert_esik'] ?? 0.65)
        );
        $this->llm    = $config['llm'] ?? new LLMClient((string)($config['groq_api_key'] ?? ''), [
            'model'        => $config['llm_model'] ?? 'llama-3.3-70b-versatile',
            'yedek_model'  => $config['llm_yedek_model'] ?? 'llama-3.1-8b-instant',
            'max_tokens'   => $config['llm_max_tokens'] ?? 400,
            'api_url'      => $config['llm_api_url'] ?? LLMClient::VARSAYILAN_URL,
            'okul_bilgisi' => $config['okul_bilgisi'] ?? '', // boşsa LLMClient::VARSAYILAN_OKUL
        ]);
        $this->logger = new Logger($db, $this->router->getModules());
    }

    /**
     * Kullanıcı mesajını işle ve cevap üret.
     *
     * @param string      $mesaj   Kullanıcının yazdığı (ya da tıkladığı butonun) metni
     * @param string|null $payload Buton komutu: flow_<menu>, modul_<kod>, set_program_<bolum>_<sinif>
     */
    public function process(string $mesaj, ?string $payload = null): MistikCevap {
        $baslangic = microtime(true);
        $mesaj = trim($mesaj);
        $payload = $payload !== null ? trim($payload) : null;

        $komut = ($payload !== null && preg_match(self::KOMUT, $payload)) ? $payload : null;
        if ($komut === null && $mesaj === '' && $payload !== null) {
            $mesaj = $payload; // Eski istemci: komut olmayan payload düz metin sayılır
        }
        if ($komut === null && $mesaj === '') {
            return $this->response('Seni dinliyorum... 🙂');
        }

        try {
            $cevap = $komut !== null ? $this->komutIsle($komut, $mesaj) : $this->metinIsle($mesaj);
        } catch (Throwable $e) {
            error_log('[MistikAI] Motor hatası: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $cevap = $this->response('⚠️ Bu isteği şu an işleyemedim. Lütfen biraz sonra tekrar dene.');
            $cevap->hata = true;
        }

        $cevap = $this->guvenliHaleGetir($cevap);
        $cevap->gecikmeMs = (int)round((microtime(true) - $baslangic) * 1000);

        $logMesaji = $komut !== null ? trim($mesaj . ' [' . $komut . ']') : $mesaj;
        $this->logger->log($this->sessionId, $logMesaji, $cevap->niyet, $cevap->guven, $cevap->metin,
                           $cevap->anlasildi, $cevap->katman, $cevap->gecikmeMs);
        $this->logger->belkiTemizle($this->logSaklamaGun);
        $this->gecmiseEkle($mesaj !== '' ? $mesaj : (string)$komut, $cevap);

        return $cevap;
    }

    // ------------------------------------------------------------------
    // Komutlar (buton tıklamaları)
    // ------------------------------------------------------------------

    private function komutIsle(string $komut, string $mesaj): MistikCevap {
        $this->beklemeyiTemizle(); // Buton tıklaması bekleyen soruyu iptal eder

        if (strpos($komut, 'flow_') === 0) {
            return $this->handleFlow(substr($komut, 5));
        }

        if (strpos($komut, 'modul_') === 0) {
            $kod = substr($komut, 6);
            $metin = $mesaj !== '' ? $mesaj : (string)($this->router->getModules()[$kod]['modul_adi'] ?? $kod);
            $cevap = $this->executeModule($kod, Metin::temiz($metin), $metin);
            if ($cevap === null) {
                return $this->etiketle($this->response('Bu hizmet şu an kullanılamıyor.'), 'komut', null, 0, false);
            }
            return $this->etiketle($cevap, 'komut', $kod, 100, true);
        }

        return $this->handleProgramSelection($komut, $mesaj);
    }

    // ------------------------------------------------------------------
    // Serbest metin: bekleyen modül → kural → takip → BERT → LLM
    // ------------------------------------------------------------------

    private function metinIsle(string $mesaj): MistikCevap {
        $temiz = Metin::temiz($mesaj);
        $kural = null;

        // ── 0. Bekleyen çok adımlı modül (örn. rehber "kimi arıyorsun?" dedi) ──
        $bekleyen = $this->bekleyenAl();
        if ($bekleyen !== null) {
            $this->beklemeyiTemizle();
            if (preg_match(self::IPTAL, $temiz)) {
                $cevap = $this->response('Tamam, vazgeçtik. Başka nasıl yardımcı olabilirim? 🙂');
                return $this->etiketle($cevap, 'akis', $bekleyen['modul'], 100, true);
            }
            // Kullanıcı açıkça başka bir konuya geçtiyse (tam anahtar kelime eşleşmesi) bekleyen soru düşer.
            $kural = $this->router->analyze($mesaj);
            $baskaKonu = $kural['status'] === 'matched' && $kural['exact'] && $kural['module_code'] !== $bekleyen['modul'];
            if (!$baskaKonu) {
                $cevap = $this->executeModule($bekleyen['modul'], $temiz, $mesaj, $bekleyen['veri']);
                if ($cevap !== null) {
                    return $this->etiketle($cevap, 'akis', $bekleyen['modul'], 100, true);
                }
            }
        }

        // ── 1. KATMAN: Rule-Based Router ──
        $kural = $kural ?? $this->router->analyze($mesaj);
        $bert = null;

        if ($kural['status'] === 'matched') {
            $kod = $kural['module_code'];
            // Doğrudan cevap varsa (cevap_override) onu kullan
            if ($kural['override']) {
                return $this->etiketle($this->response($kural['override']), 'kural', $kod, $kural['score'], true);
            }
            $cevap = $this->executeModule($kod, $temiz, $mesaj);
            if ($cevap !== null) {
                return $this->etiketle($cevap, 'kural', $kod, $kural['score'], true);
            }
            // Modülün ne dosyası ne sabit cevabı var: sonraki katmanlara bırak.
        }

        if ($kural['status'] === 'ambiguous') {
            // Önce BERT'e sor; adaylardan birini güvenle seçerse onu çalıştır, yoksa kullanıcıya sor.
            $bert = $this->bert->detectIntent($mesaj);
            if ($bert && in_array($bert['niyet'], array_column($kural['candidates'], 'code'), true)) {
                $cevap = $this->executeModule($bert['niyet'], $temiz, $mesaj);
                if ($cevap !== null) {
                    return $this->etiketle($cevap, 'bert', $bert['niyet'], $bert['guven'], true);
                }
            }
            return $this->adaylariSor($kural);
        }

        // ── Takip sorusu: "peki yarın?" → son kullanılan modül ──
        $son = $this->sonModulAl();
        if ($kural['status'] === 'no_match' && $son !== null && count(explode(' ', $temiz)) <= 6 && self::zamanIpucuVar($temiz)) {
            $cevap = $this->executeModule($son, $temiz, $mesaj);
            if ($cevap !== null) {
                return $this->etiketle($cevap, 'akis', $son, self::TAKIP_GUVEN, true);
            }
        }

        // ── 2. KATMAN: BERT Niyet Algılama ──
        // Yalnız eklenti dosyası olan (teknik) modüller doğrudan çalıştırılır; sanal niyetler
        // (selamlama vb.) ve "kapsam_disi" daha doğal cevap için LLM'e kalır.
        $bert = $bert ?? $this->bert->detectIntent($mesaj);
        if ($bert && $this->modulDosyasi($bert['niyet']) !== null) {
            $cevap = $this->executeModule($bert['niyet'], $temiz, $mesaj);
            if ($cevap !== null) {
                return $this->etiketle($cevap, 'bert', $bert['niyet'], $bert['guven'], true);
            }
        }

        // ── 3. KATMAN: LLM Sohbet ──
        return $this->llmCevabi($mesaj);
    }

    private function llmCevabi(string $mesaj): MistikCevap {
        $sonuc = $this->llm->chat($mesaj, $this->gecmisAl(), $this->llmModulleri());

        if ($sonuc['durum'] === 'arac') {
            if ($sonuc['arac'] === LLMClient::MENU_ARACI) {
                return $this->etiketle($this->handleFlow('ana_menu'), 'llm', null, self::LLM_GUVEN_ARAC, true);
            }
            $soru = $sonuc['arguman'] !== '' ? $sonuc['arguman'] : $mesaj;
            $cevap = $this->executeModule((string)$sonuc['arac'], Metin::temiz($soru), $soru);
            if ($cevap !== null) {
                return $this->etiketle($cevap, 'llm', $sonuc['arac'], self::LLM_GUVEN_ARAC, true);
            }
        }

        if ($sonuc['durum'] === 'metin') {
            $metin = (string)$sonuc['metin'];
            $bilmiyor = (bool)preg_match(self::BILMIYORUM, Metin::temiz($metin));
            $cevap = new MistikCevap(Bicim::markdownLite($metin));
            $cevap->ozet = mb_substr($metin, 0, 500);
            return $this->etiketle($cevap, 'llm', null, $bilmiyor ? self::LLM_GUVEN_BILMIYOR : self::LLM_GUVEN_METIN, !$bilmiyor);
        }

        // Bütün katmanlar başarısız: dostça mesaj + ana menü butonları
        $cevap = $this->anaMenuyle('Şu an sorunu yanıtlayamıyorum 😕 Aşağıdaki seçeneklerden birini deneyebilir ya da biraz sonra tekrar yazabilirsin.');
        $cevap->hata = true; // sohbet geçmişine yazılmaz
        return $this->etiketle($cevap, 'llm', null, 0, false);
    }

    /** Belirsiz eşleşmede "Bunu mu demek istedin?" + en fazla 3 modül butonu. */
    private function adaylariSor(array $kural): MistikCevap {
        $moduller = $this->router->getModules();
        $butonlar = [];
        foreach (array_slice($kural['candidates'], 0, 3) as $aday) {
            $m = $moduller[$aday['code']] ?? null;
            if (!$m) {
                continue;
            }
            $butonlar[] = ['text' => trim((string)($m['buton_metni'] ?? '')) ?: (string)$m['modul_adi'], 'payload' => 'modul_' . $aday['code']];
        }
        $cevap = $this->response('🤔 Bunu mu demek istedin?', $butonlar);
        // Netleştirme sorusu bir "çözüm" değil; Blackbox'ta anahtar kelime iyileştirmesi için görünsün.
        return $this->etiketle($cevap, 'kural', $kural['module_code'], $kural['score'], false);
    }

    // ------------------------------------------------------------------
    // Akışlar
    // ------------------------------------------------------------------

    /**
     * Diyalog akışı (buton menüleri) yönetimi.
     */
    public function handleFlow(string $menuCode): MistikCevap {
        $menu = false;
        if ($this->db && preg_match('/^[a-z0-9_]{1,50}$/', $menuCode)) {
            $stmt = $this->db->prepare("SELECT * FROM bot_akis_menuleri WHERE menu_kodu = ? AND aktif_mi = 1");
            $stmt->execute([$menuCode]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$menu) {
            return $this->etiketle($this->response('Diyalog akışı bulunamadı.'), 'akis', null, 0, false);
        }

        $btnStmt = $this->db->prepare("SELECT * FROM bot_akis_butonlari WHERE ust_menu_id = ? ORDER BY siralama ASC, id ASC");
        $btnStmt->execute([$menu['id']]);
        $moduller = $this->router->getModules();

        $buttons = [];
        foreach ($btnStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $hedef = trim((string)$r['hedef_veri']);
            $metin = (string)$r['buton_metni'];
            switch ($r['aksiyon_tipi']) {
                case 'alt_menu':
                    if (preg_match('/^[a-z0-9_]{1,50}$/', $hedef)) {
                        $buttons[] = ['text' => $metin, 'payload' => 'flow_' . $hedef];
                    }
                    break;
                case 'modul':
                    if (preg_match(self::MODUL_KODU, $hedef) && isset($moduller[$hedef])) {
                        $buttons[] = ['text' => $metin, 'payload' => 'modul_' . $hedef];
                    }
                    break;
                case 'link':
                    $buttons[] = ['text' => $metin, 'url' => $hedef]; // guvenliHaleGetir yalnız http(s) bırakır
                    break;
            }
        }

        return $this->etiketle($this->response((string)$menu['mesaj'], $buttons), 'akis', null, 100, true);
    }

    /** Ana menü butonlarını verilen mesajla döndürür (hata/yedek cevaplarında kullanılır). */
    private function anaMenuyle(string $mesaj): MistikCevap {
        $butonlar = $this->handleFlow('ana_menu')->butonlar;
        if (!$butonlar) {
            $butonlar = [['text' => '✨ Neler yapabilirsin?', 'payload' => 'flow_ana_menu']];
        }
        return $this->response($mesaj, $butonlar);
    }

    /**
     * Bölüm/sınıf seçim komutunu işle: set_program_<bolum_id>_<sinif> ya da set_program_sifirla.
     */
    private function handleProgramSelection(string $komut, string $mesaj): MistikCevap {
        if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
            $_SESSION['context'] = [];
        }
        if ($komut === 'set_program_sifirla') {
            unset($_SESSION['context']['bolum_id'], $_SESSION['context']['bolum'], $_SESSION['context']['sinif']);
            $girdi = '';
        } elseif (preg_match('/^set_program_(\d{1,6})_(\d{1,2})$/', $komut, $m)) {
            $_SESSION['context']['bolum_id'] = (int)$m[1];
            $_SESSION['context']['sinif'] = (int)$m[2];
            $girdi = 'haftalik';
        } else {
            return $this->etiketle($this->response('Geçersiz seçim.'), 'komut', null, 0, false);
        }

        $cevap = $this->executeModule('ders_programi', $girdi, $mesaj);
        if ($cevap === null) {
            return $this->etiketle($this->response('Ders programı hizmeti şu an kullanılamıyor.'), 'komut', null, 0, false);
        }
        return $this->etiketle($cevap, 'komut', 'ders_programi', 100, true);
    }

    // ------------------------------------------------------------------
    // Modüller
    // ------------------------------------------------------------------

    /** Kod biçimi geçerli ve AKTİF modüller arasında mı? */
    public function modulIzinli(string $kod): bool {
        return (bool)preg_match(self::MODUL_KODU, $kod) && isset($this->router->getModules()[$kod]);
    }

    /** İzinli modülün eklenti dosyası (realpath ile eklenti dizini içinde olduğu doğrulanmış) ya da null. */
    public function modulDosyasi(string $kod): ?string {
        if (!$this->modulIzinli($kod)) {
            return null;
        }
        $kok = realpath($this->pluginDir);
        if ($kok === false) {
            return null;
        }
        $dosya = realpath($kok . DIRECTORY_SEPARATOR . $kod . '.php');
        if ($dosya === false || strpos($dosya, $kok . DIRECTORY_SEPARATOR) !== 0 || !is_file($dosya)) {
            return null;
        }
        return $dosya;
    }

    /**
     * Modül (plugin) dosyasını çalıştır. Yalnız aktif modüller; dosya yoksa modülün sabit cevabı.
     *
     * @param array|null $bekleyen Bu çağrı bir bekleme devamıysa modülün bekle() ile bıraktığı veri
     * @return MistikCevap|null Modül reddedildiyse ya da söyleyecek bir şeyi yoksa null
     */
    public function executeModule(string $moduleKey, string $clean, string $raw, ?array $bekleyen = null): ?MistikCevap {
        if (!$this->modulIzinli($moduleKey)) {
            error_log('[MistikAI] Reddedilen modül kodu: ' . json_encode($moduleKey));
            return null;
        }

        $dosya = $this->modulDosyasi($moduleKey);
        if ($dosya === null) {
            // Plugin dosyası yoksa, veritabanındaki sabit cevabı kullan
            $sabit = trim((string)($this->router->getModules()[$moduleKey]['cevap_metni'] ?? ''));
            return $sabit !== '' ? $this->response($sabit) : null;
        }

        $onceki = $this->aktifModul;
        $this->aktifModul = $moduleKey;
        try {
            $cevap = $this->modulCalistir($dosya, $clean, $raw, $bekleyen);
        } finally {
            $this->aktifModul = $onceki;
        }
        if (!$cevap->hata) {
            $_SESSION['mistik']['son_modul'] = ['kod' => $moduleKey, 'zaman' => time()];
        }
        return $cevap;
    }

    /**
     * Eklentiyi motorun kapsamında çalıştırır. Modülde kullanılabilenler:
     *   $this->db, $this->response($html, $butonlar), $this->bekle($veri),
     *   $clean_input (küçük harf, noktalamasız, ASCII katlanmış: "yarın?" → "yarin"),
     *   $raw_input (kullanıcının yazdığı metin), $bekleyen (bekleme devamıysa veri, değilse null)
     * Modül echo etmemeli; çıktısı yakalanır ve atılır.
     */
    private function modulCalistir(string $__dosya, string $clean_input, string $raw_input, ?array $bekleyen): MistikCevap {
        ob_start();
        try {
            $__sonuc = require $__dosya;
        } catch (Throwable $e) {
            error_log('[MistikAI] Modül hatası (' . $this->aktifModul . '): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $__sonuc = null;
        } finally {
            $__cikti = ob_get_clean();
        }
        if (is_string($__cikti) && trim($__cikti) !== '') {
            error_log('[MistikAI] Modül (' . $this->aktifModul . ') doğrudan çıktı üretti; yok sayıldı.');
        }
        return $this->cevabaCevir($__sonuc);
    }

    /** Modülün döndürdüğü değeri MistikCevap'a çevirir (eski modüllerin JSON metnini de kabul eder). */
    private function cevabaCevir($sonuc): MistikCevap {
        if ($sonuc instanceof MistikCevap) {
            return $sonuc;
        }
        if (is_string($sonuc)) {
            $json = json_decode($sonuc, true);
            $sonuc = (is_array($json) && isset($json['reply'])) ? $json : ['reply' => $sonuc];
        }
        if (is_array($sonuc) && isset($sonuc['reply']) && is_scalar($sonuc['reply'])) {
            return $this->response((string)$sonuc['reply'], is_array($sonuc['buttons'] ?? null) ? $sonuc['buttons'] : []);
        }
        $cevap = $this->response('⚠️ Bu hizmet şu an yanıt veremiyor. Lütfen biraz sonra tekrar dene.');
        $cevap->hata = true;
        return $cevap;
    }

    /** Modüller için: bir sonraki kullanıcı mesajı bu modüle dönsün (çok adımlı akış). */
    public function bekle(array $veri = []): void {
        if ($this->aktifModul !== null) {
            $_SESSION['mistik']['bekleyen'] = ['modul' => $this->aktifModul, 'veri' => $veri, 'zaman' => time()];
        }
    }

    private function bekleyenAl(): ?array {
        $b = $_SESSION['mistik']['bekleyen'] ?? null;
        if (!is_array($b) || !isset($b['modul'], $b['zaman'])) {
            return null;
        }
        if (time() - (int)$b['zaman'] > self::BEKLEME_SURESI || !$this->modulIzinli((string)$b['modul'])) {
            $this->beklemeyiTemizle();
            return null;
        }
        return ['modul' => (string)$b['modul'], 'veri' => is_array($b['veri'] ?? null) ? $b['veri'] : []];
    }

    private function beklemeyiTemizle(): void {
        unset($_SESSION['mistik']['bekleyen']);
    }

    private function sonModulAl(): ?string {
        $s = $_SESSION['mistik']['son_modul'] ?? null;
        if (!is_array($s) || time() - (int)($s['zaman'] ?? 0) > self::SON_MODUL_SURESI) {
            return null;
        }
        $kod = (string)($s['kod'] ?? '');
        return $this->modulDosyasi($kod) !== null ? $kod : null;
    }

    /** Kısa mesajda gün/zaman ifadesi var mı ("peki yarın?", "ya cuma?", "haftaya")? */
    private static function zamanIpucuVar(string $temiz): bool {
        return TarihYardimcisi::goreliGun($temiz) !== null
            || TarihYardimcisi::gunBul($temiz) !== null
            || (bool)preg_match('/\b(haftaya|hafta|haftalik|bu hafta)\b/', $temiz);
    }

    /** LLM'e araç olarak sunulacak modüller: aktif ve eklentisi ya da sabit cevabı olanlar. */
    private function llmModulleri(): array {
        $liste = [];
        foreach ($this->router->getModules() as $kod => $m) {
            if ($this->modulDosyasi((string)$kod) !== null || trim((string)($m['cevap_metni'] ?? '')) !== '') {
                $liste[] = $m;
            }
        }
        return $liste;
    }

    // ------------------------------------------------------------------
    // Sohbet geçmişi
    // ------------------------------------------------------------------

    private function gecmisAl(): array {
        $g = $_SESSION['mistik']['gecmis'] ?? [];
        return is_array($g) ? $g : [];
    }

    private function gecmiseEkle(string $kullanici, MistikCevap $cevap): void {
        if ($cevap->hata) {
            return; // hata mesajları LLM bağlamını kirletmesin
        }
        if ($cevap->ozet !== null) {
            $asistan = $cevap->ozet;
        } else {
            $ad = $cevap->niyet !== null ? (string)($this->router->getModules()[$cevap->niyet]['modul_adi'] ?? $cevap->niyet) : 'Menü';
            $asistan = '[' . $ad . ' cevabı] ' . Metin::duzMetin($cevap->metin, 200);
        }
        $gecmis = $this->gecmisAl();
        $gecmis[] = ['role' => 'user', 'content' => mb_substr($kullanici, 0, 500)];
        $gecmis[] = ['role' => 'assistant', 'content' => $asistan];
        $_SESSION['mistik']['gecmis'] = array_slice($gecmis, -self::GECMIS_MESAJ);
    }

    // ------------------------------------------------------------------
    // Cevap yardımcıları
    // ------------------------------------------------------------------

    /**
     * Cevap nesnesi oluştur (modüller de bunu kullanır: return $this->response($html, $butonlar)).
     */
    public function response(string $text, array $buttons = []): MistikCevap {
        return new MistikCevap($text, $buttons);
    }

    private function etiketle(MistikCevap $cevap, string $katman, ?string $niyet, int $guven, bool $anlasildi): MistikCevap {
        $cevap->katman = $katman;
        $cevap->niyet = $niyet;
        $cevap->guven = max(0, min(100, $guven));
        $cevap->anlasildi = $anlasildi && !$cevap->hata;
        return $cevap;
    }

    /** Son savunma hattı: HTML izinli etiket listesinden geçer, butonlar düz metin + güvenli hedef olur. */
    private function guvenliHaleGetir(MistikCevap $cevap): MistikCevap {
        $cevap->metin = Guvenlik::temizHtml($cevap->metin);
        $cevap->butonlar = self::butonlariTemizle($cevap->butonlar);
        return $cevap;
    }

    public static function butonlariTemizle(array $butonlar): array {
        $temiz = [];
        foreach (array_slice($butonlar, 0, 12) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $metin = Metin::duzMetin((string)($b['text'] ?? ''), 80);
            if ($metin === '') {
                continue;
            }
            $payload = isset($b['payload']) && is_scalar($b['payload']) ? trim((string)$b['payload']) : '';
            $url = isset($b['url']) && is_string($b['url']) ? trim($b['url']) : null;
            if ($url === null && preg_match('~^https?://~i', $payload)) {
                $url = $payload; // eski modüller bağlantıyı payload'a koyuyordu
            }
            if ($url !== null) {
                $sema = strtolower((string)parse_url($url, PHP_URL_SCHEME));
                if (filter_var($url, FILTER_VALIDATE_URL) && in_array($sema, ['http', 'https'], true)) {
                    $temiz[] = ['text' => $metin, 'url' => $url];
                }
                continue;
            }
            $temiz[] = ['text' => $metin, 'payload' => mb_substr($payload !== '' ? $payload : $metin, 0, 200)];
        }
        return $temiz;
    }
}
