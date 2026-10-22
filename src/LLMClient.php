<?php
// src/LLMClient.php - LLM Sohbet İstemcisi (Groq / OpenAI uyumlu API)
// Rule-Based ve BERT sonuç vermediğinde doğal sohbet için kullanılır.
// Modül yönlendirmesi OpenAI uyumlu "tool calling" ile yapılır: araçlar AKTİF modüllerden üretilir,
// dönen araç adı bu izin listesine karşı doğrulanır.
// 429/5xx'te kısa bekleyip bir kez yeniden dener, sonra yedek modele geçer.

require_once __DIR__ . '/TarihYardimcisi.php';

class LLMClient {
    public const VARSAYILAN_URL = 'https://api.groq.com/openai/v1/chat/completions';

    /** $OKUL_BILGISI tanımlı değilse ya da boşsa kullanılan okul adı. */
    public const VARSAYILAN_OKUL = 'Örnek Meslek Yüksekokulu';

    /** Ana menüyü (yetenek listesini) göstermek için modüllerden bağımsız araç. */
    public const MENU_ARACI = 'ana_menuyu_goster';

    private string $apiKey;
    private string $model;
    private string $yedekModel;
    private int $maxTokens;
    private string $apiUrl;
    private int $zamanAsimi;
    private float $toplamSure;
    private string $okulBilgisi;

    /** Son çağrının teşhis bilgisi (hangi model, kaç deneme, son HTTP kodu). */
    public array $sonDeneme = [];

    /**
     * @param array $ayar model, yedek_model, max_tokens, api_url, timeout (sn), toplam_sure (sn), okul_bilgisi
     */
    public function __construct(string $apiKey, array $ayar = []) {
        $this->apiKey      = $apiKey;
        $this->model       = (string)($ayar['model'] ?? 'llama-3.3-70b-versatile');
        $this->yedekModel  = (string)($ayar['yedek_model'] ?? 'llama-3.1-8b-instant');
        $this->maxTokens   = max(32, (int)($ayar['max_tokens'] ?? 400));
        $this->apiUrl      = (string)($ayar['api_url'] ?? self::VARSAYILAN_URL) ?: self::VARSAYILAN_URL;
        $this->zamanAsimi  = max(2, (int)($ayar['timeout'] ?? 8));
        $this->toplamSure  = (float)($ayar['toplam_sure'] ?? 20);
        $this->okulBilgisi = self::okulAdi($ayar['okul_bilgisi'] ?? null);
    }

    /** Yapılandırmadaki okul adı ($OKUL_BILGISI); boşsa VARSAYILAN_OKUL. Sohbet sayfası da bunu kullanır. */
    public static function okulAdi($deger): string {
        $ad = is_scalar($deger) ? trim((string)$deger) : '';
        return $ad !== '' ? $ad : self::VARSAYILAN_OKUL;
    }

    /**
     * LLM'e sohbet isteği gönder.
     *
     * @param string $userInput   Kullanıcı mesajı
     * @param array  $chatHistory Son mesajlar: [['role' => 'user'|'assistant', 'content' => string], ...]
     * @param array  $modules     Araç olarak sunulacak aktif modül satırları (modul_kodu, modul_adi, ...)
     * @return array ['durum' => 'metin'|'arac'|'hata', 'metin' => ?string, 'arac' => ?string, 'arguman' => string, 'model' => ?string]
     */
    public function chat(string $userInput, array $chatHistory, array $modules): array {
        $this->sonDeneme = ['denemeler' => []];
        if ($this->apiKey === '') {
            return self::hata('api anahtarı yok');
        }

        [$araclar, $izinli] = self::araclariHazirla($modules);

        $messages = [['role' => 'system', 'content' => self::sistemMesaji($this->okulBilgisi)]];
        foreach ($chatHistory as $h) {
            if (isset($h['role'], $h['content']) && in_array($h['role'], ['user', 'assistant'], true) && is_string($h['content'])) {
                $messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userInput];

        $govde = [
            'messages'    => $messages,
            'temperature' => 0.3,
            'max_tokens'  => $this->maxTokens,
        ];
        if ($araclar) {
            $govde['tools'] = $araclar;
            $govde['tool_choice'] = 'auto';
        }

        $bitis = microtime(true) + $this->toplamSure;
        $modeller = array_values(array_unique(array_filter([$this->model, $this->yedekModel])));

        foreach ($modeller as $model) {
            for ($deneme = 1; $deneme <= 2; $deneme++) {
                if (microtime(true) >= $bitis) {
                    break 2;
                }
                $r = $this->istek(array_merge(['model' => $model], $govde));
                $this->sonDeneme['denemeler'][] = ['model' => $model, 'kod' => $r['kod'], 'hata' => $r['hata']];

                if ($r['kod'] === 200 && is_array($r['json'])) {
                    $sonuc = self::yanitCoz($r['json'], $izinli);
                    if ($sonuc['durum'] !== 'hata') {
                        $sonuc['model'] = $model;
                        return $sonuc;
                    }
                    break; // Bozuk/boş cevap: yedek modele geç
                }

                // Groq, modelin bozuk araç çağrısını 400 "tool_use_failed" ile döndürür; üretilen metni kurtarmayı dene.
                if ($r['kod'] === 400 && is_array($r['json'])) {
                    $kurtarilan = self::basarisizUretimCoz($r['json'], $izinli);
                    if ($kurtarilan) {
                        $kurtarilan['model'] = $model;
                        return $kurtarilan;
                    }
                }

                $tekrarEdilebilir = $r['kod'] === 429 || $r['kod'] >= 500;
                if ($deneme === 1 && $tekrarEdilebilir) {
                    $bekle = $r['tekrar_sn'] !== null ? min(2.0, max(0.2, $r['tekrar_sn'])) : 0.5;
                    if (microtime(true) + $bekle < $bitis) {
                        usleep((int)($bekle * 1e6));
                        continue;
                    }
                }
                break; // Zaman aşımı, 4xx ya da ikinci başarısızlık: sıradaki modele geç
            }
        }

        error_log('[MistikAI] LLM cevap vermedi: ' . json_encode($this->sonDeneme['denemeler'], JSON_UNESCAPED_UNICODE));
        return self::hata('tüm denemeler başarısız');
    }

    /** Tek HTTP isteği. */
    private function istek(array $govde): array {
        $tekrarSn = null;
        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($govde, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $this->zamanAsimi,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, string $satir) use (&$tekrarSn) {
                if (stripos($satir, 'retry-after:') === 0) {
                    $deger = trim(substr($satir, 12));
                    if (is_numeric($deger)) {
                        $tekrarSn = (float)$deger;
                    }
                }
                return strlen($satir);
            },
        ]);
        $yanit = curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hata = curl_error($ch);
        curl_close($ch);

        return [
            'kod'       => $hata ? 0 : $kod,
            'json'      => is_string($yanit) ? json_decode($yanit, true) : null,
            'hata'      => $hata ?: null,
            'tekrar_sn' => $tekrarSn,
        ];
    }

    /**
     * Aktif modüllerden araç tanımları ve izin listesi üretir.
     * @return array{0: array, 1: string[]}
     */
    public static function araclariHazirla(array $modules): array {
        $araclar = [];
        $izinli = [];
        foreach ($modules as $m) {
            $kod = (string)($m['modul_kodu'] ?? '');
            if (!preg_match('/^[a-z0-9_]{2,40}$/', $kod) || $kod === self::MENU_ARACI) {
                continue;
            }
            $aciklama = trim((string)($m['aciklama'] ?? ''));
            if ($aciklama === '') {
                $aciklama = trim((string)($m['modul_adi'] ?? $kod));
                if (!empty($m['buton_metni'])) {
                    $aciklama .= ' — ' . trim((string)$m['buton_metni']);
                }
            }
            $araclar[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $kod,
                    'description' => mb_substr($aciklama, 0, 300),
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'soru' => [
                                'type'        => 'string',
                                'description' => 'Kullanıcının isteğinin bu hizmete iletilecek kısa hali (örn. "yarın", "salı", "Ahmet Yılmaz").',
                            ],
                        ],
                    ],
                ],
            ];
            $izinli[] = $kod;
        }
        $araclar[] = [
            'type'     => 'function',
            'function' => [
                'name'        => self::MENU_ARACI,
                'description' => 'Kullanıcı neler yapabildiğini, hangi konularda yardım edebildiğini sorarsa ana menüyü gösterir.',
                'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
            ],
        ];
        $izinli[] = self::MENU_ARACI;
        return [$araclar, $izinli];
    }

    /**
     * Chat-completions cevabını çözer. Araç adı izin listesinde değilse yok sayılır.
     * @return array ['durum' => 'metin'|'arac'|'hata', 'metin' => ?string, 'arac' => ?string, 'arguman' => string, 'model' => ?string]
     */
    public static function yanitCoz(array $json, array $izinliAraclar): array {
        $mesaj = $json['choices'][0]['message'] ?? null;
        if (!is_array($mesaj)) {
            return self::hata('choices yok');
        }

        foreach ((array)($mesaj['tool_calls'] ?? []) as $cagri) {
            $ad = $cagri['function']['name'] ?? null;
            if (!is_string($ad) || !in_array($ad, $izinliAraclar, true)) {
                error_log('[MistikAI] LLM izinsiz araç istedi: ' . json_encode($ad));
                continue;
            }
            return self::arac($ad, $cagri['function']['arguments'] ?? null);
        }

        $icerik = trim(preg_replace('~<think>.*?</think>~s', '', (string)($mesaj['content'] ?? '')) ?? '');

        // Bazı modeller aracı metin olarak yazar: <function=yemekhane>{"soru": "yarın"}</function>
        if (preg_match('~<function=([a-z0-9_]+)>?\s*(\{.*?\})?~s', $icerik, $m)) {
            if (in_array($m[1], $izinliAraclar, true)) {
                return self::arac($m[1], $m[2] ?? null);
            }
            $icerik = trim(preg_replace('~<function=.*?(</function>|$)~s', '', $icerik) ?? '');
        }

        if ($icerik === '') {
            return self::hata('boş cevap');
        }
        return ['durum' => 'metin', 'metin' => $icerik, 'arac' => null, 'arguman' => '', 'model' => null];
    }

    /** Groq 400 tool_use_failed gövdesindeki failed_generation metninden aracı kurtarır. */
    public static function basarisizUretimCoz(array $json, array $izinliAraclar): ?array {
        $uretim = $json['error']['failed_generation'] ?? null;
        if (!is_string($uretim) || $uretim === '') {
            return null;
        }
        $sonuc = self::yanitCoz(['choices' => [['message' => ['content' => $uretim]]]], $izinliAraclar);
        return $sonuc['durum'] === 'arac' ? $sonuc : null;
    }

    private static function arac(string $ad, $argumanlar): array {
        $arg = is_string($argumanlar) ? json_decode($argumanlar, true) : $argumanlar;
        $soru = (is_array($arg) && isset($arg['soru']) && is_scalar($arg['soru'])) ? trim((string)$arg['soru']) : '';
        return ['durum' => 'arac', 'metin' => null, 'arac' => $ad, 'arguman' => mb_substr($soru, 0, 200), 'model' => null];
    }

    private static function hata(string $neden): array {
        return ['durum' => 'hata', 'metin' => null, 'arac' => null, 'arguman' => '', 'model' => null, 'neden' => $neden];
    }

    /** Sistem mesajı: bugünün tarihi (Europe/Istanbul), okul bilgisi, kısa persona ve kurallar. */
    public static function sistemMesaji(string $okulBilgisi, ?DateTimeInterface $simdi = null): string {
        $simdi = $simdi ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
        $okulBilgisi = self::okulAdi($okulBilgisi);
        $tarih = TarihYardimcisi::uzunTarih($simdi) . ', saat ' . $simdi->format('H:i');

        return <<<METIN
Sen MistikAI'sin: {$okulBilgisi} öğrencilerine yardım eden dijital kampüs asistanı. Samimi, sakin ve yardımseversin.
Bugün: {$tarih} (Türkiye saati).

Kurallar:
1. Yalnızca okul ve kampüs yaşamıyla ilgili konularda yardım et (dersler, sınavlar, akademik takvim, yemekhane, kampüs birimleri, öğrenci işleri, kayıt, burs, kulüpler). Başka konularda kibarca kampüs asistanı olduğunu söyle ve kampüsle ilgili bir konuda yardım teklif et.
2. Ders programı, yemek menüsü, akademik takvim, rehber gibi bir hizmet istenirse ilgili aracı çağır; bu bilgileri kendin üretme.
3. Emin olmadığın bir bilgi sorulursa uydurma: "Bunu bilmiyorum" de ve ilgili birime (Öğrenci İşleri, bölüm sekreterliği, ilgili ofis) yönlendir.
4. Asla tarih, saat, telefon numarası, e-posta, oda/derslik numarası ya da kişi adı uydurma.
5. Kısa cevap ver: en fazla 3-4 cümle ya da kısa bir madde listesi.
6. Her zaman Türkçe yanıt ver.
7. Selamlaşmaya kısa ve sıcak karşılık ver. Hakaret edilirse nazikçe saygı çerçevesinde kalmayı hatırlat.
8. Düz metin yaz; HTML kullanma. Gerekirse **kalın** ve "- " ile başlayan maddeler kullanabilirsin.
METIN;
    }
}
