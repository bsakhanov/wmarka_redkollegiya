#!/usr/bin/env python3
"""Сверка PHP-переноса с эталоном: одни и те же тексты через milchin.py / kk_typo.py и через run.php.

  MILCHIN=…/milchin.py KK_REDKOLLEGIYA=…/kk-redkollegiya python3 tests/parity/compare.py [файлы и папки…]

Без аргументов берутся встроенные примеры и самопроверки обоих эталонов. Файлы .md/.txt — целиком и по абзацам;
кириллица с казахскими буквами идёт в kk, остальная — в ru. Выход 1, если хоть один текст разошёлся.
"""
import glob
import json
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
KK = re.compile("[әғқңөұүһіӘҒҚҢӨҰҮҺІ]")

FLAGS = {
    "milchin": [{}, {"percent_space": True, "initials_space": False}, {"ruble": True, "iso_dates": True}],
    "kk": [{}, {"percent_space": True, "numero_space": True, "degree_space": True}, {"dash": "keep"}],
}


def builtin():
    sys.path.insert(0, os.path.dirname(os.environ["MILCHIN"]))
    import importlib.util
    spec = importlib.util.spec_from_file_location("milchin", os.environ["MILCHIN"])
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    ru = [src for _, src, _, _ in m.SELFTESTS]
    src = open(os.path.join(os.environ["KK_REDKOLLEGIYA"], "tools", "kk_typo.py"), encoding="utf-8").read()
    kk = [eval(x) for x in re.findall(r"assert f\((\"(?:[^\"\\]|\\.)*\"|'(?:[^'\\]|\\.)*')", src)]
    return ru, kk


def collect(paths):
    ru, kk = [], []
    files = []
    for p in paths:
        files += sorted(glob.glob(os.path.join(p, "**", "*.*"), recursive=True)) if os.path.isdir(p) else [p]
    for f in files:
        if not f.endswith((".md", ".txt")):
            continue
        text = open(f, encoding="utf-8", errors="replace").read()
        parts = [text] + [x for x in re.split(r"\n\s*\n", text) if x.strip()]
        for t in parts:
            (kk if len(KK.findall(t)) >= 3 else ru).append(t)
    return ru, kk


def run(cmd, engine, flags, texts, env=None):
    req = json.dumps({"engine": engine, "flags": flags, "texts": texts}, ensure_ascii=False)
    r = subprocess.run(cmd, input=req, capture_output=True, text=True, env=env)
    if r.returncode:
        sys.exit(r.stderr)
    return json.loads(r.stdout)


def first_diff(a, b):
    for i, (x, y) in enumerate(zip(a, b)):
        if x != y:
            return i
    return min(len(a), len(b))


def main():
    ru, kk = builtin()
    if len(sys.argv) > 1:
        r2, k2 = collect(sys.argv[1:])
        ru, kk = ru + r2, kk + k2
    bad = total = 0
    for engine, texts in (("milchin", ru), ("kk", kk)):
        for flags in FLAGS[engine]:
            for i in range(0, len(texts), 400):
                chunk = texts[i:i + 400]
                ref = run([sys.executable, os.path.join(HERE, "reference.py")], engine, flags, chunk, dict(os.environ))
                php = run(["php", os.path.join(HERE, "run.php")], engine, flags, chunk)
                for t, a, b in zip(chunk, ref, php):
                    total += 1
                    sa = {k: v for k, v in a["stats"].items() if v}
                    sb = {k: v for k, v in (b["stats"] or {}).items() if v}
                    if a["text"] != b["text"] or sa != sb:
                        bad += 1
                        if bad <= 12:
                            d = first_diff(a["text"], b["text"])
                            print(f"[{engine} {flags}] расхождение: эталон {sa} / php {sb}")
                            print("   вход:   ", ascii(t[max(0, d - 60):d + 60]))
                            print("   эталон: ", ascii(a["text"][max(0, d - 60):d + 60]))
                            print("   php:    ", ascii(b["text"][max(0, d - 60):d + 60]))
    print(f"сверка: {total - bad} из {total} совпали")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
