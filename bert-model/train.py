#!/usr/bin/env python3
"""MistikAI - BERT intent classifier training script.

Fine-tunes a Turkish BERT model on dataset.json (see extract_dataset.py) and
writes the model to ./model and the id -> label mapping to ./label_map.json.

    python train.py --epochs 10 --model dbmdz/bert-base-turkish-cased
"""
import argparse
import inspect
import json
import random
import sys
from collections import Counter, defaultdict
from pathlib import Path

import numpy as np
import torch
from sklearn.metrics import accuracy_score, classification_report, f1_score
from torch.utils.data import Dataset
from transformers import (
    AutoModelForSequenceClassification,
    AutoTokenizer,
    DataCollatorWithPadding,
    Trainer,
    TrainingArguments,
    set_seed,
)

BASE = Path(__file__).resolve().parent


class IntentDataset(Dataset):
    def __init__(self, texts, labels, tokenizer, max_len):
        # No padding here: DataCollatorWithPadding pads each batch to its own longest item.
        self.encodings = tokenizer(texts, truncation=True, max_length=max_len)
        self.labels = labels

    def __getitem__(self, idx):
        item = {k: torch.tensor(v[idx]) for k, v in self.encodings.items()}
        item['labels'] = torch.tensor(self.labels[idx])
        return item

    def __len__(self):
        return len(self.labels)


def load_dataset(path):
    with open(path, 'r', encoding='utf-8') as f:
        data = json.load(f)

    seen = {}
    conflicts = set()
    for intent in data['niyetler']:
        label = intent['etiket']
        for example in intent['ornekler']:
            text = str(example).strip()
            if not text:
                continue
            if text in seen and seen[text] != label:
                conflicts.add(text)
            seen.setdefault(text, label)

    if conflicts:
        print(f"! {len(conflicts)} example(s) appear under more than one intent and were dropped, e.g. {sorted(conflicts)[:3]}")
    pairs = [(t, l) for t, l in seen.items() if t not in conflicts]
    return [t for t, _ in pairs], [l for _, l in pairs]


def stratified_split(labels, val_ratio, seed):
    """Per-class split so every intent is represented in both sets.

    Classes with fewer than 2 examples stay in the training set only.
    """
    rng = random.Random(seed)
    by_class = defaultdict(list)
    for i, label in enumerate(labels):
        by_class[label].append(i)

    train_idx, val_idx = [], []
    for label in sorted(by_class):
        idx = by_class[label]
        rng.shuffle(idx)
        if len(idx) < 2 or val_ratio <= 0:
            if len(idx) < 2:
                print(f"! intent '{label}' has only {len(idx)} example(s); it is not validated")
            train_idx += idx
            continue
        n_val = min(len(idx) - 1, max(1, round(len(idx) * val_ratio)))
        val_idx += idx[:n_val]
        train_idx += idx[n_val:]
    rng.shuffle(train_idx)
    return train_idx, val_idx


def training_arguments(args, has_eval):
    """Build TrainingArguments across transformers versions.

    `evaluation_strategy` was renamed to `eval_strategy` (4.41) and later removed,
    so pick whichever name this installed version accepts.
    """
    accepted = inspect.signature(TrainingArguments.__init__).parameters
    strategy = 'epoch' if has_eval else 'no'
    kwargs = dict(
        output_dir=str(BASE / 'results'),
        num_train_epochs=args.epochs,
        learning_rate=args.lr,
        per_device_train_batch_size=args.batch_size,
        per_device_eval_batch_size=args.batch_size,
        warmup_ratio=0.1,
        weight_decay=0.01,
        save_strategy=strategy,
        save_total_limit=2,
        load_best_model_at_end=has_eval,
        logging_steps=10,
        seed=args.seed,
        report_to='none',
    )
    if has_eval:
        kwargs['metric_for_best_model'] = 'f1_macro'
        kwargs['greater_is_better'] = True
    if 'eval_strategy' in accepted:
        kwargs['eval_strategy'] = strategy
    else:
        kwargs['evaluation_strategy'] = strategy
    if 'logging_dir' in accepted:
        kwargs['logging_dir'] = str(BASE / 'logs')
    return TrainingArguments(**kwargs)


def compute_metrics(eval_pred):
    logits, labels = eval_pred
    preds = np.argmax(logits, axis=-1)
    return {
        'accuracy': accuracy_score(labels, preds),
        'f1_macro': f1_score(labels, preds, average='macro', zero_division=0),
    }


def main(args):
    set_seed(args.seed)
    random.seed(args.seed)
    np.random.seed(args.seed)

    texts, labels = load_dataset(args.dataset)
    if not texts:
        sys.exit("dataset.json is empty - run extract_dataset.py first")

    classes = sorted(set(labels))
    if len(classes) < 2:
        sys.exit("At least two intents are needed to train a classifier")
    if 'kapsam_disi' not in classes:
        print("! no 'kapsam_disi' (out-of-scope) class; every message will be forced into a campus intent")

    label2id = {label: i for i, label in enumerate(classes)}
    id2label = {i: label for label, i in label2id.items()}
    y = [label2id[l] for l in labels]

    print("Examples per intent:")
    for label, n in sorted(Counter(labels).items()):
        print(f"  {label:<24} {n}")

    train_idx, val_idx = stratified_split(labels, args.val_ratio, args.seed)
    print(f"Train: {len(train_idx)}  Validation: {len(val_idx)}")

    tokenizer = AutoTokenizer.from_pretrained(args.model)
    train_ds = IntentDataset([texts[i] for i in train_idx], [y[i] for i in train_idx], tokenizer, args.max_len)
    val_ds = IntentDataset([texts[i] for i in val_idx], [y[i] for i in val_idx], tokenizer, args.max_len) if val_idx else None

    model = AutoModelForSequenceClassification.from_pretrained(
        args.model, num_labels=len(classes), id2label=id2label, label2id=label2id
    )

    trainer = Trainer(
        model=model,
        args=training_arguments(args, has_eval=val_ds is not None),
        train_dataset=train_ds,
        eval_dataset=val_ds,
        data_collator=DataCollatorWithPadding(tokenizer),
        compute_metrics=compute_metrics if val_ds is not None else None,
    )
    trainer.train()

    out = Path(args.output)
    trainer.save_model(str(out))
    tokenizer.save_pretrained(str(out))
    with open(args.label_map, 'w', encoding='utf-8') as f:
        json.dump({str(i): label for i, label in id2label.items()}, f, ensure_ascii=False, indent=2)

    if val_ds is not None:
        pred = trainer.predict(val_ds)
        y_pred = np.argmax(pred.predictions, axis=-1)
        print("\nValidation report (best checkpoint):")
        print(classification_report(
            pred.label_ids, y_pred,
            labels=list(range(len(classes))), target_names=classes, digits=3, zero_division=0,
        ))

    print(f"Model saved to {out}/ and label map to {args.label_map}")
    print(f"{len(classes)} intents: {classes}")


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Train the MistikAI intent classifier')
    parser.add_argument('--model', default='dbmdz/bert-base-turkish-cased', help='base model on the Hugging Face Hub')
    parser.add_argument('--dataset', default=str(BASE / 'dataset.json'))
    parser.add_argument('--output', default=str(BASE / 'model'), help='directory for the fine-tuned model')
    parser.add_argument('--label-map', default=str(BASE / 'label_map.json'))
    parser.add_argument('--epochs', type=int, default=10)
    parser.add_argument('--batch-size', type=int, default=16)
    parser.add_argument('--lr', type=float, default=3e-5)
    parser.add_argument('--max-len', type=int, default=64)
    parser.add_argument('--val-ratio', type=float, default=0.2)
    parser.add_argument('--seed', type=int, default=42)
    main(parser.parse_args())
