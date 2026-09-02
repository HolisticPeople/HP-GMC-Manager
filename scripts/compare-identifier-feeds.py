#!/usr/bin/env python3
"""Three-way, row-keyed comparison for HP-GMC identifier migrations."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
from pathlib import Path


IDENTIFIER_COLUMNS = {"mpn", "gtin", "identifier_exists"}


def load_feed(path: Path) -> tuple[list[str], dict[str, dict[str, str]]]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle, delimiter="\t")
        headers = list(reader.fieldnames or [])
        if "id" not in headers:
            raise ValueError(f"{path}: missing id column")
        rows: dict[str, dict[str, str]] = {}
        for row in reader:
            offer_id = row.get("id", "")
            if not offer_id or offer_id in rows:
                raise ValueError(f"{path}: blank or duplicate id {offer_id!r}")
            rows[offer_id] = {key: value or "" for key, value in row.items()}
    return headers, rows


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def compare(left: dict[str, dict[str, str]], right: dict[str, dict[str, str]]) -> dict:
    left_ids = set(left)
    right_ids = set(right)
    shared = sorted(left_ids & right_ids)
    column_counts: dict[str, int] = {}
    identifier_changes = []
    non_identifier_changes = []
    for offer_id in shared:
        columns = sorted(set(left[offer_id]) | set(right[offer_id]))
        for column in columns:
            before = left[offer_id].get(column, "")
            after = right[offer_id].get(column, "")
            if before == after:
                continue
            column_counts[column] = column_counts.get(column, 0) + 1
            change = {"id": offer_id, "column": column, "before": before, "after": after}
            if column in IDENTIFIER_COLUMNS:
                identifier_changes.append(change)
            else:
                non_identifier_changes.append(change)

    return {
        "left_count": len(left),
        "right_count": len(right),
        "shared_count": len(shared),
        "left_only_ids": sorted(left_ids - right_ids),
        "right_only_ids": sorted(right_ids - left_ids),
        "changed_cell_count_by_column": dict(sorted(column_counts.items())),
        "identifier_changes": identifier_changes,
        "non_identifier_changes": non_identifier_changes,
    }


def build_report(production: Path, staging_before: Path, staging_after: Path | None) -> dict:
    production_headers, production_rows = load_feed(production)
    before_headers, before_rows = load_feed(staging_before)
    report = {
        "schema": "hp-gmc-feed-comparison/v1",
        "inputs": {
            "production": {"path": str(production), "sha256": sha256(production)},
            "staging_before": {"path": str(staging_before), "sha256": sha256(staging_before)},
        },
        "header_parity_production_vs_staging_before": production_headers == before_headers,
        "production_vs_staging_before": compare(production_rows, before_rows),
    }
    if staging_after is not None:
        after_headers, after_rows = load_feed(staging_after)
        report["inputs"]["staging_after"] = {"path": str(staging_after), "sha256": sha256(staging_after)}
        report["header_parity_staging_before_vs_after"] = before_headers == after_headers
        report["staging_before_vs_after"] = compare(before_rows, after_rows)
        report["production_vs_staging_after"] = compare(production_rows, after_rows)
    return report


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--production", required=True, type=Path)
    parser.add_argument("--staging-before", required=True, type=Path)
    parser.add_argument("--staging-after", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    report = build_report(args.production, args.staging_before, args.staging_after)
    payload = json.dumps(report, indent=2, ensure_ascii=False) + "\n"
    if args.output:
        args.output.write_text(payload, encoding="utf-8")
    else:
        print(payload, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
