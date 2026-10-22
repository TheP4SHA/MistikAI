# MistikAI — Self-Hosted Campus Assistant Engine

<div align="center">

![MistikAI](https://img.shields.io/badge/MistikAI-v1.1-8b5cf6?style=for-the-badge)
![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-MariaDB-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![BERT](https://img.shields.io/badge/BERT-Custom_Trained-FF6F00?style=for-the-badge)
![Groq](https://img.shields.io/badge/Groq-Llama_3.x-F55036?style=for-the-badge)

**An open-source chatbot engine that any school can host, fill with its own data and adapt: keyword rules first, an optional BERT intent model second, an optional LLM last.**

[Features](#features) · [Architecture](#architecture) · [Quick start](#quick-start) · [Configuration](#configuration) · [Adapting it](#adapting-mistikai-to-your-school) · [Security & privacy](#security--privacy) · [BERT](#bert-service-optional) · [Development](#development--tests) · [Türkçe](README_TR.md)

</div>

---

## What it is

MistikAI answers students' everyday questions in a chat window: what is on the cafeteria menu today, whether they have classes tomorrow, when the midterms start, which floor an office is on. It is plain PHP with a MySQL/MariaDB database and comes with an admin panel, so staff can manage keywords, replies, button menus and content without touching code.

Each message goes through up to three layers: fast keyword rules, an optional BERT intent classifier and an optional LLM. Only the first layer is required, so MistikAI also runs on ordinary shared hosting without Python or an API key.

MistikAI is not tied to any institution. The repository ships with sample data for a fictional school, **"Örnek Meslek Yüksekokulu"**: departments, courses, instructors, meals and dates are made up. Replace them with your own (see [Adapting MistikAI to your school](#adapting-mistikai-to-your-school)).

The chat interface, the admin panel, the bundled modules and the keyword router are in **Turkish**. The router understands Turkish casing (`İ`/`I`), suffixes and text typed without Turkish characters.

## Screenshots

<details open>
<summary><b>Chat and admin panel (sample data)</b></summary>
<br>

| Chat (dark) | Chat (light) |
|:---:|:---:|
| ![Chat, dark theme](screenshots/chat/chat-dark.png) | ![Chat, light theme](screenshots/chat/chat-light.png) |

| Dashboard | Conversation log |
|:---:|:---:|
| ![Admin dashboard](screenshots/admin/dashboard.png) | ![Conversation log](screenshots/admin/blackbox-logger.png) |

| Flow builder | Modules |
|:---:|:---:|
| ![Flow builder](screenshots/admin/flow-builder-1.png) | ![Module manager](screenshots/admin/intent-lab-1.png) |

| Dynamic content (cafeteria menu) | Keyword lab |
|:---:|:---:|
| ![Dynamic content](screenshots/admin/dynamic-content-1.png) | ![Keyword lab](screenshots/admin/intent-lab-4.png) |

</details>

## Features

| | Feature | What it does |
|---|---------|--------------|
| 🎓 | **Course schedule** | Asks for department and year once (buttons), remembers the choice for the session and answers for today, tomorrow, a weekday or the whole week |
| 🍽️ | **Cafeteria menu** | Today's, tomorrow's, a weekday's or the weekly menu from the meal table |
| 📅 | **Academic calendar** | Upcoming events, or the registration, add/drop, midterm, final and make-up dates |
| 🏫 | **Campus guide** *(example)* | Office, lab and classroom floor lookup for a fictional one-building school |
| 📞 | **Staff phonebook** *(example integration)* | Two-step name search against a JSON directory API (`$REHBER_API_URL`) |
| 🔮 | **Campus horoscope** | A light-hearted daily horoscope |
| 💬 | **Text replies** | Greetings, a polite reply to insults, or any fixed answer, without PHP |
| 🔄 | **Dialog flows** | Button menus and sub-menus built in a visual flow builder |
| 🧠 | **Turkish-aware router** | Case folding, suffix and typo tolerance, priorities, time-of-day rules, candidate buttons when unsure |
| 🤖 | **Tool-calling LLM** | Optional; may only call the active modules, knows today's date, falls back to a second model |
| 📊 | **Admin panel** | Dashboard, conversation log with answering layer and latency, module manager, keyword lab, course import, content scraper, flow builder, settings, admin roles |
| 🌙 | **Dark/light theme** | Theme toggle, remembered per browser |

## Architecture

```
┌─────────────────────────────────────────────────────┐
│     User message  (rate limit + length check)        │
└──────────────────────┬──────────────────────────────┘
                       ▼
         ┌──────────────────────────┐
         │  Layer 1: Rule-based     │   Keywords editable in the
         │  router (keyword match)  │   admin, priority scores 1-100
         └──────────┬───────────────┘
                    │
          ┌─────────┴─────────┐
          ▼                   ▼
    [Clear match]      [Ambiguous / no match]
          │                   │
          ▼                   ▼
    Run module        ┌──────────────────────┐
                      │  Layer 2: BERT       │   Optional, trained on
                      │  intent recognition  │   your own keywords
                      └──────────┬───────────┘
                                 │
                       ┌─────────┴─────────┐
                       ▼                   ▼
                 [Intent found]    [kapsam_disi / offline]
                       │                   │
                       ▼                   ▼
                 Run module        ┌──────────────────────┐
                                   │  Layer 3: LLM chat   │   Optional, any OpenAI-
                                   │  (tool calling)      │   compatible API (Groq)
                                   └──────────┬───────────┘
                                              │
                                              ▼
                                  Module call or text reply
                                              │
                                              ▼
                                   ┌──────────────────┐
                                   │  Logger           │  layer, latency,
                                   │  (every message)  │  salted IP hash
                                   └──────────────────┘
```

1. **Rule-based router** answers routine questions instantly from keywords with priority scores, with no external calls. Matching is Turkish-aware (`İ`/`I`, ASCII folding so `yarin` matches `yarın`), tolerates suffixes (`yemekhanede` → `yemekhane`) and one-letter typos in longer words. When two modules score too close, it offers candidate buttons instead of guessing.
2. **BERT intent recognition** settles keyword collisions ("yarın ne var" could be food or classes). Out-of-scope messages (`kapsam_disi`) and low-confidence predictions go on to the LLM.
3. **LLM conversation** handles free-form questions. The model can call only the active modules (OpenAI-compatible tool calling), gets the current date in its system prompt, falls back to a second model and fails with a short, friendly message.

Button clicks (`flow_…`, `modul_…`, `set_program_…`) skip the layers and run directly. More detail: [ARCHITECTURE.md](ARCHITECTURE.md).

## Quick start

### Requirements

- PHP 8.0 or newer with `pdo_mysql`, `curl`, `mbstring`, `dom` and `json`
- MySQL 5.7+ or MariaDB 10.5+ (utf8mb4)
- A web server that runs PHP (Apache, nginx + PHP-FPM, or the PHP built-in server for a local try-out)
- Optional: an API key for an OpenAI-compatible LLM (Groq by default)
- Optional: Python 3.9–3.12 for the BERT service

### 1. Get the code and create the database

```bash
git clone https://github.com/TheP4SHA/MistikAI.git
cd MistikAI

mysql -u root -p -e "CREATE DATABASE mistikai_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p mistikai_db < database/schema.sql
mysql -u root -p mistikai_db < database/seed.sql     # optional: sample data for the fictional school
```

The seed creates sample modules, keywords, departments, courses, meals, calendar entries and a main menu. It does not create an admin account and does not set any scraper source URL.

### 2. Configure

```bash
cp config/db.php.example config/db.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # use the output as $IP_TUZU
```

Edit `config/db.php`: database credentials, `$IP_TUZU`, your school's name in `$OKUL_BILGISI`, and either an LLM key in `$GROQ_API_KEY` or `''` to run without the LLM. Set `$BERT_API_URL = ''` unless you run the BERT service. All keys are listed under [Configuration](#configuration). `config/db.php` is ignored by git.

### 3. Serve it

MistikAI has no build step. The chat page is `public/index.php` (it posts to `public/api.php`) and the admin panel is `admin/`; both must be reachable from the browser.

Local try-out with the PHP built-in server, from the project folder:

```bash
php -S 127.0.0.1:8000 -t .
# chat:   http://127.0.0.1:8000/public/
# admin:  http://127.0.0.1:8000/admin/
```

On a real web server, place the project folder under the web root, for example as `/MistikAI`: the chat is then `https://your-host/MistikAI/public/`, the admin panel `https://your-host/MistikAI/admin/`, and `$BASE_PATH` must be `'/MistikAI'` (keep it `''` when the project is the web root). `config/`, `src/`, `modules/`, `database/`, `tests/` and `bert-model/` are not meant to be served; on Apache each of these folders ships with an `.htaccess` containing `Require all denied` (requires `AllowOverride All` or at least `AuthConfig`); on nginx add `location ~ ^/MistikAI/(config|src|modules|database|tests|bert-model|docs)/ { deny all; }`. If you point the document root at `public/` instead, `admin/` lies outside it and needs its own alias. Server-specific notes: [docs/SETUP_GUIDE.md](docs/SETUP_GUIDE.md).

### 4. Create the first admin

There is **no default admin account**. Open the first-run setup page **`admin/kurulum.php`** (for example `http://127.0.0.1:8000/admin/kurulum.php`) and create the first full admin (password at least 10 characters). The page only works while no admin exists; afterwards it returns 404. Log in at `admin/`. Further admins are added by a full admin under **Sistem Ayarları**.

Then try the chat: `merhaba` should get a greeting, and the **Neler yapabilirsin?** button shows the main menu. The sample meals and calendar entries are dated in the first half of 2026; add your own data to get answers for today.

### Upgrading an existing installation

From **1.0**: back up the database, then run the migration once (it is safe to re-run):

```bash
mysql -u USER -p mistikai_db < database/migrations/2026-10-guvenlik.sql
```

It adds the admin roles, the login-attempt table and the log layer/latency columns, closes open panel registration and **deletes any admin that still uses the password hash shipped in the 1.0 seed**. If that was your only admin, create a new one on `admin/kurulum.php`.

For every upgrade, compare your `config/db.php` with `config/db.php.example` and add the missing keys. Since the institution-neutral release:

- the chat page reads `$OKUL_BILGISI` from `config/db.php` for its title and welcome text. Copy the `if (!defined('MISTIK_YALNIZ_AYAR'))` guard around the PDO connection from the example, so the chat page does not open a database connection;
- `$REHBER_API_URL` has no built-in address; set it if you use the phonebook module;
- the scraper source URLs have no built-in defaults; the ones already saved in your database are kept.

See [CHANGELOG.md](CHANGELOG.md).

## Configuration

All settings are PHP variables in `config/db.php` (copied from `config/db.php.example`).

| Variable | Default in the example | Description |
|----------|------------------------|-------------|
| `$DB_HOST` | `'127.0.0.1'` | Database host |
| `$DB_NAME` | `'mistikai_db'` | Database name |
| `$DB_USER` / `$DB_PASS` | placeholders | Database credentials |
| `$GROQ_API_KEY` | placeholder | API key for the LLM endpoint; `''` runs without layer 3 |
| `$BERT_API_URL` | `'http://localhost:8005/niyet-algila'` | BERT service endpoint; `''` disables layer 2 |
| `$BERT_API_KEY` | `''` | Shared secret sent as `X-Api-Key`; must match `BERT_API_KEY` of the service |
| `$BASE_PATH` | `''` | URL prefix when installed in a sub-folder, e.g. `'/MistikAI'` (no trailing slash) |
| `$ADMIN_TEMIZ_URL` | `false` | `true`: admin links use clean URLs (`/admin/dashboard`), which need rewrite rules mapping them to `admin/pages/*.php`; `false`: direct files (`/admin/pages/dashboard.php`), works everywhere |
| `$ADMIN_KOD_EDITORU` | `false` | In-browser PHP module editor and SQL terminal (full admins only). Keep `false` in production |
| `$GUVENILIR_VEKILLER` | `[]` | Reverse-proxy IPs whose `X-Forwarded-For` header is trusted, e.g. `['127.0.0.1']` |
| `$IP_TUZU` | placeholder | Salt for the IP hash in logs and rate limiting. **Set a long random value** |
| `$LLM_MODEL` | `'llama-3.3-70b-versatile'` | Primary model |
| `$LLM_YEDEK_MODEL` | `'llama-3.1-8b-instant'` | Fallback model when the primary is rate-limited or down |
| `$LLM_MAX_TOKENS` | `400` | Max tokens per LLM reply |
| `$LLM_API_URL` | `'https://api.groq.com/openai/v1/chat/completions'` | Any OpenAI-compatible chat completions endpoint |
| `$DAKIKA_ISTEK_SINIRI` | `20` | Chat requests per minute per client |
| `$MESAJ_MAX_UZUNLUK` | `500` | Max characters per chat message |
| `$LOG_SAKLAMA_GUN` | `180` | Chat logs older than this many days are deleted |
| `$OKUL_BILGISI` | `'Örnek Meslek Yüksekokulu'` | Your school's name: chat page title and welcome text, and the LLM system prompt. Empty falls back to the example name |
| `$SCRAPER_IZINLI_HOSTLAR` | `[]` | Hosts the content scraper may fetch, e.g. `['www.example.edu.tr']`; empty disables the scraper |
| `$REHBER_API_URL` | `''` | Staff directory API for the phonebook module, e.g. `'https://rehber.example.edu.tr/api/ara'`; empty: the module replies "Rehber servisi ayarlanmamış." |

The scraper source pages (cafeteria menu and academic calendar) are not in this file. They are stored in the database and set in the admin panel under **Sistem Ayarları**; both are empty by default.

## Adapting MistikAI to your school

### 1. Name and sample data

- Put your school's name in `$OKUL_BILGISI`.
- Replace the sample data. Departments: `bot_fakulteler`, `bot_bolumler`. Courses: **Dinamik İçerik → Ders Programı** (bulk import from structured text) or `bot_ders_programi`. Meals: `bot_zaman_cizelgesi` with `tip = 'yemek'`, or the scraper. Calendar: `bot_akademik_takvim`, or the scraper. Button menus: **Akış (Flow) Yönetimi**.
- Department synonyms (`yazılım` → the `bilgisayar` department) live in `bot_varliklar` and `bot_varlik_esanlamlilar`.

### 2. Keywords

Every module card in **Modül / Niyet Yönetimi** has a **Lab** button that opens the keyword lab for that module. A keyword has:

- a priority from 1 to 100 (higher wins),
- an optional fixed reply (`cevap_override`) that is sent instead of running the module,
- an optional time rule (`sabah`, `gun`, `aksam`, `gece`), so "günaydın" scores lower in the evening.

Matching is word-based: exact words and phrases, keywords of 4+ letters also match words that start with them, and words of 6+ letters may contain one typo. Changes saved in the admin panel apply at once; direct database edits show up within about a minute (keyword cache). The **Blackbox** log lists messages that nothing understood; turn them into keywords from there.

### 3. Modules

A module is a row in `bot_moduller` plus, optionally, a PHP file `modules/<modul_kodu>.php`. Without a file, the module answers with its fixed text (`cevap_metni`). Only active modules can run, and only from the `modules/` folder.

1. Create the module in **Modül / Niyet Yönetimi → Yeni Niyet Oluştur** (name, code `a-z0-9_`, optional text).
2. For dynamic answers add `modules/<code>.php`.
3. Add keywords in the module's **Lab**.

```php
<?php
// modules/kutuphane.php
// Available: $this->db (PDO), $this->response($html, $buttons), $this->bekle($data),
//            $clean_input (lower-case, no punctuation, ASCII-folded), $raw_input, $bekleyen

$html  = "📚 <b>Kütüphane</b><br>";
$html .= "Hafta içi 08:00-22:00, hafta sonu 10:00-18:00";

return $this->response($html, [
    ['text' => '📅 Akademik takvim', 'payload' => 'modul_akademik_takvim'],
    ['text' => '🌐 Web sitesi', 'url' => 'https://www.example.edu.tr/kutuphane'],
]);
```

- Buttons are `['text' => …, 'payload' => …]` (`flow_<menu>`, `modul_<code>` or plain text) or `['text' => …, 'url' => 'https://…']`.
- `$this->bekle([...])` sends the user's next message back to the same module, with the data in `$bekleyen` (two-step questions, as in the phonebook). Users can type `iptal` to cancel.
- Module and flow HTML passes through an allow-list sanitizer (formatting tags, lists, tables, `span`/`div`, safe links, `https` images); scripts, event handlers and `javascript:` links are removed. Escape anything you echo back from the user or from an external API.
- When the LLM is enabled, every active module with a file or a fixed text is offered to it as a tool, described by the module name (and button text). Name modules clearly.

`modules/kampus_rehberi.php`, `modules/telefon_rehberi.php` and `modules/kampus_astroloji.php` ship as files but are not in the seed. Create a module with the same code to enable one.

### 4. Data sources

The integrations below are **examples** written for one sample site structure and one sample API. Adapt them to your institution's pages and services.

- **Content scraper** (`admin/pages/icerik.php`): fetches the cafeteria menu and the academic calendar from the URLs saved under **Sistem Ayarları**. While a URL is empty the scraper reports that no source is defined ("kaynak adresi tanımlı değil"), and it only fetches hosts listed in `$SCRAPER_IZINLI_HOSTLAR`. `yemekAyristir()` expects table rows of date (`DD.MM.YYYY`), main dish, three side dishes and calories; `takvimAyristir()` expects rows of start date, end date and activity, with dates such as `28 Temmuz 2025 Pazartesi` and `GÜZ YARIYILI` / `BAHAR YARIYILI` rows that switch the term. Rewrite these two functions for your pages, or skip the scraper and fill the tables yourself. Only scrape pages you are allowed to, and respect the site's terms and robots rules.
- **Phonebook** (`modules/telefon_rehberi.php`): calls `GET <$REHBER_API_URL>?aranan=<name>` and expects a JSON array whose items have `adsoyad`, `unvankisa`, `birim`, `telefon`, `email` and `resim`. With an empty `$REHBER_API_URL` it replies "Rehber servisi ayarlanmamış." without a network call. Change the request and the field names for your directory service.
- **Campus guide** (`modules/kampus_rehberi.php`): the offices and the floor plan (rooms 1-8 on the first floor, and so on) describe a fictional building. Edit them for your campus.

### 5. Language and time zone

Replies, the LLM system prompt and the router's normalization are Turkish, and dates use `Europe/Istanbul` (set in `public/api.php` and in the LLM prompt). Change these if your school is elsewhere.

## Admin panel

- **Ana Ekran (Dashboard)**: today's interactions, success rate, blind spots, most used modules, live log feed
- **Blackbox (Log Analizi)**: every message with module, 0-100 confidence, answering layer (`kural`, `bert`, `llm`, `akis`, `komut`, `sinir`) and latency; filter and teach from here
- **Modül / Niyet Yönetimi**: create, edit, pause modules; **Lab** per module for keywords; PHP editor and SQL terminal only with `$ADMIN_KOD_EDITORU = true`
- **Akış (Flow) Yönetimi**: button menus as a canvas or a list; buttons open a sub-menu, run a module or open a link
- **Dinamik İçerik**: cafeteria menu and academic calendar (scraper) and the course schedule import
- **Sistem Ayarları**: scraper source URLs; full admins manage admin accounts

Admins are **full** (`tam`) or **restricted** (`kisitli`). Only full admins add admins and, when enabled, use the code editor and SQL terminal.

## Security & privacy

- CSRF tokens on the chat API and on every admin form
- PDO prepared statements with native prepares for all SQL
- Allow-list HTML sanitizer for module and flow replies; LLM output is rendered as text (bold, lists and `https` links only)
- Content Security Policy and other security headers
- Session hardening: `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, strict mode, new session ID at login
- Admin login throttling per IP and username
- Per-minute rate limit and message length limit on the chat API
- In-browser PHP editor and SQL terminal off by default (`$ADMIN_KOD_EDITORU`)
- Scraper limited to `$SCRAPER_IZINLI_HOSTLAR`, no automatic redirects, every resolved IP must be public (SSRF guard)
- `X-Forwarded-For` trusted only from `$GUVENILIR_VEKILLER`
- No default admin; first-run setup page; bcrypt password hashes
- BERT service bound to `127.0.0.1` with an optional API key
- CI: PHP lint, tests and a gitleaks secret scan

**Privacy (KVKK / GDPR)**

- Chat logs and the rate limiter store a **salted hash of the IP address** (`$IP_TUZU`), never the IP itself.
- Logs are deleted after `$LOG_SAKLAMA_GUN` days (default 180).
- Message texts are stored in the logs so that admins can improve the keywords. Tell your users.
- **When layer 3 is used, the user's message and a short recent history are sent to the LLM provider** (Groq by default, a third party outside Türkiye). If you enable it, disclose this in your privacy notice (KVKK aydınlatma metni) and make sure you have a legal basis. `$GROQ_API_KEY = ''` keeps all processing on your server.

Found a vulnerability? Report it privately, see [SECURITY.md](SECURITY.md).

## BERT service (optional)

`bert-model/` contains everything for layer 2:

- `extract_dataset.py` builds a dataset from your keywords (expanded with Turkish templates) plus an out-of-scope class from `kapsam_disi.txt`,
- `train.py` fine-tunes a Turkish BERT model (fixed seed, stratified split, per-class report),
- `serve.py` serves it on `127.0.0.1:8005` (`POST /niyet-algila`, `GET /saglik`) with an optional `BERT_API_KEY` and a confidence threshold (`BERT_ESIK`, default 0.6).

Point `$BERT_API_URL` at the service and set the same `$BERT_API_KEY`. Retrain after adding modules or many keywords. Details: [bert-model/README.md](bert-model/README.md).

## Development & tests

```
MistikAI/
├── public/          # chat page (index.php), API (api.php), assets
├── admin/           # admin panel; kurulum.php = first-run setup
├── src/             # engine: MistikEngine, RuleBasedRouter, BertClient, LLMClient, Logger, Guvenlik …
├── modules/         # module files (modules/<modul_kodu>.php)
├── config/          # db.php.example (copy to db.php, ignored by git)
├── database/        # schema.sql, seed.sql (sample data), migrations/
├── bert-model/      # BERT dataset, training and service
├── tests/           # dependency-free PHP test runner
├── docs/            # setup guide
└── .github/         # CI workflow, gitleaks config
```

```bash
php tests/run.php                                               # unit tests
MISTIK_TEST_DB_NAME=mistik_test MISTIK_TEST_DB_USER=... MISTIK_TEST_DB_PASS=... php tests/run.php
                                                                # + database tests (each runs in a rolled-back transaction)
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
python3 -m py_compile bert-model/*.py
```

CI (GitHub Actions) runs `php -l` and the tests on PHP 8.0 and 8.3, byte-compiles the Python scripts and runs a gitleaks secret scan. Contributions are welcome: open an issue or a pull request, and keep CI green. Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE](LICENSE).

---

<div align="center">
<sub>MIT License · © TheP4SHA</sub>
</div>
