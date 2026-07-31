"""Pelari test sederhana. pytest tidak terpasang di Python 3.11 sistem, dan
memasangnya cuma untuk 9 test tidak sepadan.

Jalankan: python jalankan_test.py
"""
import traceback

import test_metrik as T

fn = [(n, getattr(T, n)) for n in sorted(dir(T)) if n.startswith("test_")]
ok = gagal = 0
for n, f in fn:
    try:
        f()
        ok += 1
        print(f"  OK    {n}")
    except Exception as e:
        gagal += 1
        print(f"  GAGAL {n}: {type(e).__name__}: {e}")
        traceback.print_exc(limit=2)

print(f"\n{ok} lolos, {gagal} gagal dari {len(fn)}")
raise SystemExit(1 if gagal else 0)
