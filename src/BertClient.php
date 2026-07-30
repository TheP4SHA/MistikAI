<?php
// src/BertClient.php - BERT Niyet Algılama İstemcisi
// Kendi eğittiğimiz BERT modeline istek atar.
// Rule-Based Router belirsiz kaldığında devreye girer.

class BertClient {
    private string $apiUrl;
    private int $timeout;

    public function __construct(string $apiUrl, int $timeout = 2) {
        $this->apiUrl = $apiUrl;
        $this->timeout = $timeout;
    }

    /**
     * BERT modeline niyet algılama isteği gönder.
     * 
     * @return array|null ['niyet' => string, 'eminlik' => float] veya null (hata/bilinmiyor)
     */
    public function detectIntent(string $userInput): ?array {
        if (empty($this->apiUrl)) {
            return null;
        }

        $data = json_encode(['metin' => $userInput]);
        
        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 1
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200 || !$response) {
            return null;
        }

        $result = json_decode($response, true);
        
        if (!$result || !isset($result['niyet'])) {
            return null;
        }

        // "bilinmiyor" niyeti geçersiz sayılır
        if ($result['niyet'] === 'bilinmiyor') {
            return null;
        }

        return [
            'niyet'   => $result['niyet'],
            'eminlik' => $result['eminlik'] ?? 0.0
        ];
    }
}
