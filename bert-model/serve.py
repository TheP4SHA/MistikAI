#!/usr/bin/env python3
"""MistikAI - BERT intent recognition API.

    POST /niyet-algila   {"metin": "..."}  ->  {"niyet": "...", "eminlik": 0.93}
    GET  /saglik         health check

Configuration (environment variables, CLI flags override host/port):
    BERT_HOST          bind address               (default 127.0.0.1)
    BERT_PORT          port                       (default 8005)
    BERT_API_KEY       if set, X-Api-Key header is required
    BERT_ESIK          confidence threshold 0-1   (default 0.6)
    BERT_MAX_KARAKTER  max characters per message (default 500)
    BERT_MODEL_DIR     model directory            (default ./model)
    BERT_LABEL_MAP     label map file             (default ./label_map.json)
    BERT_THREADS       waitress worker threads    (default 4)

Message texts are never logged.
"""
import argparse
import hmac
import json
import os
import sys
from pathlib import Path

from flask import Flask, jsonify, request

BASE = Path(__file__).resolve().parent
KAPSAM_DISI = 'kapsam_disi'


def _env_float(name, default):
    try:
        return float(os.environ.get(name, default))
    except ValueError:
        sys.exit(f"{name} must be a number")


def _env_int(name, default):
    try:
        return int(os.environ.get(name, default))
    except ValueError:
        sys.exit(f"{name} must be an integer")


API_KEY = os.environ.get('BERT_API_KEY', '')
ESIK = _env_float('BERT_ESIK', 0.6)
MAX_KARAKTER = _env_int('BERT_MAX_KARAKTER', 500)
MODEL_DIR = os.environ.get('BERT_MODEL_DIR', str(BASE / 'model'))
LABEL_MAP = os.environ.get('BERT_LABEL_MAP', str(BASE / 'label_map.json'))
MAX_TOKEN = 64

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 16 * 1024  # request body limit (bytes)

_torch = None
_tokenizer = None
_model = None
_labels = {}


def load_model():
    """Load tokenizer, model and label map once at start-up."""
    global _torch, _tokenizer, _model, _labels
    import torch
    from transformers import AutoModelForSequenceClassification, AutoTokenizer

    _torch = torch
    _tokenizer = AutoTokenizer.from_pretrained(MODEL_DIR)
    _model = AutoModelForSequenceClassification.from_pretrained(MODEL_DIR)
    _model.eval()

    if os.path.exists(LABEL_MAP):
        with open(LABEL_MAP, 'r', encoding='utf-8') as f:
            _labels = {int(k): v for k, v in json.load(f).items()}
    else:
        _labels = {int(k): v for k, v in _model.config.id2label.items()}
    if KAPSAM_DISI not in _labels.values():
        print(f"! model has no '{KAPSAM_DISI}' class; only the threshold filters out-of-scope messages")


def _authorised():
    if not API_KEY:
        return True
    sent = request.headers.get('X-Api-Key', '')
    return hmac.compare_digest(sent.encode('utf-8'), API_KEY.encode('utf-8'))


def _error(message, status):
    return jsonify({'hata': message}), status


@app.route('/niyet-algila', methods=['POST'])
def detect_intent():
    if not _authorised():
        return _error('yetkisiz', 401)

    data = request.get_json(silent=True)
    if not isinstance(data, dict) or not isinstance(data.get('metin', ''), str):
        return _error('gecersiz istek: {"metin": "..."} bekleniyor', 400)

    text = data.get('metin', '').strip()
    if not text:
        return jsonify({'niyet': KAPSAM_DISI, 'eminlik': 0.0})
    if len(text) > MAX_KARAKTER:
        return _error(f'metin en fazla {MAX_KARAKTER} karakter olabilir', 413)

    inputs = _tokenizer(text, return_tensors='pt', truncation=True, max_length=MAX_TOKEN)
    with _torch.inference_mode():
        probs = _torch.softmax(_model(**inputs).logits, dim=-1)[0]
    confidence, index = _torch.max(probs, dim=-1)

    conf = round(float(confidence.item()), 4)
    intent = _labels.get(int(index.item()), KAPSAM_DISI)
    if conf < ESIK:
        intent = KAPSAM_DISI

    return jsonify({'niyet': intent, 'eminlik': conf})


@app.route('/saglik', methods=['GET'])
@app.route('/health', methods=['GET'])
def health():
    return jsonify({'durum': 'ok', 'model_yuklu': _model is not None, 'esik': ESIK})


@app.errorhandler(413)
def too_large(_e):
    return _error('istek cok buyuk', 413)


def main():
    parser = argparse.ArgumentParser(description='MistikAI BERT intent API')
    parser.add_argument('--host', default=os.environ.get('BERT_HOST', '127.0.0.1'))
    parser.add_argument('--port', type=int, default=_env_int('BERT_PORT', 8005))
    parser.add_argument('--dev', action='store_true', help='use the Flask development server')
    args = parser.parse_args()

    if not 0.0 <= ESIK <= 1.0:
        sys.exit('BERT_ESIK must be between 0 and 1')
    if args.host not in ('127.0.0.1', 'localhost', '::1') and not API_KEY:
        print(f"! listening on {args.host} without BERT_API_KEY: anyone who can reach this port can use the model")

    load_model()
    print(f"MistikAI BERT: {len(_labels)} intents, threshold {ESIK}, http://{args.host}:{args.port}")

    if not args.dev:
        try:
            from waitress import serve
        except ImportError:
            print("! waitress is not installed; falling back to the Flask development server")
        else:
            serve(app, host=args.host, port=args.port, threads=_env_int('BERT_THREADS', 4))
            return

    app.run(host=args.host, port=args.port)


if __name__ == '__main__':
    main()
