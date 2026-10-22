<?php
// src/Logger.php - Derin Etkileşim Kaydedicisi (Deep Logger)
// Her kullanıcı mesajını güven skoru, eşleşen modül, cevaplayan katman, gecikme ve "anlaşıldı mı" bilgisiyle loglar.
// Admin paneldeki Blackbox (Log Analizi) bu verileri kullanır.
// session_id sütununda ham IP değil Guvenlik::ipOzeti() tutulur (KVKK).

require_once __DIR__ . '/Guvenlik.php';
require_once __DIR__ . '/Metin.php';

class Logger {
    private ?PDO $db;
    private array $modules;
    private bool $yeniSutunlarVar = true;

    public function __construct(?PDO $db, array $modules) {
        $this->db = $db;
        $this->modules = $modules;
    }

    /**
     * Etkileşimi veritabanına kaydet.
     *
     * @param string      $sessionId       Kullanıcı kimliği (IP özeti)
     * @param string      $message         Kullanıcının yazdığı mesaj
     * @param string|null $intentCode      Algılanan niyet kodu (modül kodu)
     * @param int         $confidenceScore Güven skoru (0-100'e kırpılır)
     * @param string|null $botReply        Botun verdiği cevap (HTML olabilir; düz metin olarak saklanır)
     * @param bool|null   $anlasildi       null ise eski kural: bilinen modül/sanal niyet → anlaşıldı
     * @param string|null $katman          kural | bert | llm | akis | komut | sinir
     * @param int|null    $gecikmeMs       İsteğin işlenme süresi
     */
    public function log(string $sessionId, string $message, ?string $intentCode, int $confidenceScore, ?string $botReply = null,
                        ?bool $anlasildi = null, ?string $katman = null, ?int $gecikmeMs = null): void {
        if (!$this->db) {
            return;
        }
        $modulId = null;
        if ($intentCode !== null && isset($this->modules[$intentCode]['id'])) {
            $modulId = (int)$this->modules[$intentCode]['id'];
        } elseif ($intentCode !== null) {
            foreach ($this->modules as $m) {
                if (($m['modul_kodu'] ?? null) === $intentCode) {
                    $modulId = (int)$m['id'];
                    break;
                }
            }
        }

        if ($anlasildi === null) {
            $virtualIntents = ['selamlama', 'argo_filtresi', 'yetenekler'];
            $anlasildi = $modulId !== null || in_array($intentCode, $virtualIntents, true);
        }

        $degerler = [
            mb_substr($sessionId, 0, 100),
            mb_substr($message, 0, 2000),
            $botReply !== null ? Metin::duzMetin($botReply, 4000) : null,
            $anlasildi ? 1 : 0,
            $modulId,
            $intentCode !== null ? mb_substr($intentCode, 0, 50) : null,
            max(0, min(100, $confidenceScore)),
        ];

        try {
            if ($this->yeniSutunlarVar) {
                try {
                    $this->db->prepare("
                        INSERT INTO bot_sohbet_loglari
                        (session_id, kullanici_mesaji, bot_cevabi, anlasildi_mi, eslesen_modul_id, algilanan_niyet, guven_skoru, katman, gecikme_ms)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute(array_merge($degerler, [$katman, $gecikmeMs]));
                    return;
                } catch (PDOException $e) {
                    // Şema henüz güncellenmediyse (katman/gecikme_ms yok) eski sütunlarla devam et.
                    if ($e->getCode() !== '42S22') {
                        throw $e;
                    }
                    $this->yeniSutunlarVar = false;
                    error_log('[MistikAI] bot_sohbet_loglari tablosunda katman/gecikme_ms sütunları yok; şemayı güncelleyin.');
                }
            }
            $this->db->prepare("
                INSERT INTO bot_sohbet_loglari
                (session_id, kullanici_mesaji, bot_cevabi, anlasildi_mi, eslesen_modul_id, algilanan_niyet, guven_skoru)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute($degerler);
        } catch (PDOException $e) {
            // Log yazılamaması sohbeti durdurmamalı.
            error_log('[MistikAI] Log yazılamadı: ' . $e->getMessage());
        }
    }

    /** $gun günden eski logları siler (her çağrıda en fazla 5000 satır). Silinen satır sayısını döndürür. */
    public function eskiKayitlariSil(int $gun): int {
        if (!$this->db || $gun <= 0) {
            return 0;
        }
        try {
            $stmt = $this->db->prepare("DELETE FROM bot_sohbet_loglari WHERE islem_tarihi < (NOW() - INTERVAL ? DAY) LIMIT 5000");
            $stmt->execute([$gun]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('[MistikAI] Eski loglar silinemedi: ' . $e->getMessage());
            return 0;
        }
    }

    /** Ucuz saklama politikası: isteklerin yaklaşık 1/$olasilik'inde eski logları temizler. */
    public function belkiTemizle(int $gun, int $olasilik = 100): void {
        if ($gun > 0 && random_int(1, max(1, $olasilik)) === 1) {
            $this->eskiKayitlariSil($gun);
        }
    }

    /**
     * Kullanıcının IP adresi. Geriye dönük uyumluluk için duruyor; vekil başlıklarına
     * yalnız güvenilir vekillerden geliyorsa bakan Guvenlik::istemciIp() kullanılır.
     */
    public static function getUserIP(): string {
        return Guvenlik::istemciIp();
    }
}
