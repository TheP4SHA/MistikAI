#!/usr/bin/env python3
"""MistikAI - BERT Intent Recognition API Server
Serves the trained model as a REST API.
"""
import json
import torch
from flask import Flask, request, jsonify
from transformers import BertTokenizer, BertForSequenceClassification

app = Flask(__name__)

# Load model and tokenizer
tokenizer = BertTokenizer.from_pretrained('./model')
model = BertForSequenceClassification.from_pretrained('./model')
model.eval()

# Load label mapping
with open('label_map.json', 'r', encoding='utf-8') as f:
    label_map = json.load(f)

@app.route('/niyet-algila', methods=['POST'])
def detect_intent():
    data = request.get_json()
    text = data.get('metin', '')
    
    if not text:
        return jsonify({'niyet': 'bilinmiyor', 'eminlik': 0.0})
    
    inputs = tokenizer(text, return_tensors='pt', truncation=True, padding=True, max_length=64)
    
    with torch.no_grad():
        outputs = model(**inputs)
        probs = torch.softmax(outputs.logits, dim=-1)
        confidence, predicted = torch.max(probs, dim=-1)
    
    intent = label_map.get(str(predicted.item()), 'bilinmiyor')
    conf = round(confidence.item(), 4)
    
    # Low confidence threshold
    if conf < 0.4:
        intent = 'bilinmiyor'
    
    return jsonify({'niyet': intent, 'eminlik': conf})

@app.route('/health', methods=['GET'])
def health():
    return jsonify({'status': 'ok', 'model': 'MistikAI-BERT'})

if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--port', type=int, default=8005)
    args = parser.parse_args()
    
    print(f"🚀 MistikAI BERT serving on port {args.port}")
    app.run(host='0.0.0.0', port=args.port)
