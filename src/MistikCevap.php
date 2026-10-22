<?php
// src/MistikCevap.php - Motorun iç cevap nesnesi
// Motor ve modüller bu nesneyi döndürür; JSON'a yalnız public/api.php çevirir.

class MistikCevap {
    /** @var array<int, array{text: string, payload?: string, url?: string}> */
    public array $butonlar = [];

    /** Hangi katman cevapladı: kural | bert | llm | akis | komut | sinir */
    public ?string $katman = null;

    /** Algılanan niyet (modül kodu) */
    public ?string $niyet = null;

    /** 0-100 güven skoru */
    public int $guven = 0;

    /** Cevap kullanıcının isteğini karşıladı mı (Blackbox "kör nokta" analizi için) */
    public bool $anlasildi = false;

    public ?int $gecikmeMs = null;

    /** Modül/çalıştırma hatası (motor bu durumda anlasildi=0 loglar) */
    public bool $hata = false;

    /** LLM sohbet geçmişine yazılacak kısa metin (boşsa motor HTML'den özet çıkarır) */
    public ?string $ozet = null;

    /**
     * @param string $metin    Güvenli HTML (motor, modül/akış HTML'ini temizHtml'den geçirir)
     * @param array  $butonlar [['text' => ..., 'payload' => ...] | ['text' => ..., 'url' => 'https://...']]
     */
    public function __construct(public string $metin, array $butonlar = []) {
        $this->butonlar = $butonlar;
    }

    /** İstemciye giden JSON gövdesi: {"reply": "<html>", "buttons": [...]} */
    public function disaAktar(): array {
        return ['reply' => $this->metin, 'buttons' => array_values($this->butonlar)];
    }
}
