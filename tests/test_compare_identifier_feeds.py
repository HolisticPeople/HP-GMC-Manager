#!/usr/bin/env python3

import importlib.util
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("feed_compare", ROOT / "scripts/compare-identifier-feeds.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(MODULE)


with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    production = root / "production.tsv"
    before = root / "before.tsv"
    after = root / "after.tsv"
    production.write_text("id\ttitle\tmpn\tgtin\tidentifier_exists\nA\tOne\tSKU-A\t\tTRUE\nB\tTwo\t\tGTIN-B\t\n", encoding="utf-8")
    before.write_text("id\ttitle\tmpn\tgtin\tidentifier_exists\nA\tOne\t\t\t\nC\tThree\t\t\t\n", encoding="utf-8")
    after.write_text("id\ttitle\tmpn\tgtin\tidentifier_exists\nA\tOne\tMFR-A\t\t\nC\tThree\t\t\t\n", encoding="utf-8")
    report = MODULE.build_report(production, before, after)

    assert report["production_vs_staging_before"]["shared_count"] == 1
    assert report["production_vs_staging_before"]["left_only_ids"] == ["B"]
    assert report["production_vs_staging_before"]["right_only_ids"] == ["C"]
    delta = report["staging_before_vs_after"]
    assert delta["changed_cell_count_by_column"] == {"mpn": 1}
    assert delta["non_identifier_changes"] == []

print("ALL PASS")
