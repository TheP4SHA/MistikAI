<?php
// src/BertClient.php - BERT Niyet Algılama İstemcisi
// Kendi eğittiğimiz BERT modeline istek atar.
// Rule-Based Router eşleşme bulamadığında ya da belirsiz kaldığında devreye girer.
// "kapsam_disi"/"bilinmiyor" etiketi ve düşük güven "niyet yok" sayılır; soru LLM katmanına geçer.

class BertClient {
    /** Bu etiketler niyet değildir. */
    private const NIYETSIZ = ['bilinmiyor', 'kapsam_disi'];

    private string $apiUrl;
    private string $apiKey;
    private float $esik;
    private int $zamanAsimiMs;

    /**
     * @param string $apiUrl       BERT servis adresi (boşsa katman atlanır)
     * @param string $apiKey       Doluysa X-Api-Key başlığıyla gönderilir
     * @param float  $esik         Bu güvenin altındaki tahminler yok sayılır (0-1)
     * @param int    $zamanAsimiMs Toplam istek süresi sınırı
     */
    public function __construct(string $apiUrl, string $apiKey = '', float $esik = 0.65, int $zamanAsimiMs = 1500) {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
        $this->esik = $esik;
        $this->zamanAsimiMs = $zamanAsimiMs;
    }

    /**
     * BERT modeline niyet algılama isteği gönder.
     *
     * @return array|null ['niyet' => string, 'eminlik' => float (0-1), 'guven' => int (0-100)] veya null (hata/niyet yok)
     */
    public function detectIntent(string $userInput): ?array {
        if ($this->apiUrl === '' || trim($userInput) === '') {
            return null;
        }

        $basliklar = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->apiKey !== '') {
            $basliklar[] = 'X-Api-Key: ' . $this->apiKey;
        }

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => json_encode(['metin' => $userInput], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER        => $basliklar,
            CURLOPT_TIMEOUT_MS        => $this->zamanAsimiMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(500, $this->zamanAsimiMs),
            CURLOPT_NOSIGNAL          => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200 || !is_string($response) || $response === '') {
            if ($error || $httpCode >= 400) {
                error_log('[MistikAI] BERT servisi yanıt vermedi: ' . ($error ?: "HTTP {$httpCode}"));
            }
            return null;
        }

        return self::sonucuYorumla(json_decode($response, true), $this->esik);
    }

    /** Servis cevabını yorumlar (testlerde de kullanılır). */
    public static function sonucuYorumla($sonuc, float $esik): ?array {
        if (!is_array($sonuc) || !isset($sonuc['niyet']) || !is_string($sonuc['niyet'])) {
            return null;
        }
        $niyet = $sonuc['niyet'];
        $eminlik = (float)($sonuc['eminlik'] ?? 0.0);
        if (in_array($niyet, self::NIYETSIZ, true) || $eminlik < $esik || !preg_match('/^[a-z0-9_]{2,40}$/', $niyet)) {
            return null;
        }
        return [
            'niyet'   => $niyet,
            'eminlik' => $eminlik,
            'guven'   => (int)max(0, min(100, round($eminlik * 100))),
        ];
    }
}
