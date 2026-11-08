#!/usr/bin/env python3
"""Эталон для сверки казахского слоя: kk_lint.py (детекторы и S01 со словарём из списка форм), kk_parity.py из kk-redkollegiya.

Путь — KK_REDKOLLEGIYA. Вход: JSON {"mode": "lint"|"numbers"|"parity", "glossary": путь к JSON, "texts": [...], "pairs": [[ru, kk], ...]}.
"""
import json
import os
import sys

sys.path.insert(0, os.path.join(os.environ["KK_REDKOLLEGIYA"], "tools"))
import kk_lint  # noqa: E402
import kk_parity  # noqa: E402
import kk_restore  # noqa: E402

DATA = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "component", "administrator", "data")


req = json.load(sys.stdin)
gl = json.load(open(req["glossary"], encoding="utf-8")) if req.get("glossary") else {}
out = []
if req["mode"] == "lint":
    for t in req["texts"]:
        doc = kk_lint.Doc(t, "prose")
        spans = []
        for d in (kk_lint.d_hygiene, lambda doc, sp: kk_lint.d_glossary(doc, gl, sp), kk_lint.d_punct, kk_lint.d_lower_after_dot,
                  kk_lint.d_quotes, kk_lint.d_number_affix, kk_lint.d_currency):
            d(doc, spans)
        spans.sort(key=lambda s: (s.s, s.e))
        out.append([[s.s, s.e, s.edit, s.rule.split()[0], s.severity, s.action] for s in spans])
elif req["mode"] == "restore":
    import collections
    import re
    # эталон S01 — словарь kk-redkollegiya 0.14.0 без Hunspell: Dictionary(forms=…) с её же списком форм
    from kk_clean import Dictionary
    lm = kk_restore.CharLM.load(os.path.join(DATA, "kk-lm-kaznerd.json.gz"))
    d = Dictionary(forms=os.path.join(os.environ["KK_REDKOLLEGIYA"], "data", "kk_forms_kaznerd.txt"))
    glw = set()
    for e in gl.get("entries", []):
        if e.get("kk"):
            glw |= {w.lower() for w in re.findall(rf"[{kk_lint.CYRL}]+", e["kk"])}
    for t in req["texts"]:
        doc = kk_lint.Doc(t, "prose")
        spans = []
        kk_lint.d_restore(doc, d, lm, glw, False, spans, collections.Counter())
        out.append([[s.s, s.e, s.edit] for s in spans])
elif req["mode"] == "numbers":
    for t in req["texts"]:
        out.append([kk_parity.numbers(t), kk_parity.latin(t), kk_parity.latin(t, True)])
else:
    for ru, kk in req["pairs"]:
        out.append([[f["rule"].split()[0]] for f in kk_parity.compare(ru, kk, gl)])
json.dump(out, sys.stdout, ensure_ascii=False)
