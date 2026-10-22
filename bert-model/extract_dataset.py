#!/usr/bin/env python3
"""MistikAI - BERT training dataset builder.

Reads the layer-1 keywords of every active module from MySQL/MariaDB, expands
them into realistic Turkish questions with templates, adds the out-of-scope
class `kapsam_disi` from kapsam_disi.txt and writes dataset.json.

Database settings come from the environment (or CLI flags):
    MISTIK_DB_HOST (127.0.0.1)  MISTIK_DB_NAME (mistikai_db)
    MISTIK_DB_USER              MISTIK_DB_PASS (prompted when unset)

    python extract_dataset.py
    python extract_dataset.py --sade-moduller selamlama,argo_filtresi --max-ornek 300

Templates produce imperfect Turkish on purpose: they teach the model which
words matter, not grammar. Real, hand-checked user questions are always better
training data - add them to the database as keywords or edit dataset.json.
"""
import argparse
import getpass
import json
import os
import random
import re
from pathlib import Path

BASE = Path(__file__).resolve().parent
KAPSAM_DISI = 'kapsam_disi'

# Applied to short noun-like keywords ("yemek", "akademik takvim", "bütünleme").
SABLONLAR = [
    '{x}',
    '{x}?',
    '{x} nedir',
    '{x} ne zaman',
    '{x} nerede',
    '{x} var mı',
    '{x} nasıl',
    '{x} ne durumda',
    '{x} saat kaçta',
    '{x} bilgisi',
    '{x} hakkında bilgi',
    '{x} hakkında bilgi verir misin',
    '{x} ile ilgili bilgi lazım',
    '{x} göster',
    'bugün {x}',
    'bugün {x} var mı',
    'yarın {x}',
    'yarın {x} var mı',
    'bu hafta {x}',
    'haftaya {x} var mı',
    'acaba {x}',
    'mistik {x}',
    'selam {x}',
    'hocam {x} ne zaman',
]

# Applied to full questions ("bugün yemekte ne var", "finaller ne zaman").
HAFIF_SABLONLAR = [
    '{x}',
    '{x}?',
    'acaba {x}',
    '{x} acaba',
    'mistik {x}',
    'selam {x}',
    'hocam {x}',
    '{x} söyler misin',
]

# Applied to conversational modules (--sade-moduller), e.g. greetings.
SOHBET_SABLONLAR = [
    '{x}',
    '{x}!',
    '{x} mistik',
    'mistik {x}',
    'hey {x}',
    '{x} dostum',
]

SORU_IZI = re.compile(r'\b(ne|neler|nerede|nereye|kaç|kaçta|hangi|nasıl|mı|mi|mu|mü|mısın|misin|musun|müsün)\b')

ASCII = str.maketrans('çğıöşüÇĞİÖŞÜâîûÂÎÛ', 'cgiosuCGIOSUaiuAIU')


def tr_lower(text):
    """Turkish-aware lower-case (I -> ı, İ -> i)."""
    return text.replace('I', 'ı').replace('İ', 'i').lower()


def katla(text):
    """ASCII folding, the way many users type on phones: 'yarın' -> 'yarin'."""
    return text.translate(ASCII)


def soru_mu(kelime):
    """True for full questions/phrases, which only get light augmentation."""
    return len(kelime.split()) >= 3 or bool(SORU_IZI.search(kelime))


def genislet(kelime, sablonlar):
    out = []
    for s in sablonlar:
        metin = re.sub(r'\s+', ' ', s.format(x=kelime)).strip()
        out.append(metin)
        folded = katla(metin)
        if folded != metin:
            out.append(folded)
    return out


def ornekler_uret(niyetler, sade_moduller, max_ornek, seed):
    """niyetler: {modul_kodu: [keyword, ...]} -> {etiket: [example, ...]}"""
    rng = random.Random(seed)
    sonuc = {}
    for kod, kelimeler in sorted(niyetler.items()):
        ornekler = []
        for kelime in kelimeler:
            kelime = tr_lower(kelime.strip())
            if not kelime:
                continue
            if kod in sade_moduller:
                sablonlar = SOHBET_SABLONLAR
            elif soru_mu(kelime):
                sablonlar = HAFIF_SABLONLAR
            else:
                sablonlar = SABLONLAR
            ornekler += genislet(kelime, sablonlar)
        sonuc[kod] = _benzersiz_ve_sinirli(ornekler, max_ornek, rng)
    return sonuc


def kapsam_disi_oku(path):
    cumleler = []
    with open(path, 'r', encoding='utf-8') as f:
        for satir in f:
            satir = satir.strip()
            if satir and not satir.startswith('#'):
                cumleler.append(satir)
    ornekler = []
    for c in cumleler:
        ornekler += [c, tr_lower(c), katla(tr_lower(c))]
    return ornekler


def _benzersiz_ve_sinirli(ornekler, max_ornek, rng):
    tekil = list(dict.fromkeys(ornekler))
    if max_ornek and len(tekil) > max_ornek:
        tekil = rng.sample(tekil, max_ornek)
    return tekil


def celiskileri_ayikla(veri):
    """Drop texts that ended up under more than one intent."""
    sahip = {}
    for etiket, ornekler in veri.items():
        for o in ornekler:
            sahip.setdefault(o, set()).add(etiket)
    celiskili = {o for o, etiketler in sahip.items() if len(etiketler) > 1}
    if celiskili:
        print(f"! {len(celiskili)} ambiguous example(s) removed, e.g. {sorted(celiskili)[:3]}")
    return {e: [o for o in ornekler if o not in celiskili] for e, ornekler in veri.items()}


def anahtar_kelimeleri_oku(args):
    import mysql.connector  # imported lazily so the generator can be tested without a database

    password = args.db_pass if args.db_pass is not None else getpass.getpass('Database password: ')
    db = mysql.connector.connect(host=args.db_host, database=args.db_name, user=args.db_user, password=password)
    try:
        cursor = db.cursor(dictionary=True)
        cursor.execute("""
            SELECT m.modul_kodu, k.kelime
            FROM bot_moduller m
            JOIN bot_anahtar_kelimeler k ON m.id = k.modul_id
            WHERE m.aktif_mi = 1 AND k.aktif_mi = 1
        """)
        niyetler = {}
        for row in cursor.fetchall():
            word = (row['kelime'] or '').strip()
            if word:
                niyetler.setdefault(row['modul_kodu'], []).append(word)
        cursor.close()
        return niyetler
    finally:
        db.close()


def main():
    p = argparse.ArgumentParser(description='Build dataset.json for train.py')
    p.add_argument('--db-host', default=os.environ.get('MISTIK_DB_HOST', '127.0.0.1'))
    p.add_argument('--db-name', default=os.environ.get('MISTIK_DB_NAME', 'mistikai_db'))
    p.add_argument('--db-user', default=os.environ.get('MISTIK_DB_USER', 'root'))
    p.add_argument('--db-pass', default=os.environ.get('MISTIK_DB_PASS'))
    p.add_argument('--kapsam-disi', default=str(BASE / 'kapsam_disi.txt'), help='out-of-scope sentences, one per line')
    p.add_argument('--sade-moduller', default='selamlama,argo_filtresi',
                   help='comma-separated modules that only get light augmentation')
    p.add_argument('--haric', default='', help='comma-separated modules to leave out of the dataset')
    p.add_argument('--max-ornek', type=int, default=400, help='cap per intent to keep classes balanced (0 = no cap)')
    p.add_argument('--seed', type=int, default=42)
    p.add_argument('--cikti', default=str(BASE / 'dataset.json'))
    args = p.parse_args()

    haric = {m.strip() for m in args.haric.split(',') if m.strip()}
    sade = {m.strip() for m in args.sade_moduller.split(',') if m.strip()}

    niyetler = {k: v for k, v in anahtar_kelimeleri_oku(args).items() if k not in haric}
    veri = ornekler_uret(niyetler, sade, args.max_ornek, args.seed)

    if os.path.exists(args.kapsam_disi):
        rng = random.Random(args.seed)
        veri[KAPSAM_DISI] = _benzersiz_ve_sinirli(kapsam_disi_oku(args.kapsam_disi), args.max_ornek, rng)
    else:
        print(f"! {args.kapsam_disi} not found; the model will have no out-of-scope class")

    veri = celiskileri_ayikla(veri)
    dataset = {'niyetler': [{'etiket': e, 'ornekler': o} for e, o in veri.items() if o]}

    with open(args.cikti, 'w', encoding='utf-8') as f:
        json.dump(dataset, f, ensure_ascii=False, indent=2)

    print(f"{'intent':<24} {'keywords':>8} {'examples':>8}")
    for n in dataset['niyetler']:
        print(f"{n['etiket']:<24} {len(niyetler.get(n['etiket'], [])):>8} {len(n['ornekler']):>8}")
    print(f"Saved {sum(len(n['ornekler']) for n in dataset['niyetler'])} examples to {args.cikti}")


if __name__ == '__main__':
    main()
