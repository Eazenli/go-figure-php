"""A first example test: catalog_draft.py on a tiny hand-written JSON-stat response.

It checks the two JSON-stat traps (the index sent as a list, a missing cell) and
the coverage report. Run with: make data-test.
"""
import json
import subprocess
import sys
from pathlib import Path

SCRIPT = Path(__file__).resolve().parent.parent / "catalog_draft.py"


def jsonstat(tmp_path):
    # 3 places x 3 years, flattened row by row; FR has no value for 2024.
    data = {
        "updated": "2026-10-12T23:00:00+0200",
        "id": ["unit", "geo", "time"],
        "size": [1, 3, 3],
        "dimension": {
            "unit": {"category": {"index": {"NR": 0}, "label": {"NR": "Number"}}},
            "geo": {"category": {"index": ["EU27_2020", "FR", "UK"]}},  # index sent as a list
            "time": {"category": {"index": {"2015": 0, "2016": 1, "2024": 2}}},
        },
        "value": {"0": 10, "1": 11, "2": 12, "3": 1, "4": 2, "6": 5, "7": 6},
    }
    path = tmp_path / "sample.json"
    path.write_text(json.dumps(data), encoding="utf-8")
    return path


def run(tmp_path):
    result = subprocess.run(
        [sys.executable, str(SCRIPT), "demo_set", "unit=NR", "--file", str(jsonstat(tmp_path))],
        capture_output=True, text=True, check=True,
    )
    return result.stdout


def test_keeps_only_listed_places_with_values(tmp_path):
    out = run(tmp_path)
    assert "geo: [EU27_2020, FR]" in out


def test_reports_the_gap_and_the_united_kingdom(tmp_path):
    out = run(tmp_path)
    assert "#   FR: 2015-2016" in out
    assert "United Kingdom: values stop in 2016" in out


def test_time_range_covers_the_listed_places(tmp_path):
    out = run(tmp_path)
    assert "time: { granularity: annual, from: 2015, to: 2024 }" in out
