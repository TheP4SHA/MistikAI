# BERT Intent Recognition Model

## Overview

MistikAI uses a custom-trained BERT model for Turkish intent classification. This model serves as **Layer 2** in the hybrid architecture — it's called when the Rule-Based Router produces ambiguous results.

## Training Data

Training data is extracted from the `bot_anahtar_kelimeler` and `bot_moduller` database tables using the `extract_dataset.py` script.

### Data Format

```json
{
  "niyetler": [
    {
      "etiket": "yemekhane",
      "ornekler": ["yemek", "menü", "bugün yemekte ne var", ...]
    },
    {
      "etiket": "ders_programi",  
      "ornekler": ["ders", "program", "bugün dersim var mı", ...]
    }
  ]
}
```

## Scripts

### `extract_dataset.py`
Extracts training data from the MySQL database and outputs a JSON dataset.

### `train.py`
Fine-tunes a Turkish BERT model (e.g., `dbmdz/bert-base-turkish-cased`) on the extracted intent dataset.

### `serve.py`  
Serves the trained model as a REST API using Flask/FastAPI.

**API Endpoint:** `POST /niyet-algila`

**Request:**
```json
{"metin": "yarın yemekte ne var"}
```

**Response:**
```json
{"niyet": "yemekhane", "eminlik": 0.94}
```

## Requirements

```
torch>=2.0
transformers>=4.30
flask>=3.0
mysql-connector-python>=8.0
scikit-learn>=1.3
```

## Quick Start

```bash
# 1. Extract training data
python extract_dataset.py

# 2. Train the model
python train.py --epochs 10 --model dbmdz/bert-base-turkish-cased

# 3. Serve the API
python serve.py --port 8005
```

> **Note:** The BERT layer is optional. MistikAI works without it — the Rule-Based Router and LLM layers handle most queries. BERT improves accuracy when synonym ambiguity is high.
