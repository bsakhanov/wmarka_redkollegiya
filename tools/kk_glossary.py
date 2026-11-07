#!/usr/bin/env python3
"""Пересобрать component/administrator/data/kk-glossary.json из glossary.yaml казахской Редколлегии.

  python3 tools/kk_glossary.py …/kk-redkollegiya/data/glossary.yaml [версия]
"""
import json
import os
import sys

import yaml

src = sys.argv[1]
ver = sys.argv[2] if len(sys.argv) > 2 else ""
g = yaml.safe_load(open(src, encoding="utf-8"))
out = {
    "source": f"kk-redkollegiya {ver}, data/glossary.yaml (github.com/bsakhanov/kk-redkollegiya), GPL-3.0-or-later".replace("  ", " "),
    "note": "Глоссарий-пример казахской Редколлегии: узус корпоративного корпуса нефтегазовой компании (около 840 тыс. слов). Пересобрать: tools/kk_glossary.py.",
    "version": g.get("version"),
    "abbreviations": g.get("abbreviations", []),
    "entries": g.get("entries", []),
}
dst = os.path.join(os.path.dirname(__file__), "..", "component", "administrator", "data", "kk-glossary.json")
json.dump(out, open(dst, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print(f"{dst}: записей {len(out['entries'])}, сокращений {len(out['abbreviations'])}")
