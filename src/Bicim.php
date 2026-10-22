<?php
// src/Bicim.php - LLM çıktısı için güvenli "markdown-lite"
// LLM cevabı HTML olarak DEĞİL metin olarak ele alınır: önce tamamen kaçışlanır,
// sonra yalnız şu biçimler eklenir: **kalın**, satır sonu, "- " / "1." listeleri ve https bağlantıları.

final class Bicim {

    /** [etiket](https://...) ya da çıplak https://... */
    private const BAGLANTI = '~\[([^\]\n]{1,200})\]\((https://[^\s()<>"\']{1,500})\)|(https://[^\s<>"\'`]{1,500})~u';

    public static function markdownLite(string $metin): string {
        $metin = str_replace(["\r\n", "\r"], "\n", $metin);
        $metin = trim(preg_replace('~<think>.*?</think>~s', '', $metin) ?? $metin);
        if ($metin === '') {
            return '';
        }

        $html = '';
        $liste = null; // 'ul' | 'ol' | null
        $bosSatir = 0;
        foreach (explode("\n", $metin) as $satir) {
            if (preg_match('/^\s*(?:[-*•])\s+(.+)$/u', $satir, $m)) {
                $tur = 'ul';
            } elseif (preg_match('/^\s*\d{1,3}[.)]\s+(.+)$/u', $satir, $m)) {
                $tur = 'ol';
            } else {
                $tur = null;
            }

            if ($liste !== null && $tur !== $liste) {
                $html .= "</{$liste}>";
                $liste = null;
            }
            if ($tur !== null) {
                if ($liste === null) {
                    $html .= "<{$tur}>";
                    $liste = $tur;
                }
                $html .= '<li>' . self::satirIci($m[1]) . '</li>';
                $bosSatir = 0;
                continue;
            }

            if (trim($satir) === '') {
                // Art arda boş satırları tek paragraf boşluğuna indir.
                if ($html !== '' && $bosSatir++ === 0) {
                    $html .= '<br>';
                }
                continue;
            }
            $bosSatir = 0;
            $html .= self::satirIci($satir) . '<br>';
        }
        if ($liste !== null) {
            $html .= "</{$liste}>";
        }
        return preg_replace('~(?:<br>)+$~', '', $html) ?? $html;
    }

    /** Tek satırı kaçışlar; bağlantıları ve **kalın** biçimini uygular. */
    private static function satirIci(string $satir): string {
        $cikti = '';
        $konum = 0;
        if (preg_match_all(self::BAGLANTI, $satir, $eslesmeler, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($eslesmeler as $e) {
                [$tamami, $bas] = $e[0];
                $cikti .= self::kalin(self::e(substr($satir, $konum, $bas - $konum)));
                $konum = $bas + strlen($tamami);

                if (isset($e[3]) && $e[3][1] >= 0) {
                    // Çıplak URL: sondaki noktalama bağlantıya dahil değil.
                    $url = $e[3][0];
                    $kuyruk = '';
                    if (preg_match('/[.,;:!?)\]]+$/u', $url, $k)) {
                        $kuyruk = $k[0];
                        $url = substr($url, 0, -strlen($kuyruk));
                    }
                    $cikti .= self::baglanti($url, $url) . self::e($kuyruk);
                } else {
                    $cikti .= self::baglanti($e[2][0], $e[1][0]);
                }
            }
        }
        $cikti .= self::kalin(self::e(substr($satir, $konum)));
        return $cikti;
    }

    private static function baglanti(string $url, string $etiket): string {
        $p = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)($p['scheme'] ?? '')) !== 'https' || empty($p['host'])) {
            return self::e($etiket);
        }
        return '<a href="' . self::e($url) . '" target="_blank" rel="noopener noreferrer">' . self::e($etiket) . '</a>';
    }

    /** Kaçışlanmış metinde **kalın** → <strong>. */
    private static function kalin(string $kacisli): string {
        return preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $kacisli) ?? $kacisli;
    }

    private static function e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
