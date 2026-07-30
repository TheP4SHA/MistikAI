#!/usr/bin/env python3
"""MistikAI - BERT Training Dataset Extractor
Extracts intent training data from MySQL database.
"""
import json
import mysql.connector

def extract():
    db = mysql.connector.connect(
        host='127.0.0.1',
        database='mistikai_db',
        user='your_user',
        password='your_password'
    )
    cursor = db.cursor(dictionary=True)
    
    cursor.execute("""
        SELECT m.modul_kodu, k.kelime 
        FROM bot_moduller m 
        JOIN bot_anahtar_kelimeler k ON m.id = k.modul_id 
        WHERE m.aktif_mi = 1 AND k.aktif_mi = 1
    """)
    
    intents = {}
    for row in cursor.fetchall():
        code = row['modul_kodu']
        word = row['kelime'].strip()
        if word:
            intents.setdefault(code, []).append(word)
    
    dataset = {
        "niyetler": [
            {"etiket": code, "ornekler": words}
            for code, words in intents.items()
        ]
    }
    
    with open('dataset.json', 'w', encoding='utf-8') as f:
        json.dump(dataset, f, ensure_ascii=False, indent=2)
    
    total = sum(len(v) for v in intents.values())
    print(f"✅ Extracted {total} samples across {len(intents)} intents")
    print(f"📄 Saved to dataset.json")
    
    cursor.close()
    db.close()

if __name__ == '__main__':
    extract()
