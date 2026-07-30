<?php
// src/MistikEngine.php - MistikAI Ana Motor (Hibrit AI Orkestratörü)
// 3 katmanlı mimariyi yönetir: Rule-Based → BERT → LLM
// Her katman sırasıyla denenir, ilk başarılı sonuç kullanılır.

require_once __DIR__ . '/RuleBasedRouter.php';
require_once __DIR__ . '/BertClient.php';
require_once __DIR__ . '/LLMClient.php';
require_once __DIR__ . '/Logger.php';

class MistikEngine {
    private PDO $db;
    private RuleBasedRouter $router;
    private BertClient $bert;
    private LLMClient $llm;
    private Logger $logger;
    private string $pluginDir;
    private string $sessionId;

    public function __construct(PDO $db, array $config) {
        $this->db = $db;
        $this->pluginDir = $config['plugin_dir'] ?? __DIR__ . '/../modules/';
        $this->sessionId = Logger::getUserIP();

        // Alt bileşenleri başlat
        $this->router = new RuleBasedRouter($db);
        $this->bert   = new BertClient($config['bert_api_url'] ?? '');
        $this->llm    = new LLMClient(
            $config['groq_api_key'] ?? '',
            $config['llm_model'] ?? 'llama-3.1-8b-instant'
        );
        $this->logger = new Logger($db, $this->router->getModules());
    }

    /**
     * Kullanıcı mesajını işle ve cevap üret.
     */
    public function process(string $userInput, string $rawInput): string {
        // Boş mesaj kontrolü
        if (empty(trim($userInput))) {
            return $this->response('Seni dinliyorum...');
        }

        // ── 0. Özel Komutlar ──
        // Flow (diyalog akışı) komutu
        if (strpos($userInput, 'flow_') === 0) {
            return $this->handleFlow(str_replace('flow_', '', $userInput));
        }

        // Bölüm/Sınıf seçim komutu
        if (strpos($userInput, 'set_program_') === 0) {
            return $this->handleProgramSelection($userInput, $rawInput);
        }

        // ── 1. KATMAN: Rule-Based Router ──
        $ruleResult = $this->router->analyze($userInput);

        if ($ruleResult['status'] === 'matched') {
            // Doğrudan cevap varsa (cevap_override) onu kullan
            if ($ruleResult['override']) {
                $response = $this->response($ruleResult['override']);
                $this->logger->log($this->sessionId, $userInput, $ruleResult['module_code'], $ruleResult['score'], $ruleResult['override']);
                return $response;
            }

            // Modülü çalıştır
            $response = $this->executeModule($ruleResult['module_code'], $userInput, $rawInput);
            $this->logger->log($this->sessionId, $userInput, $ruleResult['module_code'], $ruleResult['score'], json_decode($response)->reply ?? null);
            return $response;
        }

        // ── 2. KATMAN: BERT Niyet Algılama ──
        // Rule-Based belirsiz kaldıysa veya eşleşme bulamadıysa
        $bertResult = $this->bert->detectIntent($userInput);

        if ($bertResult) {
            $fastIntent = $bertResult['niyet'];
            $confidence = (int)($bertResult['eminlik'] * 100);

            // Teknik modüller BERT sonucuyla doğrudan çalıştırılır
            $technicalModules = ['ders_programi', 'yemekhane', 'akademik_takvim', 'kampus_rehberi', 'kampus_astroloji', 'telefon_rehberi'];

            if (in_array($fastIntent, $technicalModules)) {
                $response = $this->executeModule($fastIntent, $userInput, $rawInput);
                $this->logger->log($this->sessionId, $userInput, $fastIntent, $confidence, json_decode($response)->reply ?? null);
                return $response;
            }

            // Sanal niyetler (selamlama, argo) için de logla
            // Ama cevabı LLM'den al (daha doğal olsun diye)
        }

        // ── 3. KATMAN: LLM Sohbet ──
        $modules = $this->router->getModules();
        $modulesArray = array_values($modules);
        $chatHistory = $_SESSION['chat_history'] ?? [];

        $llmResult = $this->llm->chat($userInput, $chatHistory, $modulesArray);

        // LLM bir modül komutu döndüyse çalıştır
        if ($llmResult['command_type'] === 'flow') {
            return $this->handleFlow($llmResult['command']);
        }
        if ($llmResult['command_type'] === 'module') {
            $response = $this->executeModule($llmResult['command'], $userInput, $rawInput);
            $intentCode = $bertResult['niyet'] ?? $llmResult['command'];
            $confidence = isset($bertResult['eminlik']) ? (int)($bertResult['eminlik'] * 100) : 0;
            $this->logger->log($this->sessionId, $userInput, $intentCode, $confidence, json_decode($response)->reply ?? null);
            return $response;
        }

        // Doğal sohbet cevabı
        $_SESSION['chat_history'][] = ['role' => 'user', 'content' => $userInput];
        $_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $llmResult['reply']];
        // Geçmiş boyutunu sınırla (son 6 mesaj)
        if (count($_SESSION['chat_history']) > 6) {
            array_shift($_SESSION['chat_history']);
            array_shift($_SESSION['chat_history']);
        }

        $intentCode = $bertResult['niyet'] ?? null;
        $confidence = isset($bertResult['eminlik']) ? (int)($bertResult['eminlik'] * 100) : 0;
        $this->logger->log($this->sessionId, $userInput, $intentCode, $confidence, $llmResult['reply']);

        return $this->response($llmResult['reply']);
    }

    /**
     * Diyalog akışı (buton menüleri) yönetimi.
     */
    public function handleFlow(string $menuCode): string {
        $stmt = $this->db->prepare("SELECT * FROM bot_akis_menuleri WHERE menu_kodu = ?");
        $stmt->execute([$menuCode]);
        $menu = $stmt->fetch();

        if (!$menu) {
            return $this->response('Diyalog akışı bulunamadı.');
        }

        $btnStmt = $this->db->prepare("SELECT * FROM bot_akis_butonlari WHERE ust_menu_id = ? ORDER BY siralama ASC");
        $btnStmt->execute([$menu['id']]);
        
        $buttons = [];
        foreach ($btnStmt->fetchAll() as $r) {
            $payload = ($r['aksiyon_tipi'] === 'alt_menu') ? 'flow_' . $r['hedef_veri'] : $r['hedef_veri'];
            $buttons[] = ['text' => $r['buton_metni'], 'payload' => $payload];
        }

        return $this->response($menu['mesaj'], $buttons);
    }

    /**
     * Bölüm/sınıf seçim komutunu işle.
     */
    private function handleProgramSelection(string $input, string $rawInput): string {
        $parts = explode('_', $input);
        if (!isset($_SESSION['context'])) $_SESSION['context'] = [];
        $_SESSION['context']['bolum_id'] = $parts[2] ?? null;
        $_SESSION['context']['sinif'] = $parts[3] ?? null;

        $response = $this->executeModule('ders_programi', 'haftalik', $rawInput);
        $this->logger->log($this->sessionId, 'Bölüm Ayarlandı: ' . ($parts[2] ?? ''), 'ders_programi', 100, json_decode($response)->reply ?? null);
        return $response;
    }

    /**
     * Modül (plugin) dosyasını çalıştır.
     */
    private function executeModule(string $moduleKey, string $clean, string $raw): string {
        $plugin = $this->pluginDir . $moduleKey . '.php';

        if (file_exists($plugin)) {
            $clean_input = $clean;
            $raw_input = $raw;
            return require $plugin;
        }

        // Plugin dosyası yoksa, veritabanındaki sabit cevabı kullan
        $stmt = $this->db->prepare("SELECT cevap_metni FROM bot_moduller WHERE modul_kodu = ?");
        $stmt->execute([$moduleKey]);
        $m = $stmt->fetch();

        return $this->response($m['cevap_metni'] ?? 'Anlayamadım, lütfen tekrar dener misin?');
    }

    /**
     * Standart JSON response oluştur.
     */
    public function response(string $text, array $buttons = []): string {
        return json_encode(['reply' => $text, 'buttons' => $buttons], JSON_UNESCAPED_UNICODE);
    }
}
