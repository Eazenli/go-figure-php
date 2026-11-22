"""refresh.py on small hand-written JSON-stat responses: no network.

The traps of JSON-stat: the index and the flags sent as an object or as a list, a cell published
without a value, a cell not published at all, a dimension left loose. And what the snapshot keeps:
the places and periods of the entry, the rows of other providers, the same rows in SQLite and CSV.
"""
import csv
import sqlite3
import sys
import urllib.error
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import refresh  # noqa: E402

ENTRY = {
    "id": "nights_total",
    "source": {"provider": "eurostat", "dataset": "tour_occ_ninat"},
    "dimensions": {"unit": "NR"},
    "geo": ["EU27_2020", "FR", "MT"],
    "time": {"granularity": "annual", "from": 2019, "to": 2024},
    "unit": {"code": "NR", "label": "nights"},
}
WEATHER = {
    "id": "temp_max_city",
    "source": {"provider": "open_meteo", "url": "https://archive-api.open-meteo.com/v1/archive?{place}&start_date=1991-01-01&end_date=2020-12-31&daily=temperature_2m_max&models=cerra&timezone=GMT"},
    "geo_map": {"ES004C": "latitude=37.393&longitude=-5.956"},
    "geo": ["ES004C"],
    "time": {"granularity": "month_of_year", "normal": "1991-2020"},
    "unit": {"code": "degC"},
}


def jsonstat(index_as_list=False, flags_as_list=False):
    """4 places x 3 years, flattened row by row. UK is not an entry place; 2018 is out of coverage.
    FR 2018 is estimated, FR 2024 is published without a value (":"), MT 2024 is confidential
    ("|C"), MT 2018 has a break in series and is confidential ("b|C"), MT 2019 is not published at
    all, UK 2019 has a value but UK is not a place of the entry."""
    geo = ["EU27_2020", "FR", "MT", "UK"]
    values = {"0": 10, "1": 11, "2": 12, "3": 1.5, "4": 2, "10": 99}
    flags = {"3": "e", "5": ":", "6": "b|C", "8": "|C"}
    return {
        "updated": "2026-10-12T23:00:00+0200",
        "id": ["unit", "geo", "time"],
        "size": [1, 4, 3],
        "dimension": {
            "unit": {"category": {"index": {"NR": 0}}},
            "geo": {"category": {"index": geo if index_as_list else {g: i for i, g in enumerate(geo)}}},
            "time": {"category": {"index": {"2018": 0, "2019": 1, "2024": 2}}},
        },
        "value": values,
        "status": [flags.get(str(i)) for i in range(12)] if flags_as_list else flags,
    }


@pytest.mark.parametrize("index_as_list", [False, True])
@pytest.mark.parametrize("flags_as_list", [False, True])
def test_a_cell_is_decoded_whatever_the_shape_of_the_response(index_as_list, flags_as_list):
    cells = refresh.decode(jsonstat(index_as_list, flags_as_list))

    assert cells[("EU27_2020", "2019")] == (11.0, "")
    assert cells[("FR", "2019")] == (2.0, "")
    assert cells[("FR", "2018")] == (1.5, "e")
    # Published without a value: a cell with none, so the app says the source publishes no value.
    assert cells[("FR", "2024")] == (None, "")
    # Confidential, without a value: "c", after the flags of the value.
    assert cells[("MT", "2024")] == (None, "c")
    assert cells[("MT", "2018")] == (None, "bc")
    assert cells[("UK", "2019")] == (99.0, "")
    # Not published at all: no cell.
    assert ("MT", "2019") not in cells


def test_a_loose_dimension_is_an_error():
    data = jsonstat()
    data["dimension"]["unit"]["category"]["index"] = {"NR": 0, "P_THAB": 1}

    with pytest.raises(ValueError, match="not frozen: unit"):
        refresh.decode(data)


def test_only_the_places_and_periods_of_the_entry_are_kept():
    rows = refresh.rows_of(ENTRY, jsonstat(), "2026-11-18")

    assert [(r[1], r[2]) for r in rows] == [("EU27_2020", "2019"), ("EU27_2020", "2024"), ("FR", "2019"), ("FR", "2024"), ("MT", "2024")]
    assert {r[3] for r in rows} == {"NR"}
    assert {r[6] for r in rows} == {"2026-10-12"}
    assert {r[7] for r in rows} == {"2026-11-18"}


def test_the_snapshot_is_written_and_a_city_not_cached_keeps_its_rows(tmp_path):
    refresh.write([("temp_max_city", "ES004C", "M09", "degC", 31.1, "", "2026-11-18", "2026-11-18")], tmp_path)
    asked = []

    def download(dataset, dimensions, places):
        asked.append((dataset, dimensions, places))
        return jsonstat()

    rows = refresh.refresh({"nights_total": ENTRY, "temp_max_city": WEATHER}, directory=tmp_path, download=download,
                           snapshot_date="2026-11-18", weather_cache=tmp_path / "no-cache")

    assert asked == [("tour_occ_ninat", {"unit": "NR"}, ["EU27_2020", "FR", "MT"])]
    assert len(rows) == 6
    db = sqlite3.connect(tmp_path / "facts.sqlite")
    stored = db.execute("SELECT dataset, geo, time, value, status FROM facts ORDER BY dataset, geo, time").fetchall()
    db.close()
    assert ("temp_max_city", "ES004C", "M09", 31.1, "") in stored
    assert ("nights_total", "FR", "2024", None, "") in stored
    with open(tmp_path / "facts.csv", encoding="utf-8", newline="") as f:
        table = list(csv.reader(f))
    assert table[0] == refresh.COLUMNS
    assert len(table) == 1 + len(stored)
    assert ["nights_total", "EU27_2020", "2019", "NR", "11", "", "2026-10-12", "2026-11-18"] in table
    assert not list(tmp_path.glob("*.tmp"))


def test_a_failed_download_leaves_the_snapshot_as_it_was(tmp_path):
    refresh.write([("nights_total", "FR", "2019", "NR", 1.0, "", "2026-10-12", "2026-11-01")], tmp_path)

    def download(dataset, dimensions, places):
        raise RuntimeError("Eurostat is down")

    with pytest.raises(RuntimeError):
        refresh.refresh({"nights_total": ENTRY}, directory=tmp_path, download=download)
    db = sqlite3.connect(tmp_path / "facts.sqlite")
    assert db.execute("SELECT snapshot_date FROM facts").fetchall() == [("2026-11-01",)]
    db.close()


def test_the_shipped_catalog_has_only_entries_refresh_knows():
    entries = refresh.load_catalog()

    assert entries
    for entry in entries.values():
        assert entry["source"]["provider"] in ("eurostat", "open_meteo"), entry["id"]
        assert entry["unit"]["code"]
        # Read as Symfony reads it: Norway is "NO", not False (YAML 1.1).
        assert all(isinstance(g, str) for g in entry["geo"]), entry["id"]
    assert "NO" in entries["nights_hotels"]["geo"]


def test_the_shipped_snapshot_and_its_csv_hold_the_same_rows():
    database, table = refresh.HERE / "facts.sqlite", refresh.HERE / "facts.csv"
    if not database.exists():
        pytest.skip("no snapshot yet")
    db = sqlite3.connect(database)
    try:
        rows = db.execute(f"SELECT {', '.join(refresh.COLUMNS)} FROM facts ORDER BY dataset, geo, time").fetchall()
    finally:
        db.close()
    with open(table, encoding="utf-8", newline="") as f:
        lines = list(csv.reader(f))
    assert lines[0] == refresh.COLUMNS
    assert lines[1:] == [[str(refresh._cell(v)) for v in row] for row in rows]


def test_a_monthly_coverage_compares_months_as_text():
    entry = {"time": {"from": "2015-01", "to": "2026-09"}}
    assert [refresh.covers(entry, p) for p in ("2014-12", "2015-01", "2026-09", "2026-10")] == [False, True, True, False]


def test_an_entry_without_any_cell_stops_the_run_and_writes_nothing(tmp_path):
    refresh.write([("nights_total", "FR", "2019", "NR", 1.0, "", "2026-10-12", "2026-11-01")], tmp_path)
    elsewhere = dict(ENTRY, geo=["SE"])

    with pytest.raises(SystemExit, match="nights_total"):
        refresh.refresh({"nights_total": elsewhere}, directory=tmp_path, download=lambda *_: jsonstat())

    db = sqlite3.connect(tmp_path / "facts.sqlite")
    assert db.execute("SELECT snapshot_date FROM facts").fetchall() == [("2026-11-01",)]
    db.close()


def test_a_request_eurostat_refuses_is_not_retried(monkeypatch):
    calls = []

    def refuse(url, timeout):
        calls.append(url)
        raise urllib.error.HTTPError(url, 404, "Not Found", None, None)

    monkeypatch.setattr(refresh.urllib.request, "urlopen", refuse)
    monkeypatch.setattr(refresh.time, "sleep", lambda s: None)
    with pytest.raises(RuntimeError, match="404"):
        refresh.fetch("tour_occ_ninat", {"unit": "NR"}, ["FR"])
    assert len(calls) == 1
