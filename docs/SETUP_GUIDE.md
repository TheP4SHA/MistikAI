# MistikAI — Step-by-Step Setup Guide

This guide walks you through setting up MistikAI from scratch. Whether you are installing it locally on Windows using XAMPP/WAMP or deploying to a production Linux server, follow these steps carefully.

## 1. System Requirements

| Requirement | Minimum Version | Note |
|-------------|----------------|------|
| PHP | 8.0 or newer | OOP features and Match expressions used |
| Database | MySQL 5.7+ or MariaDB 10.5+ | utf8mb4 support required |
| Web Server | Apache or Nginx | URL Rewriting (`mod_rewrite`) must be enabled |
| PHP Extensions | `pdo_mysql`, `curl`, `mbstring`, `json`, `dom` | DB connection, API calls, Turkish text handling, HTML sanitizer |
| Python (optional) | 3.9 - 3.12 | Only for the BERT service |

## 2. Setting Up the Database

First, you need to create the database structure and (optionally) load the demo data.

### Option A: Using phpMyAdmin (Localhost / XAMPP / WAMP)
1. Open phpMyAdmin (`http://localhost/phpmyadmin`)
2. Click **Databases** and create a new database named `mistikai_db` (Collation: `utf8mb4_unicode_ci`).
3. Click on the new database, go to the **Import** tab.
4. Browse and select `database/schema.sql` and click **Go**.
5. (Optional) Go to **Import** again and select `database/seed.sql` to load demo modules, keywords and sample data. It does not create an admin account.

### Option B: Using Command Line (Linux / Production)
```bash
# 1. Create database
mysql -u root -p -e "CREATE DATABASE mistikai_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Import schema
mysql -u root -p mistikai_db < database/schema.sql

# 3. (Optional) Import demo data
mysql -u root -p mistikai_db < database/seed.sql
```

## 3. Core Configuration (db.php)

MistikAI needs to know how to connect to your database and external APIs.

1. Navigate to the `config/` directory.
2. Duplicate `db.php.example` and rename it to `db.php`.
   ```bash
   cp config/db.php.example config/db.php
   ```
3. Open `db.php` in a text editor and fill in your details. Settings are plain PHP **variables** (not `define()` constants):

```php
<?php
// config/db.php (excerpt)
$DB_HOST = '127.0.0.1';         // Usually 127.0.0.1 or localhost
$DB_NAME = 'mistikai_db';
$DB_USER = 'root';              // For XAMPP this is usually 'root'
$DB_PASS = '';                  // For XAMPP this is usually empty

// Optional: LLM layer. Get a key at https://console.groq.com
$GROQ_API_KEY = 'gsk_...';

// Optional: BERT layer (bert-model/serve.py)
$BERT_API_URL = 'http://127.0.0.1:8005/niyet-algila';
$BERT_API_KEY = '';             // same value as BERT_API_KEY of the service

// Required: salt for the IP hash in logs and rate limiting
$IP_TUZU = 'paste-a-long-random-string-here';

// Your school's name (chat page title and welcome text, LLM system prompt)
$OKUL_BILGISI = 'Örnek Meslek Yüksekokulu';
```

Generate a salt with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Keep the rest of the file (the PDO connection and the `global` lines) as it is.

Every key, with its default, is listed in the [configuration reference](../README.md#configuration). The ones worth a look before going live:

- `$ADMIN_KOD_EDITORU` — keep `false` in production; it enables the in-browser PHP editor and SQL terminal.
- `$GUVENILIR_VEKILLER` — if MistikAI runs behind a reverse proxy or load balancer, list its IPs so the real client IP is used for rate limiting. Leave it empty otherwise.
- `$SCRAPER_IZINLI_HOSTLAR` — the content scraper only fetches from these hosts, e.g. `['www.example.edu.tr']`. Empty disables it. The source pages themselves are set in the admin panel under **Sistem Ayarları** (empty by default).
- `$REHBER_API_URL` — the staff directory API used by the example phonebook module, e.g. `'https://rehber.example.edu.tr/api/ara'`. Empty: the module answers that the directory is not configured.
- `$DAKIKA_ISTEK_SINIRI`, `$MESAJ_MAX_UZUNLUK`, `$LOG_SAKLAMA_GUN` — rate limit, message length and log retention.
- `$BASE_PATH` — set it (e.g. `'/MistikAI'`) when the site is not at the web root.

## 4. Web Server Configuration (Crucial Step)

The chat page lives in `public/` and the admin panel in `admin/`; both must be reachable from the web. The `src/`, `config/`, `modules/`, `database/`, `tests/` and `bert-model/` folders must not be served. If the document root points at `public/`, `admin/` is outside it and needs its own alias; the simplest setup is the project folder under the web root (for example `/MistikAI`, with `$BASE_PATH = '/MistikAI'`) and web access to the internal folders denied.

### Quick local try-out (PHP built-in server)

```bash
cd MistikAI
php -S 127.0.0.1:8000 -t .
# chat:  http://127.0.0.1:8000/public/
# admin: http://127.0.0.1:8000/admin/
```

Keep `$BASE_PATH = ''`. The built-in server is for development only.

### Using Localhost (XAMPP / WAMP / Laragon)

Place the `MistikAI` folder in `htdocs` / `www` (Laragon: `laragon/www`). The chat is then at `http://localhost/MistikAI/public/` and the admin panel at `http://localhost/MistikAI/admin/`; set `$BASE_PATH = '/MistikAI'`.

With a virtual host whose document root is the project folder, keep `$BASE_PATH = ''` and deny the internal folders:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/MistikAI"
    ServerName mistikai.local
    <Directory "C:/xampp/htdocs/MistikAI">
        AllowOverride All
        Require all granted
    </Directory>
    <LocationMatch "^/(config|src|modules|database|tests|bert-model)/">
        Require all denied
    </LocationMatch>
    RedirectMatch ^/$ /public/
</VirtualHost>
```

Chat: `http://mistikai.local/public/`, admin: `http://mistikai.local/admin/`.

### Using Nginx (Production)

```nginx
server {
    listen 80;
    server_name www.example.edu.tr;
    root /var/www/MistikAI;
    index index.php;

    # Internal folders are never served
    location ~ ^/(config|src|modules|database|tests|bert-model)/ {
        deny all;
    }

    location = / {
        return 302 /public/;
    }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Chat: `https://www.example.edu.tr/public/`, admin: `https://www.example.edu.tr/admin/`. Use HTTPS in production.

## 5. Verify Your Installation

1. Open the chat page (e.g., `http://127.0.0.1:8000/public/`, `http://mistikai.local/public/` or `http://localhost/MistikAI/public/`). You should see the chat interface with your `$OKUL_BILGISI` in the welcome text.
2. Type **"merhaba"**. The bot should respond immediately.
3. Type **"yemek menüsü"**. It should trigger the `yemekhane` module.

### Creating the First Admin
There is **no default admin account**.

1. Open the first-run setup page: `http://your-url/admin/kurulum.php`
2. Enter your name, a username and a password (at least 10 characters). This account is a **full admin** (`tam`).
3. The setup page stops working as soon as an admin exists. Log in at `http://your-url/admin`.

To add more admins, log in as a full admin and use **Settings**. New admins can be full or restricted (`kisitli`). Repeated failed logins are throttled per IP and username for a while.

### Upgrading from 1.0
1. Back up the database.
2. Run the migration once (it is safe to re-run):
   ```bash
   mysql -u USER -p mistikai_db < database/migrations/2026-10-guvenlik.sql
   ```
3. The migration **deletes the admin that still uses the password hash from the old seed** (the documented `admin123` never matched it; it was the public hash of `password`). If that was your only admin, create a new one on `admin/kurulum.php`.
4. Add the new keys from `config/db.php.example` to your `config/db.php`, at least `$IP_TUZU`.

## 6. Optional: Setting up the BERT Model

MistikAI works without the BERT model (keyword routing + Groq LLM). For the full 3-layer setup:

1. Install Python 3.9 - 3.12.
2. Go to the `bert-model/` directory and create a virtual environment.
3. On a CPU-only server install the CPU build of torch first, then the requirements:
   ```bash
   pip install torch==2.5.1 --index-url https://download.pytorch.org/whl/cpu
   pip install -r requirements.txt
   ```
4. Build the dataset: `python extract_dataset.py` (database settings from `MISTIK_DB_*` environment variables).
5. Train: `python train.py`
6. Start the API: `BERT_API_KEY=your-secret python serve.py` (listens on `127.0.0.1:8005`, uses waitress).

Point `$BERT_API_URL` in `config/db.php` to `http://127.0.0.1:8005/niyet-algila` and set `$BERT_API_KEY` to the same secret. Check the service with `curl http://127.0.0.1:8005/saglik`. Details, including a systemd unit: [bert-model/README.md](../bert-model/README.md).

## 7. Privacy Checklist (KVKK / GDPR)

- Set a long random `$IP_TUZU`; logs and the rate limiter store only a salted hash of the IP.
- Choose a log retention period (`$LOG_SAKLAMA_GUN`, default 180 days).
- If you enable the LLM layer, users' messages are sent to Groq. Say so in your privacy notice (KVKK aydınlatma metni) and make sure you have a legal basis, or set `$GROQ_API_KEY = ''`.

## 8. Troubleshooting

* **Blank Screen / 500 Error:** Check your PHP error logs. Make sure PHP 8.0+ is running and PDO extensions are enabled.
* **Chatbot doesn't reply:** Ensure your `config/db.php` credentials are correct. Check the Network tab in your browser's Developer Tools (F12) to see what `/api.php` is returning.
* **No natural conversation:** Ensure you have added a valid Groq API key to `db.php` and that `$LLM_MODEL` / `$LLM_YEDEK_MODEL` are models your Groq account can use.
* **"Too many requests" while testing:** raise `$DAKIKA_ISTEK_SINIRI`. Behind a proxy, add the proxy IP to `$GUVENILIR_VEKILLER`, otherwise all users share one limit.
* **Scraper does nothing:** set the source URL under **Sistem Ayarları** and add its host to `$SCRAPER_IZINLI_HOSTLAR`. The bundled parsers are examples for a sample site structure; adapt `yemekAyristir()` / `takvimAyristir()` in `admin/pages/icerik.php` to your pages.
* **Phonebook says it is not configured:** set `$REHBER_API_URL` and adapt `modules/telefon_rehberi.php` to your directory API.
* **Admin login blocked:** too many failed attempts; wait a few minutes and try again.
* **BERT never used:** check `curl http://127.0.0.1:8005/saglik` and that `$BERT_API_KEY` matches the service.
* **Database Connection Failed:** Make sure MySQL is running and the user privileges are correctly set.
