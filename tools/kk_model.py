#!/usr/bin/env python3
"""Пересобрать модель букв S01 и список форм из открытого корпуса KazNERD (ISSAI, CC BY 4.0).

  KK_REDKOLLEGIYA=…/kk-redkollegiya python3 tools/kk_model.py [папка для кэша корпуса]

Обучение — kk_restore.py train на обучающей части (train); проверочные части (valid, test) в модель не входят и служат стендом.
Пишет component/administrator/data/kk-lm.json.gz и kk-forms.txt (формы, встреченные в train не реже двух раз).
Our product uses ISSAI KazNERD (https://aclanthology.org/2022.lrec-1.44), available under CC BY 4.0.
"""
import collections
import os
import subprocess
import sys
import tempfile

KK = os.environ["KK_REDKOLLEGIYA"]
sys.path.insert(0, os.path.join(KK, "tests", "bench"))
import kaznerd_bench as kb  # noqa: E402

cache = sys.argv[1] if len(sys.argv) > 1 else os.path.expanduser("~/.cache/kk-redkollegiya/kaznerd")
data = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "component", "administrator", "data")
train = list(kb.sentences(kb.fetch(cache, "train")))
tmp = os.path.join(tempfile.mkdtemp(), "train.txt")
open(tmp, "w", encoding="utf-8").write("\n".join(kb.detok(s) for s in train) + "\n")
subprocess.run([sys.executable, os.path.join(KK, "tools", "kk_restore.py"), "train", tmp, "-o", os.path.join(data, "kk-lm.json.gz")], check=True)
v = collections.Counter(w.lower() for s in train for w in s)
words = sorted(w for w, c in v.items() if c >= 2 and any(ch.isalpha() for ch in w))
with open(os.path.join(data, "kk-forms.txt"), "w", encoding="utf-8") as f:
    f.write("# kk-forms.txt — формы слов, встречающиеся не реже двух раз в обучающей части KazNERD (ISSAI, CC BY 4.0,\n")
    f.write("# https://github.com/IS2AI/KazNERD; https://aclanthology.org/2022.lrec-1.44). Нужны правилу S01 вместо Hunspell.\n")
    f.write("# Пересобрать: tools/kk_model.py. Our product uses ISSAI KazNERD, available under CC BY 4.0.\n")
    f.write("\n".join(words) + "\n")
print(f"форм: {len(words)}")
