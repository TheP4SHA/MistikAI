#!/usr/bin/env python3
"""MistikAI - BERT Intent Classifier Training Script
Fine-tunes a Turkish BERT model for campus intent classification.
"""
import json
import argparse
import numpy as np
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import LabelEncoder
from transformers import (
    BertTokenizer, BertForSequenceClassification,
    Trainer, TrainingArguments
)
import torch
from torch.utils.data import Dataset

class IntentDataset(Dataset):
    def __init__(self, texts, labels, tokenizer, max_len=64):
        self.encodings = tokenizer(texts, truncation=True, padding=True, max_length=max_len)
        self.labels = labels
    
    def __getitem__(self, idx):
        item = {k: torch.tensor(v[idx]) for k, v in self.encodings.items()}
        item['labels'] = torch.tensor(self.labels[idx])
        return item
    
    def __len__(self):
        return len(self.labels)

def main(args):
    # Load dataset
    with open('dataset.json', 'r', encoding='utf-8') as f:
        data = json.load(f)
    
    texts, labels = [], []
    for intent in data['niyetler']:
        for example in intent['ornekler']:
            texts.append(example)
            labels.append(intent['etiket'])
    
    # Encode labels
    le = LabelEncoder()
    encoded_labels = le.fit_transform(labels)
    
    # Save label mapping
    label_map = {int(i): label for i, label in enumerate(le.classes_)}
    with open('label_map.json', 'w', encoding='utf-8') as f:
        json.dump(label_map, f, ensure_ascii=False, indent=2)
    
    # Split
    train_texts, val_texts, train_labels, val_labels = train_test_split(
        texts, encoded_labels.tolist(), test_size=0.2, random_state=42, stratify=encoded_labels
    )
    
    # Tokenize
    tokenizer = BertTokenizer.from_pretrained(args.model)
    train_dataset = IntentDataset(train_texts, train_labels, tokenizer)
    val_dataset = IntentDataset(val_texts, val_labels, tokenizer)
    
    # Model
    model = BertForSequenceClassification.from_pretrained(
        args.model, num_labels=len(le.classes_)
    )
    
    # Training
    training_args = TrainingArguments(
        output_dir='./results',
        num_train_epochs=args.epochs,
        per_device_train_batch_size=16,
        per_device_eval_batch_size=16,
        warmup_steps=100,
        weight_decay=0.01,
        evaluation_strategy='epoch',
        save_strategy='epoch',
        load_best_model_at_end=True,
        logging_dir='./logs'
    )
    
    trainer = Trainer(
        model=model, args=training_args,
        train_dataset=train_dataset, eval_dataset=val_dataset
    )
    
    trainer.train()
    
    # Save
    model.save_pretrained('./model')
    tokenizer.save_pretrained('./model')
    print(f"\n✅ Model trained and saved to ./model/")
    print(f"📊 {len(le.classes_)} intent classes: {list(le.classes_)}")

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--model', default='dbmdz/bert-base-turkish-cased')
    parser.add_argument('--epochs', type=int, default=10)
    main(parser.parse_args())
