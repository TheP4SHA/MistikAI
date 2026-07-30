# MistikAI — Step-by-Step Setup Guide

This guide walks you through setting up MistikAI from scratch. Whether you are installing it locally on Windows using XAMPP/WAMP or deploying to a production Linux server, follow these steps carefully.

## 1. System Requirements

| Requirement | Minimum Version | Note |
|-------------|----------------|------|
| PHP | 8.0 or newer | OOP features and Match expressions used |
| Database | MySQL 5.7+ or MariaDB 10.5+ | utf8mb4 support required |
| Web Server | Apache or Nginx | URL Rewriting (`mod_rewrite`) must be enabled |
| PHP Extensions | `pdo_mysql`, `curl`, `mbstring`, `json` | Required for API calls and DB connection |

## 2. Setting Up the Database

First, you need to create the database structure and (optionally) load the demo data.

### Option A: Using phpMyAdmin (Localhost / XAMPP / WAMP)
1. Open phpMyAdmin (`http://localhost/phpmyadmin`)
2. Click **Databases** and create a new database named `mistikai_db` (Collation: `utf8mb4_unicode_ci`).
3. Click on the new database, go to the **Import** tab.
4. Browse and select `database/schema.sql` and click **Go**.
5. (Optional but recommended) Go to **Import** again and select `database/seed.sql` to load demo modules and admin users.

### Option B: Using Command Line (Linux / Production)
```bash
# 1. Create database
mysql -u root -p -e "CREATE DATABASE mistikai_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Import schema
mysql -u root -p mistikai_db < database/schema.sql

# 3. Import seed data (demo data and admin login)
mysql -u root -p mistikai_db < database/seed.sql
```

## 3. Core Configuration (db.php)

MistikAI needs to know how to connect to your database and external APIs.

1. Navigate to the `config/` directory.
2. Duplicate `db.php.example` and rename it to `db.php`.
   ```bash
   cp config/db.php.example config/db.php
   ```
3. Open `db.php` in a text editor and fill in your details:

```php
<?php
// config/db.php
define('DB_HOST', 'localhost'); // Usually localhost or 127.0.0.1
define('DB_NAME', 'mistikai_db');
define('DB_USER', 'root');      // For XAMPP this is usually 'root'
define('DB_PASS', '');          // For XAMPP this is usually empty

// External API Keys (Optional but highly recommended)
// Get your free API key at: https://console.groq.com
define('GROQ_API_KEY', 'your_gsk_key_here'); 

// If you are running the BERT intent recognition Python server locally
define('BERT_API_URL', 'http://127.0.0.1:5000/niyet-algila');
```

## 4. Web Server Configuration (Crucial Step)

The architecture of MistikAI dictates that **only the `public/` directory** should be accessible from the web. The `src/`, `config/`, and `database/` folders must remain hidden for security.

### Using Localhost (XAMPP / WAMP / Laragon)

**Laragon (Recommended):**
If you put the `MistikAI` folder inside `laragon/www`, Laragon will automatically create `http://mistikai.test`. However, you must point the document root to `public/`.
- Right click Laragon -> Apache -> `sites-enabled`
- Edit your auto-generated config and change `DocumentRoot` to `C:/laragon/www/MistikAI/public/`.
- Restart Apache.

**XAMPP / WAMP:**
If you place the project in `htdocs` or `www` without configuring a virtual host, you will access the site via `http://localhost/MistikAI/public/`.
For a professional setup, create an Apache Virtual Host:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/MistikAI/public"
    ServerName mistikai.local
    <Directory "C:/xampp/htdocs/MistikAI/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Using Nginx (Production)

```nginx
server {
    listen 80;
    server_name yourdomain.edu.tr;
    root /var/www/MistikAI/public;
    index index.php;
    
    # Forward all chat API requests and frontend routes to index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 5. Verify Your Installation

1. Open your browser and go to your configured URL (e.g., `http://mistikai.local/` or `http://localhost/MistikAI/public/`). You should see the chat interface.
2. Type **"merhaba"**. The bot should respond immediately.
3. Type **"yemek menüsü"**. It should trigger the `yemekhane` module.

### Accessing the Admin Panel
1. Navigate to the admin path: `http://your-url/admin`
2. **Default Login:**
   - Username: `admin`
   - Password: `admin123`
3. **Security Warning:** Go to settings and change this password immediately!

## 6. Optional: Setting up the BERT Model

MistikAI will function perfectly fine without the BERT model (it will rely on Rule-Based routing + Groq LLM). However, if you want the robust 3-layer architecture:

1. Install Python 3.9+
2. Navigate to the `bert-model/` directory.
3. Install requirements: `pip install -r requirements.txt`
4. Train the model: `python train.py`
5. Start the API server: `python serve.py` (Runs on port 5000)

Make sure `BERT_API_URL` in `config/db.php` points to `http://127.0.0.1:5000/niyet-algila`.

## 7. Troubleshooting

* **Blank Screen / 500 Error:** Check your PHP error logs. Make sure PHP 8.0+ is running and PDO extensions are enabled.
* **Chatbot doesn't reply:** Ensure your `config/db.php` credentials are correct. Check the Network tab in your browser's Developer Tools (F12) to see what `/api.php` is returning.
* **No natural conversation:** Ensure you have added a valid Groq API key to `db.php`.
* **Database Connection Failed:** Make sure MySQL is running and the user privileges are correctly set.
