<?php
// src/RuleBasedRouter.php - Kural Tabanlı Niyet Motoru
// Anahtar kelime + öncelik skoru ile kullanıcı niyetini belirler.
// Eş anlamlılar çok fazla çakışma yaratırsa BERT'e yönlendirir.

class RuleBasedRouter {
    private $db;
    private $keywords = [];     // Önbelleklenmiş anahtar kelimeler
    private $modules = [];      // Aktif modüller
    private $ambiguityThreshold = 15; // Skor farkı bu değerin altındaysa "belirsiz"

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->loadKeywords();
        $this->loadModules();
    }

    private function loadKeywords() {
        $stmt = $this->db->query("
            SELECT k.kelime, k.modul_id, k.oncelik, k.cevap_override, k.zaman_kontrol,
                   m.modul_kodu, m.modul_adi
            FROM bot_anahtar_kelimeler k
            JOIN bot_moduller m ON k.modul_id = m.id
            WHERE k.aktif_mi = 1 AND m.aktif_mi = 1
            ORDER BY k.oncelik DESC
        ");
        $this->keywords = $stmt->fetchAll();
    }

    private function loadModules() {
        $stmt = $this->db->query("SELECT * FROM bot_moduller WHERE aktif_mi = 1");
        foreach ($stmt->fetchAll() as $m) {
            $this->modules[$m['modul_kodu']] = $m;
        }
    }

    /**
     * Kullanıcı mesajını analiz et ve niyet belirle.
     * 
     * @return array ['status' => 'matched'|'ambiguous'|'no_match', 'module_code' => string|null, 'score' => int, 'override' => string|null]
     */
    public function analyze(string $userInput): array {
        $normalized = $this->normalize($userInput);
        $scores = []; // modul_kodu => ['score' => int, 'matches' => int, 'override' => string|null]

        foreach ($this->keywords as $kw) {
            $kelime = mb_strtolower($kw['kelime'], 'UTF-8');
            
            // Tam eşleşme kontrolü (kullanıcı mesajı kelimeyi içeriyor mu?)
            if (mb_strpos($normalized, $kelime) !== false) {
                $modulKodu = $kw['modul_kodu'];
                $oncelik = (int)$kw['oncelik'];
                
                // Zaman kontrolü (sabah/akşam gibi bağlam)
                if ($kw['zaman_kontrol'] && !$this->checkTimeContext($kw['zaman_kontrol'])) {
                    $oncelik = max(1, $oncelik - 20); // Zaman uyumsuzsa skoru düşür
                }

                if (!isset($scores[$modulKodu])) {
                    $scores[$modulKodu] = ['score' => 0, 'matches' => 0, 'override' => null, 'module' => $kw];
                }
                
                // Skor hesapla: öncelik + kelime uzunluğu bonusu (daha spesifik = daha iyi)
                $lengthBonus = min(20, mb_strlen($kelime) * 2);
                $scores[$modulKodu]['score'] = max($scores[$modulKodu]['score'], $oncelik + $lengthBonus);
                $scores[$modulKodu]['matches']++;
                
                // cevap_override varsa kaydet (doğrudan cevap)
                if ($kw['cevap_override']) {
                    $scores[$modulKodu]['override'] = $kw['cevap_override'];
                }
            }
        }

        if (empty($scores)) {
            return ['status' => 'no_match', 'module_code' => null, 'score' => 0, 'override' => null];
        }

        // Skorlara göre sırala
        uasort($scores, fn($a, $b) => $b['score'] - $a['score']);
        $sorted = array_values($scores);
        $best = $sorted[0];
        $bestCode = array_keys($scores)[0];

        // Belirsizlik kontrolü: İlk 2 sonuç arasındaki fark çok azsa → BERT'e yönlendir
        if (count($sorted) >= 2) {
            $diff = $best['score'] - $sorted[1]['score'];
            if ($diff < $this->ambiguityThreshold) {
                return [
                    'status' => 'ambiguous',
                    'module_code' => $bestCode,
                    'score' => $best['score'],
                    'override' => null,
                    'candidates' => array_slice(array_keys($scores), 0, 3)
                ];
            }
        }

        return [
            'status' => 'matched',
            'module_code' => $bestCode,
            'score' => $best['score'],
            'override' => $best['override']
        ];
    }

    private function normalize(string $text): string {
        $text = mb_strtolower(trim($text), 'UTF-8');
        // Türkçe karakter normalizasyonu
        $text = str_replace(
            ['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'İ', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç'],
            ['ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'i', 'ğ', 'ü', 'ş', 'ö', 'ç'],
            $text
        );
        return $text;
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

    public function getModules(): array {
        return $this->modules;
    }
}
