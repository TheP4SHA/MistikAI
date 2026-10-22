<?php
// src/Metin.php - Türkçe metin yardımcıları
// Türkçeye duyarlı küçük harf (İ→i, I→ı), noktalama temizliği ve ASCII katlama (ç→c, ğ→g, ı→i, ö→o, ş→s, ü→u).
// Kural motoru, modüller ve tarih yardımcısı aynı normalleştirmeyi kullanır.

final class Metin {

    private const KATLAMA = [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        'â' => 'a', 'î' => 'i', 'û' => 'u',
    ];

    /** Türkçe kurallarıyla küçük harf: "İLETİŞİM" → "iletişim", "ILIK" → "ılık". */
    public static function kucukHarf(string $metin): string {
        $metin = str_replace(['İ', 'I'], ['i', 'ı'], $metin);
        $metin = mb_strtolower($metin, 'UTF-8');
        // Bazı girişlerde "i" + birleşik nokta (U+0307) kalır; at.
        return str_replace("\u{0307}", '', $metin);
    }

    /** Türkçe kurallarıyla kelime başları büyük: "İNŞAAT BÖLÜMÜ" → "İnşaat Bölümü" (mb_convert_case "Inşaat" yapar). */
    public static function baslik(string $metin): string {
        return preg_replace_callback('/(^|[\s\-\/(])(\p{L})/u', function (array $m): string {
            $harf = ['i' => 'İ', 'ı' => 'I'][$m[2]] ?? mb_strtoupper($m[2], 'UTF-8');
            return $m[1] . $harf;
        }, self::kucukHarf($metin)) ?? $metin;
    }

    /** Küçük harf + noktalama/simge temizliği + tek boşluk. Türkçe harfler korunur. */
    public static function normalize(string $metin): string {
        $metin = self::kucukHarf($metin);
        $metin = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $metin) ?? '';
        return trim($metin);
    }

    /** Türkçe harfleri ASCII karşılığına indirir (girdi önceden küçük harfe çevrilmiş olmalı). */
    public static function katla(string $metin): string {
        return strtr($metin, self::KATLAMA);
    }

    /** normalize + katla: modüllere giden $clean_input bu biçimdedir ("Yarın?" → "yarin"). */
    public static function temiz(string $metin): string {
        return self::katla(self::normalize($metin));
    }

    /** HTML'den düz metin özeti (log ve sohbet geçmişi için). */
    public static function duzMetin(string $html, int $sinir = 0): string {
        $metin = preg_replace('/<(br|\/p|\/div|\/li|hr)[^>]*>/i', ' ', $html) ?? $html;
        $metin = html_entity_decode(strip_tags($metin), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $metin = trim(preg_replace('/\s+/u', ' ', $metin) ?? $metin);
        if ($sinir > 0 && mb_strlen($metin) > $sinir) {
            $metin = rtrim(mb_substr($metin, 0, $sinir - 1)) . '…';
        }
        return $metin;
    }
}
