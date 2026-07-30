<?php
// src/Logger.php - Derin Etkileşim Kaydedicisi (Deep Logger)
// Her kullanıcı mesajını güven skoru, eşleşen modül ve "anlaşıldı mı" bilgisiyle loglar.
// Admin paneldeki Blackbox (Log Analizi) bu verileri kullanır.

class Logger {
    private $db;
    private array $modules;

    public function __construct(PDO $db, array $modules) {
        $this->db = $db;
        $this->modules = $modules;
    }

    /**
     * Etkileşimi veritabanına kaydet.
     * 
     * @param string $sessionId  Kullanıcı oturum/IP kimliği
     * @param string $message    Kullanıcının yazdığı mesaj
     * @param string|null $intentCode  Algılanan niyet kodu (modül kodu)
     * @param int $confidenceScore     Güven skoru (0-100)
     * @param string|null $botReply    Botun verdiği cevap
     */
    public function log(string $sessionId, string $message, ?string $intentCode, int $confidenceScore, ?string $botReply = null): void {
        $modulId = null;
        
        // Niyet kodundan modül ID'sini bul
        if ($intentCode) {
            foreach ($this->modules as $m) {
                if ($m['modul_kodu'] === $intentCode) {
                    $modulId = $m['id'];
                    break;
                }
            }
        }

        // "Sanal niyetler" (modül dosyası olmayan ama bilinen niyetler)
        $virtualIntents = ['selamlama', 'argo_filtresi', 'yetenekler'];
        $understood = ($modulId || in_array($intentCode, $virtualIntents)) ? 1 : 0;

        // HTML etiketlerini temizle
        $cleanReply = $botReply ? strip_tags($botReply) : null;

        $stmt = $this->db->prepare("
            INSERT INTO bot_sohbet_loglari 
            (session_id, kullanici_mesaji, bot_cevabi, anlasildi_mi, eslesen_modul_id, algilanan_niyet, guven_skoru) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sessionId,
            $message,
            $cleanReply,
            $understood,
            $modulId,
            $intentCode,
            $confidenceScore
        ]);
    }

    /**
     * Kullanıcının gerçek IP adresini bul (proxy arkasında bile).
     */
    public static function getUserIP(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
