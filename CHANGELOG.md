# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## 1.1.0 — 2026-10-22

Existing installations: back up the database, run `database/migrations/2026-10-guvenlik.sql` once and add the new keys from `config/db.php.example` to `config/db.php`. See [Upgrading](README.md#upgrading-an-existing-installation).

### Security

- CSRF tokens on every admin form, in addition to the chat API.
- Allow-list HTML sanitizer for module and flow replies; scripts, event handlers and `javascript:` links are removed.
- LLM output is rendered as text (bold, lists and `https` links only), never as raw HTML.
- Content Security Policy headers.
- Session hardening: `HttpOnly`, `SameSite`, `Secure` on HTTPS, strict mode, new session ID at login.
- Admin login brute-force throttle per IP and username.
- No default admin account. The seed shipped an `admin` user whose documented password (`admin123`) did not match its hash, which was the publicly known hash of `password`. The migration deletes any account that still uses it.
- Admin roles: full (`tam`) and restricted (`kisitli`). Only full admins add admins; open self-registration is closed.
- The in-browser PHP editor and SQL terminal are disabled by default (`$ADMIN_KOD_EDITORU`) and limited to full admins.
- The content scraper only fetches hosts listed in `$SCRAPER_IZINLI_HOSTLAR` and refuses private or internal addresses (SSRF guard).
- `X-Forwarded-For` is trusted only from proxies listed in `$GUVENILIR_VEKILLER`.
- BERT service binds to `127.0.0.1` by default, supports an `X-Api-Key` shared secret (constant-time compare) and limits input length.
- CI runs a gitleaks secret scan.

### Fixed

- Rate limiting described in the docs did not exist. It is now enforced per minute (`$DAKIKA_ISTEK_SINIRI`) with a message length limit (`$MESAJ_MAX_UZUNLUK`).
- Turkish case handling (`İ`/`I`) in the router; ASCII-typed messages such as `yarin` now match `yarın`.
- `train.py`: `warmup_steps=100` exceeded the total number of training steps on small datasets; `evaluation_strategy` failed on newer transformers versions.
- Docs: BERT port is 8005 (not 5000), configuration uses variables (not `define()`), keyword counts and other claims corrected, the non-existent "change password in settings" removed.

### Changed

- Router: word-based matching with suffix tolerance (`yemekhanede` → `yemekhane`), one-letter typo tolerance for longer words, ASCII folding, and candidate buttons when two modules are too close to call.
- LLM layer: OpenAI-compatible tool calling restricted to the active modules, date-aware system prompt (`$OKUL_BILGISI`), configurable model with a fallback model (`$LLM_MODEL`, `$LLM_YEDEK_MODEL`), `$LLM_MAX_TOKENS`, retry on 429/5xx and a graceful message when the LLM is unavailable.
- BERT client and service: out-of-scope answers (`kapsam_disi`) and low-confidence predictions fall through to the LLM; threshold configurable (`BERT_ESIK`, default 0.6).
- Logs store a salted IP hash (`$IP_TUZU`) instead of the IP, and are deleted after `$LOG_SAKLAMA_GUN` days.
- `extract_dataset.py` reads database settings from environment variables and expands keywords with Turkish templates instead of using the bare keywords.
- `train.py`: fixed seed, stratified per-class split, `warmup_ratio`, best checkpoint by macro F1, per-class report, `label_map.json` and label names in the model config.
- `serve.py`: waitress in production with a Flask fallback; message texts are not logged.
- Python dependencies are pinned.

### Added

- First-run setup page `admin/kurulum.php`.
- `database/migrations/2026-10-guvenlik.sql` for existing installations (admin roles, login attempts, log layer and latency columns).
- Logs record which layer answered (`katman`) and the latency.
- New settings: `$BASE_PATH`, `$ADMIN_TEMIZ_URL`, `$ADMIN_KOD_EDITORU`, `$GUVENILIR_VEKILLER`, `$IP_TUZU`, `$LLM_MODEL`, `$LLM_YEDEK_MODEL`, `$LLM_MAX_TOKENS`, `$DAKIKA_ISTEK_SINIRI`, `$MESAJ_MAX_UZUNLUK`, `$LOG_SAKLAMA_GUN`, `$OKUL_BILGISI`, `$SCRAPER_IZINLI_HOSTLAR`, `$BERT_API_KEY`.
- PHP test runner (`php tests/run.php`).
- GitHub Actions CI: `php -l` on PHP 8.0 and 8.3, tests, Python byte-compile, gitleaks.
- BERT: out-of-scope class from `bert-model/kapsam_disi.txt`, `/saglik` health endpoint, systemd example, notes on an embeddings + kNN alternative.
- `public/assets/mistik.webp`: 160 px animated avatar (68 KB, the GIF is 1 MB).
- `SECURITY.md` and this changelog.

## 1.0.0 — 2026-07-30

- Initial public release: 3-layer engine (keyword router, BERT, Groq LLM), chat UI, admin panel, flow builder, modules.
