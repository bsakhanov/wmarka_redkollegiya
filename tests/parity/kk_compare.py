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
    nums = texts_from(ru_docs + kk_docs) + ["1 234,5 тыс. тонн", "1234,5 мың тонна", "1\u00a0234,5 тыс.", "1\u202f234,5 мың", "12 345,67",
        "1 234 567,8 млн", "1 085 тонн", "1\u00a0085 тонна", "1 234,5 и 6 7", "2026 123 тонн", "1\u2009234,5 тыс.", "1\u2007085"]
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
              ["Проект запущен 12 декабря 2025 года, IPO в 2026.", "Жоба 2025 жылғы 12 желтоқсанда іске қосылды, ІРО 2026 жылы."],
              ["В 2026 году выпущено 1 234,5 тыс. тонн.", "2026 жылы 1234,5 мың тонна шығарылды."],
              ["Выручка 12 345,67 млн, рост 1\u2009085 тонн, 1 234 567,8 млн.", "Түсім 12345,67 млн, өсім 1\u00a0085 тонна, 1234567,8 млн."]]
    a, b = both({"mode": "parity", "glossary": GL, "pairs": pairs})
    for p, x, y in zip(pairs, a, b):
        total += 1
        if x != y:
            bad += 1
            print("сверка расхождение:", ascii(p[1][:60]), x, y)
    # S01: подсказки восстановления букв — на чистом тексте и на тексте со снятыми казахскими буквами
    corrupt = {"ә": "а", "ғ": "г", "қ": "к", "ң": "н", "ө": "о", "ұ": "у", "ү": "у", "һ": "х", "і": "и",
               "Ә": "А", "Ғ": "Г", "Қ": "К", "Ң": "Н", "Ө": "О", "Ұ": "У", "Ү": "У", "Һ": "Х", "І": "И"}
    kk_par = [x for x in texts_from(kk_docs) if len(x) < 4000]
    rtexts = kk_par[:300] + ["".join(corrupt.get(c, c) for c in x) for x in kk_par[:300]]
    extra = os.environ.get("KAZNERD_TEST")
    if extra and os.path.exists(extra):
        lines = [l.strip() for l in open(extra, encoding="utf-8") if l.strip()][:600]
        rtexts += lines + ["".join(corrupt.get(c, c) for c in l) for l in lines]
    for i in range(0, len(rtexts), 200):
        chunk = rtexts[i:i + 200]
        a, b = both({"mode": "restore", "glossary": GL, "texts": chunk})
        for t, x, y in zip(chunk, a, b):
            total += 1
            if x != y:
                bad += 1
                if bad <= 20:
                    print("S01 расхождение:", ascii(t[:70]), "\n   эталон:", x[:5], "\n   php:   ", y[:5])
    print(f"сверка казахского слоя: {total - bad} из {total} совпали")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
