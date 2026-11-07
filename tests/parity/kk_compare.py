#!/usr/bin/env python3
"""Сверка казахского слоя с эталоном: kk_lint (места, правки, коды, уровни), числа и латиница kk_parity, находки сверки.

  KK_REDKOLLEGIYA=…/kk-redkollegiya python3 tests/parity/kk_compare.py [файлы и папки с казахским текстом]

Без аргументов — документы самой kk-redkollegiya: казахские для kk_lint, пары «русский — казахский» для kk_parity.
"""
import glob
import json
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
KK = os.environ["KK_REDKOLLEGIYA"]
GL = os.path.join(HERE, "..", "..", "component", "administrator", "data", "kk-glossary.json")


def run(cmd, req):
    r = subprocess.run(cmd, input=json.dumps(req, ensure_ascii=False), capture_output=True, text=True)
    if r.returncode:
        sys.exit(r.stderr[-3000:])
    return json.loads(r.stdout)


def both(req):
    return (run([sys.executable, os.path.join(HERE, "kk_reference.py")], req), run(["php", os.path.join(HERE, "kk_run.php")], req))


def texts_from(paths):
    out = []
    for p in paths:
        files = sorted(glob.glob(os.path.join(p, "**", "*.*"), recursive=True)) if os.path.isdir(p) else [p]
        for f in files:
            if f.endswith((".md", ".txt")):
                t = open(f, encoding="utf-8", errors="replace").read()
                out += [t] + [x for x in re.split(r"\n\s*\n", t) if x.strip()]
    return out


def main():
    kk_docs = [os.path.join(KK, x) for x in ("README.kk.md", "tests/sample_kk.md")] + glob.glob(os.path.join(KK, "docs", "*.kk.md"))
    texts = texts_from(kk_docs + sys.argv[1:])
    src = open(os.path.join(KK, "tools", "kk_lint.py"), encoding="utf-8").read()
    texts += [eval(x) for x in re.findall(r"lint\((\"(?:[^\"\\]|\\.)*\")", src)]
    bad = total = 0
    for i in range(0, len(texts), 300):
        chunk = texts[i:i + 300]
        a, b = both({"mode": "lint", "glossary": GL, "texts": chunk})
        for t, x, y in zip(chunk, a, b):
            total += 1
            if x != y:
                bad += 1
                if bad <= 8:
                    sx, sy = {tuple(map(str, s)) for s in x}, {tuple(map(str, s)) for s in y}
                    print("kk_lint расхождение:", ascii(t[:80]), "\n   только эталон:", list(sx - sy)[:4], "\n   только php:   ", list(sy - sx)[:4])
    ru_docs = [os.path.join(KK, x) for x in ("README.md",)] + [f for f in glob.glob(os.path.join(KK, "docs", "*.md")) if not f.endswith((".kk.md", ".en.md"))]
    nums = texts_from(ru_docs + kk_docs)
    a, b = both({"mode": "numbers", "texts": nums})
    for t, x, y in zip(nums, a, b):
        total += 1
        if x != y:
            bad += 1
            if bad <= 16:
                print("числа расхождение:", ascii(t[:80]), "\n   эталон:", x, "\n   php:   ", y)
    pairs = []
    for ru in ru_docs:
        kk = ru[:-3] + ".kk.md"
        if os.path.exists(kk):
            pairs.append([open(ru, encoding="utf-8").read(), open(kk, encoding="utf-8").read()])
    pairs += [["Объём вырос на 15,3% до 543,5 млн юаней. Joomla и KPI.\n\nВ 2026 году выпущено 1 234,5 тыс. тонн.",
               "Көлем 15,8%-ға өсіп, 543,5 млн юань болды. Joomla.\n\n2026 жылы 1234,5 мың тонна шығарылды."],
              ["Один.\n\nДва 5.", "Бір."],
              ["Проект запущен 12 декабря 2025 года, IPO в 2026.", "Жоба 2025 жылғы 12 желтоқсанда іске қосылды, ІРО 2026 жылы."]]
    a, b = both({"mode": "parity", "glossary": GL, "pairs": pairs})
    for p, x, y in zip(pairs, a, b):
        total += 1
        if x != y:
            bad += 1
            print("сверка расхождение:", ascii(p[1][:60]), x, y)
    print(f"сверка казахского слоя: {total - bad} из {total} совпали")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
