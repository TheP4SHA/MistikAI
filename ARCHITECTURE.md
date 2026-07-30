# MistikAI — Technical Architecture

## System Overview

MistikAI uses a 3-layer cascading architecture for intent resolution:

```mermaid
flowchart TD
    A["👤 User Message"] --> B{"Layer 1: Rule-Based Router"}
    B -->|"Exact Match<br/>(score gap > 15)"| C["Execute Module"]
    B -->|"Ambiguous<br/>(score gap ≤ 15)"| D{"Layer 2: BERT"}
    B -->|"No Match"| D
    D -->|"Intent Recognized"| C
    D -->|"Unknown / Offline"| E{"Layer 3: LLM"}
    E -->|"[MODULE:x] command"| C
    E -->|"Natural reply"| F["Display Response"]
    C --> F
    F --> G["Deep Logger"]
```

## Database Schema

```mermaid
erDiagram
    bot_moduller ||--o{ bot_anahtar_kelimeler : "has keywords"
    bot_moduller ||--o{ bot_sohbet_loglari : "logs interactions"
    bot_fakulteler ||--o{ bot_bolumler : "has departments"
    bot_bolumler ||--o{ bot_ders_programi : "has courses"
    bot_fakulteler ||--o{ bot_ders_programi : "belongs to"
    bot_varliklar ||--o{ bot_varlik_esanlamlilar : "has synonyms"
    bot_akis_menuleri ||--o{ bot_akis_butonlari : "has buttons"
    
    bot_moduller {
        int id PK
        string modul_kodu UK
        string modul_adi
        text cevap_metni
        boolean aktif_mi
    }
    
    bot_anahtar_kelimeler {
        int id PK
        int modul_id FK
        string kelime
        int oncelik "1-100 priority score"
        text cevap_override
        string zaman_kontrol
    }
    
    bot_sohbet_loglari {
        bigint id PK
        string session_id
        text kullanici_mesaji
        text bot_cevabi
        int guven_skoru "0-100"
        boolean anlasildi_mi
        string algilanan_niyet
    }
```

## Rule-Based Router Algorithm

1. Normalize user input (lowercase, Turkish char handling)
2. Scan all active keywords from `bot_anahtar_kelimeler`
3. For each match: `score = max(priority, current_score) + length_bonus`
4. Sort modules by total score
5. If top 2 scores differ by < 15 → **ambiguous** → send to BERT
6. If clear winner → execute that module
7. If no matches → send to BERT

## Module Plugin System

Modules are PHP files in `modules/` that receive:
- `$clean_input` — Normalized user input
- `$raw_input` — Original user input  
- `$this` — MistikEngine instance (`$this->db`, `$this->response()`)

Return value must be a JSON string from `$this->response()`.

## Security Layers

| Layer | Implementation |
|-------|---------------|
| Authentication | bcrypt hashed passwords, session-based admin auth |
| CSRF | Per-session random token, verified on every API call |
| SQL Injection | PDO prepared statements exclusively |
| XSS | `htmlspecialchars()` on all user-facing output |
| Session | `httponly`, `samesite=Strict`, `use_strict_mode` |
| Rate Limiting | IP-based request counting per time window |
