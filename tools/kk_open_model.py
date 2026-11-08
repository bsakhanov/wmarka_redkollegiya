#!/usr/bin/env python3
"""Взять открытую модель букв и список форм у kk-redkollegiya — те же файлы, что у неё, с проверкой суммы.

  python3 tools/kk_open_model.py [v0.14.0]

Модель lm-kaznerd.json.gz — приложение к релизу kk-redkollegiya (проверка по её lm-kaznerd.json.gz.sha256),
список форм — data/kk_forms_kaznerd.txt той же метки. Обе вещи kk-redkollegiya строит из обучающей части KazNERD
(ISSAI, CC BY 4.0) своим tests/bench/train_kaznerd.py; здесь они не пересобираются, чтобы эталон был один.
Our product uses ISSAI KazNERD (https://aclanthology.org/2022.lrec-1.44), available under CC BY 4.0.
"""
import hashlib
import os
import sys
import urllib.request

TAG = sys.argv[1] if len(sys.argv) > 1 else "v0.14.0"
REPO = "https://github.com/bsakhanov/kk-redkollegiya"
DATA = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "component", "administrator", "data")


def get(url):
    with urllib.request.urlopen(url, timeout=60) as r:
        return r.read()


model = get(f"{REPO}/releases/download/{TAG}/lm-kaznerd.json.gz")
want = get(f"{REPO}/releases/download/{TAG}/lm-kaznerd.json.gz.sha256").decode().split()[0]
got = hashlib.sha256(model).hexdigest()
if got != want:
    sys.exit(f"сумма модели не сошлась: {got} вместо {want}")
forms = get(f"https://raw.githubusercontent.com/bsakhanov/kk-redkollegiya/{TAG}/data/kk_forms_kaznerd.txt")
open(os.path.join(DATA, "kk-lm-kaznerd.json.gz"), "wb").write(model)
open(os.path.join(DATA, "kk-forms-kaznerd.txt"), "wb").write(forms)
print(f"{TAG}: модель {len(model) // 1024} КБ, sha256 {got[:16]}…; форм {sum(1 for l in forms.decode().splitlines() if l and not l.startswith('#'))}")
