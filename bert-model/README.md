# BERT Intent Recognition Service

## Overview

The BERT service is **Layer 2** of MistikAI. PHP calls it only when the keyword router (Layer 1) finds no match or an ambiguous one. It classifies a message into one of the campus modules, or into `kapsam_disi` (out of scope) so that the message goes on to the LLM (Layer 3).

The layer is optional. Without it, MistikAI runs on keyword matching plus the LLM.

| File | Purpose |
|------|---------|
| `extract_dataset.py` | Builds `dataset.json` from the module keywords in the database |
| `kapsam_disi.txt` | ~80 non-campus Turkish sentences used as the `kapsam_disi` class |
| `train.py` | Fine-tunes a Turkish BERT model, writes `model/` and `label_map.json` |
| `serve.py` | REST API used by `src/BertClient.php` |

`dataset.json`, `label_map.json`, `model/`, `results/` and `logs/` are generated and ignored by git.

## Setup

```bash
cd bert-model
python3 -m venv .venv && . .venv/bin/activate

# CPU-only server: install the small CPU build of torch first
pip install torch==2.5.1 --index-url https://download.pytorch.org/whl/cpu
pip install -r requirements.txt
```

Python 3.9 to 3.12 is supported. Training a base-size model on a few thousand short sentences takes minutes on a GPU and noticeably longer on a CPU. Inference on a CPU is fast enough for a chatbot.

## 1. Build the dataset

```bash
export MISTIK_DB_HOST=127.0.0.1 MISTIK_DB_NAME=mistikai_db MISTIK_DB_USER=mistik
python extract_dataset.py          # asks for the password if MISTIK_DB_PASS is not set
```

The keywords alone are too few and too short to train on (the demo seed has about 23). The script therefore expands every keyword with Turkish templates:

- short keywords (`yemek`, `akademik takvim`): `X nedir`, `X ne zaman`, `X nerede`, `bugün X`, `yarın X var mı`, `X hakkında bilgi` and more;
- full questions (`bugün yemekte ne var`): light variations such as `acaba X`, `X?`;
- conversational modules (`--sade-moduller`, default `selamlama,argo_filtresi`): `X!`, `mistik X`, ...;
- every example also gets an ASCII-folded copy (`yarın` → `yarin`), because many users type that way.

Each class is capped (`--max-ornek`, default 400) to keep the classes balanced, and texts that end up under two intents are dropped. Use `--haric mod1,mod2` to leave modules out.

Templates teach the model which words matter, not good Turkish. The best data is real user questions that you checked by hand. Add them as keywords in the admin panel, or edit `dataset.json` before training.

### Out-of-scope class

`kapsam_disi.txt` holds one sentence per line (chit-chat, weather, football, maths, recipes ...). They become the `kapsam_disi` class, so the model can say "this is not a campus question" instead of forcing every message into the closest module. Add sentences that your users actually send.

## 2. Train

```bash
python train.py --epochs 10 --model dbmdz/bert-base-turkish-cased
```

| Option | Default | |
|--------|---------|-|
| `--model` | `dbmdz/bert-base-turkish-cased` | any Hugging Face sequence-classification base |
| `--epochs` | 10 | |
| `--batch-size` | 16 | |
| `--lr` | 3e-5 | |
| `--val-ratio` | 0.2 | stratified per intent |
| `--seed` | 42 | fixed for reproducible runs |

The script keeps the best epoch by macro F1, prints a per-class precision/recall report on the validation set and saves `model/` and `label_map.json`. Templated validation data is similar to the training data, so treat high scores as a sanity check, not as real-world accuracy.

## 3. Serve

```bash
export BERT_API_KEY="$(openssl rand -hex 32)"   # same value as $BERT_API_KEY in config/db.php
python serve.py                                   # http://127.0.0.1:8005
```

| Variable | Default | |
|----------|---------|-|
| `BERT_HOST` | `127.0.0.1` | bind address (`--host` overrides) |
| `BERT_PORT` | `8005` | port (`--port` overrides) |
| `BERT_API_KEY` | empty | when set, requests must send the same value in `X-Api-Key` |
| `BERT_ESIK` | `0.6` | confidence threshold (0-1) |
| `BERT_MAX_KARAKTER` | `500` | longer messages get HTTP 413 |
| `BERT_MODEL_DIR` | `./model` | |
| `BERT_LABEL_MAP` | `./label_map.json` | |
| `BERT_THREADS` | `4` | waitress worker threads |

In `config/db.php` set `$BERT_API_URL = 'http://127.0.0.1:8005/niyet-algila';` and `$BERT_API_KEY` to the same secret.

### API

`POST /niyet-algila` with header `X-Api-Key` (if configured):

```json
{"metin": "yarın yemekte ne var"}
```

```json
{"niyet": "yemekhane", "eminlik": 0.94}
```

When the top probability is below `BERT_ESIK`, or the top class is the out-of-scope class, the answer is `{"niyet": "kapsam_disi", "eminlik": p}` and PHP moves on to the LLM. Errors: `400` invalid JSON, `401` wrong or missing key, `413` message too long.

`GET /saglik` returns `{"durum": "ok", "model_yuklu": true, "esik": 0.6}` (`/health` is an alias).

### Production

`serve.py` uses [waitress](https://docs.pylonsproject.org/projects/waitress/) when it is installed (it is in `requirements.txt`) and falls back to the Flask development server otherwise (`--dev` forces it). Keep the service on `127.0.0.1` on the same machine as PHP. If it has to run on another host, set `BERT_API_KEY`, use a firewall rule and put TLS in front of it. Message texts are not logged by the service.

Example systemd unit (`/etc/systemd/system/mistik-bert.service`):

```ini
[Unit]
Description=MistikAI BERT intent service
After=network.target

[Service]
User=mistik
WorkingDirectory=/opt/MistikAI/bert-model
Environment=BERT_HOST=127.0.0.1
Environment=BERT_PORT=8005
EnvironmentFile=/etc/mistik-bert.env
ExecStart=/opt/MistikAI/bert-model/.venv/bin/python serve.py
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

Put `BERT_API_KEY=...` in `/etc/mistik-bert.env` (mode 600), then run `systemctl enable --now mistik-bert`.

## Alternative: multilingual embeddings + kNN

Fine-tuning needs a retrain every time a module or keyword is added. A lighter option is a sentence-embedding model such as `intfloat/multilingual-e5-small` (about 120 M parameters, handles Turkish):

1. Embed every example sentence once and store the vectors with their module.
2. For a new message, embed it and take the k nearest examples (cosine similarity).
3. Majority module wins; below a similarity threshold, answer `kapsam_disi`.

New intents only need new example sentences, with no retraining. The same vectors and service can later power retrieval-augmented answers (RAG) over regulations or announcements. The trade-off is that a fine-tuned classifier is usually more accurate when you have enough good labelled data. This repository does not include that alternative; it is a suggested direction.
