<?php
// src/HizSiniri.php - Dakikalık istek sınırı (bot_api_rate_limit)
// Anahtar ham IP değil Guvenlik::ipOzeti() değeridir. Sabit 60 saniyelik pencere:
// last_request pencerenin başlangıcıdır, pencere dolunca sayaç 1'den başlar.

class HizSiniri {
    private const PENCERE_SN = 60;

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * İsteği sayar.
     * @return array{sayi: int, kalan_sn: int} Bu penceredeki istek sayısı (bu istek dahil) ve pencerenin bitmesine kalan süre
     */
    public function say(string $anahtar): array {
        $pencere = self::PENCERE_SN;
        // ON DUPLICATE KEY UPDATE atamaları soldan sağa işler; last_request'e açıkça değer verildiği için
        // ON UPDATE CURRENT_TIMESTAMP devreye girmez ve pencere başlangıcı korunur.
        $this->db->prepare("
            INSERT INTO bot_api_rate_limit (ip_address, request_count, last_request)
            VALUES (?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                request_count = IF(last_request <= NOW() - INTERVAL {$pencere} SECOND, 1, request_count + 1),
                last_request  = IF(last_request <= NOW() - INTERVAL {$pencere} SECOND, NOW(), last_request)
        ")->execute([$anahtar]);

        $stmt = $this->db->prepare("SELECT request_count, TIMESTAMPDIFF(SECOND, last_request, NOW()) AS gecen FROM bot_api_rate_limit WHERE ip_address = ?");
        $stmt->execute([$anahtar]);
        $satir = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['request_count' => 1, 'gecen' => 0];

        if (random_int(1, 100) === 1) {
            $this->db->exec("DELETE FROM bot_api_rate_limit WHERE last_request < NOW() - INTERVAL 1 HOUR");
        }

        return [
            'sayi'     => (int)$satir['request_count'],
            'kalan_sn' => max(1, $pencere - (int)$satir['gecen']),
        ];
    }
}
