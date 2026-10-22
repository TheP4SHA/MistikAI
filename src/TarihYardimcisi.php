<?php
// src/TarihYardimcisi.php - Türkçe gün/tarih ayrıştırma
// Ders programı ve yemekhane modülleri "salı", "cumartesi", "yarın" gibi ifadeleri buradan çözer.

require_once __DIR__ . '/Metin.php';

final class TarihYardimcisi {

    public const GUNLER = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];

    public const AYLAR = [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
                          7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];

    /** ASCII katlanmış gün adı → doğru Türkçe yazımı. "cumartesi", "cuma"dan önce denenir. */
    private const GUN_KATLANMIS = [
        'pazartesi' => 'Pazartesi', 'sali' => 'Salı', 'carsamba' => 'Çarşamba', 'persembe' => 'Perşembe',
        'cumartesi' => 'Cumartesi', 'cuma' => 'Cuma', 'pazar' => 'Pazar',
    ];

    /** Gün adlarının alabileceği yaygın ekler (salıya, cumadan, pazartesi günü…). */
    private const GUN_EKI = '(?:y?[aeiu]|[dt][ae]n?|n[iu]n|ki|l[ae]ri?)?';

    public static function gunAdi(int $isoGun): string {
        return self::GUNLER[$isoGun] ?? '';
    }

    /**
     * Metindeki gün adını bulur ("Salı dersim var mı?" → "Salı"). Kelime sınırına bakar:
     * "cumartesi" asla "Cuma", "salim" asla "Salı" olmaz.
     */
    public static function gunBul(string $metin): ?string {
        $k = Metin::temiz($metin);
        $desen = '/\b(' . implode('|', array_keys(self::GUN_KATLANMIS)) . ')' . self::GUN_EKI . '\b/';
        if (preg_match($desen, $k, $m)) {
            return self::GUN_KATLANMIS[$m[1]];
        }
        return null;
    }

    /** Göreli gün: bugün → 0, yarın → 1, öbür gün → 2, dün → -1; yoksa null. */
    public static function goreliGun(string $metin): ?int {
        $k = Metin::temiz($metin);
        if (preg_match('/\b(obur ?gun|ertesi gun)/', $k)) {
            return 2;
        }
        if (preg_match('/\byarin(ki|a)?\b/', $k)) {
            return 1;
        }
        if (preg_match('/\bdun(ku|e)?\b/', $k)) {
            return -1;
        }
        if (preg_match('/\bbugun(ku|e)?\b/', $k)) {
            return 0;
        }
        return null;
    }

    /** Verilen gün adının bugünden itibaren ilk tarihi (bugün o günse bugün). */
    public static function siradakiTarih(string $gunAdi, ?DateTimeImmutable $bugun = null): ?DateTimeImmutable {
        $hedef = array_search($gunAdi, self::GUNLER, true);
        if ($hedef === false) {
            return null;
        }
        $bugun = ($bugun ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $fark = ((int)$hedef - (int)$bugun->format('N') + 7) % 7;
        return $bugun->modify("+{$fark} day");
    }

    /** "22 Ekim 2026 Perşembe" */
    public static function uzunTarih(DateTimeInterface $t): string {
        return $t->format('j') . ' ' . self::AYLAR[(int)$t->format('n')] . ' ' . $t->format('Y') . ' ' . self::gunAdi((int)$t->format('N'));
    }
}
