# MistikAI — Hybrid AI Campus Assistant

<div align="center">

![MistikAI](https://img.shields.io/badge/MistikAI-v1.0-8b5cf6?style=for-the-badge)
![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-MariaDB-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![BERT](https://img.shields.io/badge/BERT-Custom_Trained-FF6F00?style=for-the-badge)
![Groq](https://img.shields.io/badge/Groq-Llama_3.1-F55036?style=for-the-badge)

**A 3-layer hybrid AI engine that combines Rule-Based matching, custom-trained BERT intent recognition, and LLM-powered conversation for intelligent campus services.**

[Architecture](#architecture) · [Installation](#installation) · [Modules](#modules) · [Admin Panel](#admin-panel) · [BERT Training](#bert-model-training)

</div>

---

## Overview

MistikAI is a full-stack intelligent assistant built for **Osmaniye Meslek Yüksekokulu (OMYO)**. It provides students with instant access to course schedules, cafeteria menus, academic calendar, campus navigation, and more through a modern chat interface.

What makes MistikAI unique is its **3-layer hybrid architecture** — it starts with fast keyword matching, escalates to a custom-trained BERT model when keywords are ambiguous, and falls back to an LLM for natural conversation.
## Screenshots

<details open>
<summary><b>View Interface & Admin Dashboard</b></summary>
<br>

| Chat Interface (Dark) | Chat Interface (Light) |
|:---:|:---:|
| ![Chat Dark](screenshots/chat/chat-dark.png) | ![Chat Light](screenshots/chat/chat-light.png) |

| Admin Dashboard | Flow Builder |
|:---:|:---:|
| ![Dashboard](screenshots/admin/dashboard.png) | ![Flow Builder](screenshots/admin/flow-builder-1.png) |

| Intelligence Lab (Intent) | Dynamic Content (Scraping) |
|:---:|:---:|
| ![Intent Lab](screenshots/admin/intent-lab-1.png) | ![Dynamic Content](screenshots/admin/dynamic-content-1.png) |

</details>

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                   User Message                       │
└──────────────────────┬──────────────────────────────┘
                       ▼
         ┌──────────────────────────┐
         │  Layer 1: Rule-Based     │   700+ keywords with
         │  Router (Keyword Match)  │   priority scores (1-100)
         └──────────┬───────────────┘
                    │
          ┌─────────┴─────────┐
          ▼                   ▼
    [Exact Match]      [Ambiguous / No Match]
          │                   │
          ▼                   ▼
    Execute Module    ┌──────────────────────┐
                      │  Layer 2: BERT       │   Custom-trained
                      │  Intent Recognition  │   Turkish NLU model
                      └──────────┬───────────┘
                                 │
                       ┌─────────┴─────────┐
                       ▼                   ▼
                 [Intent Found]    [Unknown Intent]
                       │                   │
                       ▼                   ▼
                 Execute Module    ┌──────────────────────┐
                                   │  Layer 3: LLM Chat   │
                                   │  (Groq / Llama 3.1)  │
                                   └──────────┬───────────┘
                                              │
                                              ▼
                                     Natural Response
                                              │
                                              ▼
                                   ┌──────────────────┐
                                   │  Deep Logger      │
                                   │  (Every message)  │
                                   └──────────────────┘
```

### Why 3 Layers?

1. **Rule-Based Router** — Handles 80%+ of queries instantly via keyword matching with priority scores. No API calls needed.
2. **BERT Intent Recognition** — When synonyms cause ambiguity between modules (e.g., "yarın ne var" could be food or classes), the custom BERT model resolves the intent.
3. **LLM Conversation** — For free-form chat, greetings, and queries that don't match any module.

## Features

| Feature | Description |
|---------|------------|
| 🎓 **Course Schedule** | Query daily/weekly class schedules by department and year |
| 🍽️ **Cafeteria Menu** | Today's, tomorrow's, or weekly meal menus with calories |
| 📅 **Academic Calendar** | Exam dates, registration periods, holidays |
| 🏫 **Campus Guide** | Building locations, classroom/lab floor mapping |
| 📞 **Phone Directory** | Live search of university staff directory |
| 🔮 **Campus Horoscope** | Fun daily horoscope with campus-themed predictions |
| 🛡️ **Profanity Filter** | Rule-based Turkish profanity detection |
| 🌙 **Dark/Light Theme** | Modern UI with theme toggle |
| 📊 **Admin Dashboard** | Log analysis, module management, keyword training |
| 🔄 **Dialog Flows** | Visual flow builder for multi-step conversations |

## Tech Stack

- **Backend:** PHP 8.0+ (OOP, PDO)
- **Database:** MySQL / MariaDB (utf8mb4)
- **AI Layer 1:** Custom Rule-Based Engine with priority scoring
- **AI Layer 2:** BERT (Custom-trained for Turkish intent classification)
- **AI Layer 3:** Groq API (Llama 3.1 8B Instant)
- **Frontend:** Vanilla JS, CSS3 (Glassmorphism, CSS Variables)
- **Security:** CSRF tokens, prepared statements, session hardening

## Installation

### Prerequisites

- PHP 8.0+ with `curl`, `pdo_mysql`, `mbstring` extensions
- MySQL 5.7+ or MariaDB 10.5+
- Apache with `mod_rewrite` (or Nginx equivalent)
- (Optional) Groq API key for LLM chat features
- (Optional) Custom BERT model endpoint for enhanced intent recognition

### Step 1: Clone & Configure

```bash
git clone https://github.com/yourusername/MistikAI.git
cd MistikAI

# Create your configuration file
cp config/db.php.example config/db.php
# Edit config/db.php with your database credentials and API keys
```

### Step 2: Database Setup

```bash
# Create the database
mysql -u root -p -e "CREATE DATABASE mistikai_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Import schema
mysql -u root -p mistikai_db < database/schema.sql

# Import sample data (optional, for demo)
mysql -u root -p mistikai_db < database/seed.sql
```

### Step 3: Web Server

Point your web server's document root to the `public/` directory:

**Apache (Virtual Host):**
```apache
<VirtualHost *:80>
    DocumentRoot "/path/to/MistikAI/public"
    ServerName mistikai.local
    <Directory "/path/to/MistikAI/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Step 4: Access

- **Chatbot:** `http://mistikai.local/`
- **Admin Panel:** `http://mistikai.local/admin`
- **Default Admin:** Username: `admin` / Password: `admin123` (change immediately!)

## Project Structure

```
MistikAI/
├── public/              # Web-accessible files
│   ├── index.php        # Chat interface
│   ├── api.php          # API entry point
│   └── assets/          # Static assets
│
├── src/                 # Core engine (OOP)
│   ├── MistikEngine.php # Main orchestrator
│   ├── RuleBasedRouter.php  # Layer 1: Keyword matching
│   ├── BertClient.php   # Layer 2: BERT API client
│   ├── LLMClient.php    # Layer 3: Groq/LLM client
│   └── Logger.php       # Deep interaction logger
│
├── modules/             # Plugin modules
│   ├── ders_programi.php
│   ├── yemekhane.php
│   ├── akademik_takvim.php
│   ├── kampus_rehberi.php
│   ├── telefon_rehberi.php
│   └── kampus_astroloji.php
│
├── admin/               # Admin panel
├── database/            # SQL schema & seed data
├── config/              # Configuration (gitignored)
├── bert-model/          # BERT training resources
└── docs/                # Additional documentation
```

## Modules

Each module is a standalone PHP file in `modules/`. They receive `$clean_input`, `$raw_input`, and have access to `$this` (MistikEngine instance with `$this->db` and `$this->response()`).

### Creating a Custom Module

1. Create a new file in `modules/` (e.g., `kutupbane.php`)
2. Add the module to `bot_moduller` table
3. Add keywords to `bot_anahtar_kelimeler` table
4. The module will be automatically available

```php
<?php
// modules/kutuphane.php
// Available: $this->db, $this->response(), $clean_input, $raw_input

$html = "📚 <b>Kütüphane Bilgileri:</b><br>";
$html .= "Çalışma saatleri: 08:00 - 22:00<br>";
$html .= "Hafta sonu: 10:00 - 18:00";

return $this->response($html);
```

## Admin Panel

The admin panel provides:

- **Dashboard** — KPI cards, live log feed, module success rates
- **Blackbox Logger** — Analyze every user interaction with confidence scores
- **Module Manager** — Create/edit modules with an in-browser PHP IDE
- **Intelligence Lab** — Manage keywords, synonyms, and priority rules
- **Course Import** — Bulk import course schedules from structured text
- **Content Scraper** — Import cafeteria menus and academic calendar from university website
- **Flow Builder** — Visual dialog flow designer with canvas studio

## BERT Model Training

See [bert-model/README.md](bert-model/README.md) for details on:
- Training data extraction from the keyword database
- Model architecture and training process
- Deploying the inference API

## Security

- ✅ CSRF token verification on all API requests
- ✅ Prepared statements (PDO) for all SQL queries
- ✅ Session hardening (`httponly`, `samesite=Strict`)
- ✅ Input sanitization (`htmlspecialchars`)
- ✅ `.htaccess` protection for config files
- ✅ API rate limiting by IP address
- ✅ bcrypt password hashing for admin accounts

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Disclaimer / Legal Notice

> [!WARNING]
> This project includes web scraping scripts (within the admin panel's `icerik.php`) designed specifically as a **proof of concept** for Osmaniye Korkut Ata University (osmaniye.edu.tr). 
> 
> Scraping data from websites without explicit permission may violate their Terms of Service and could be considered unethical or illegal depending on your jurisdiction. The author assumes no responsibility for how these scripts are used. Always ensure you have the right to scrape, access, and store third-party data before deploying this in a production environment.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

<div align="center">
<sub>Built with ❤️ by <strong>Pasha</strong> at Osmaniye Korkut Ata University</sub>
</div>
