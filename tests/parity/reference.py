#!/usr/bin/env python3
"""Эталонная сторона сверки: milchin.py (beaverbeard/milchin) и kk_typo.py (bsakhanov/kk-redkollegiya).

Пути — переменные MILCHIN (к milchin.py) и KK_REDKOLLEGIYA (к корню kk-redkollegiya).
Вход и выход — как у parity/run.php.
"""
import collections
import importlib.util
import json
import os
import sys


def load(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    mod = importlib.util.module_from_spec(spec)
    sys.path.insert(0, os.path.dirname(path))
    spec.loader.exec_module(mod)
    return mod


def main():
    req = json.load(sys.stdin)
    out = []
    if req["engine"] == "kk":
        kk = load(os.path.join(os.environ["KK_REDKOLLEGIYA"], "tools", "kk_typo.py"), "kk_typo")
        fl = req["flags"]
        for t in req["texts"]:
            text, cnt = kk.fix(t, dash=fl.get("dash", "em"), percent_space=fl.get("percent_space", False),
                               numero_space=fl.get("numero_space", False), degree_space=fl.get("degree_space", False))
            out.append({"text": text, "stats": dict(cnt)})
    else:
        m = load(os.environ["MILCHIN"], "milchin")
        fl = req["flags"]
        for t in req["texts"]:
            text, c = m.proofread(t, percent_space=fl.get("percent_space", False), initials_space=fl.get("initials_space", True),
                                  ruble=fl.get("ruble", False), iso_dates=fl.get("iso_dates", False))
            out.append({"text": text, "stats": c.counts})
    json.dump(out, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
