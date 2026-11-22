"""Build facts.sqlite and facts.csv, the table the app reads every number from.

For every catalog/*.yaml entry of Eurostat:
  1. download its dataset from the Statistics API 1.0 (JSON-stat), with every dimension frozen
     as the entry says, except geo and time;
  2. keep the places of the entry and the periods of its coverage;
  3. write one row per cell into the "facts" table: dataset (the entry id), geo (Eurostat code),
     time, unit (the entry's unit code), value, status (the Eurostat flag, '' if none),
     source_updated (the dataset's "updated" date), snapshot_date (today, or --date).
A cell the source publishes without a value but with a flag (confidential "c", low reliability "u")
gets a row with no value: the app shows "The source publishes no value". A cell the source leaves
out of the response gets no row: the app shows "This source has no data for this place and period".
An entry that gets no row at all stops the run: its frozen dimensions are surely wrong.

Entries of Open-Meteo are not downloaded here: their monthly normals are computed from the daily
values that weather.py cached (make weather), without any call. On a machine without that cache
(no raw/open-meteo/ folder), their rows are carried over from the current facts.sqlite; where the
cache exists, a city it does not hold gets no row (its point may have been corrected: its old
normals would sit under a new proof link). Entries of any other provider are carried over.

The table is written to a temporary file first, then moved into place: a failed download never
leaves a half-written snapshot. facts.csv holds the same rows, sorted, for review.

Run it with: make refresh (from app/, in WSL), or make refresh c="nights_total prices_food" to
download some entries only (the others are carried over).
"""
import argparse
import csv
import datetime
import json
import os
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

import yaml

import weather

HERE = Path(__file__).resolve().parent
CATALOG = HERE.parent / "catalog"
API = "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/"
COLUMNS = ["dataset", "geo", "time", "unit", "value", "status", "source_updated", "snapshot_date"]
SCHEMA = """
CREATE TABLE facts (
  dataset        TEXT NOT NULL,
  geo            TEXT NOT NULL,
  time           TEXT NOT NULL,
  unit           TEXT NOT NULL,
  value          REAL,
  status         TEXT NOT NULL DEFAULT '',
  source_updated TEXT NOT NULL,
  snapshot_date  TEXT NOT NULL,
  PRIMARY KEY (dataset, geo, time)
)
"""


class CatalogLoader(yaml.SafeLoader):
    """YAML as Symfony reads it (1.2): NO is Norway and ON a string, not booleans (YAML 1.1)."""


CatalogLoader.yaml_implicit_resolvers = {
    first: [(tag, pattern) for tag, pattern in resolvers if tag != "tag:yaml.org,2002:bool"]
    for first, resolvers in yaml.SafeLoader.yaml_implicit_resolvers.items()
}
CatalogLoader.add_implicit_resolver("tag:yaml.org,2002:bool", re.compile(r"^(?:true|True|TRUE|false|False|FALSE)$"), list("tTfF"))


def load_catalog(directory=CATALOG):
    """Every entry of the catalog, by id."""
    entries = {}
    for path in sorted(Path(directory).glob("*.yaml")):
        with open(path, encoding="utf-8") as f:
            entry = yaml.load(f, Loader=CatalogLoader)
        entries[entry["id"]] = entry
    return entries


def fetch(dataset, dimensions, places, attempts=3):
    """The JSON-stat response of a dataset, with every dimension frozen but geo and time."""
    query = [("format", "JSON"), ("lang", "EN"), *dimensions.items(), *(("geo", g) for g in places)]
    url = f"{API}{dataset}?{urllib.parse.urlencode(query)}"
    for attempt in range(1, attempts + 1):
        try:
            with urllib.request.urlopen(url, timeout=180) as response:
                return json.load(response)
        except OSError as error:  # URLError, HTTPError and timeouts are all OSError
            # A request Eurostat refuses (a wrong code: 400, 404) will not succeed on a retry.
            if attempt == attempts or (isinstance(error, urllib.error.HTTPError) and 400 <= error.code < 500 and error.code != 429):
                raise RuntimeError(f"{dataset}: {error}") from error
            time.sleep(5 * attempt)
    raise AssertionError("unreachable")


def _as_map(items):
    """JSON-stat sends an index, the values and the flags either as an object or as a list."""
    if isinstance(items, list):
        return {str(i): v for i, v in enumerate(items) if v is not None}
    return {str(k): v for k, v in (items or {}).items()}


def decode(data):
    """{(geo, time): (value or None, flag)} for every cell the response holds.

    Every dimension other than geo and time must hold a single category: the entry froze it.
    """
    ids, sizes = data["id"], data["size"]
    index = {}
    for name in ids:
        category = data["dimension"][name]["category"]["index"]
        index[name] = {code: i for i, code in enumerate(category)} if isinstance(category, list) else category
    loose = [name for name in ids if name not in ("geo", "time") and len(index[name]) != 1]
    if loose:
        raise ValueError(f"dimensions not frozen: {', '.join(loose)}")

    strides = [1] * len(sizes)
    for i in range(len(sizes) - 2, -1, -1):
        strides[i] = strides[i + 1] * sizes[i + 1]
    codes = {name: sorted(index[name], key=index[name].get) for name in ("geo", "time")}
    position = {name: ids.index(name) for name in ("geo", "time")}

    values, flags = _as_map(data.get("value")), _as_map(data.get("status"))
    cells = {}
    for pos in set(values) | set(flags):
        n = int(pos)
        geo = codes["geo"][n // strides[position["geo"]] % sizes[position["geo"]]]
        period = codes["time"][n // strides[position["time"]] % sizes[position["time"]]]
        value = values.get(pos)
        # Before "|", the flags of the value (":" is "not available"); after it, its confidentiality
        # ("C", "N", "P"). The flag keeps the letters of the value, and "c" for any confidentiality.
        observation, _, confidentiality = str(flags.get(pos, "")).partition("|")
        flag = "".join(c for c in observation if c.isalpha()).lower() + ("c" if confidentiality.strip() else "")
        cells[(geo, period)] = (None if value is None else float(value), flag)
    return cells


def covers(entry, period):
    """Whether a period is within the coverage of an entry (YYYY or YYYY-MM compare as text)."""
    span = entry["time"]
    return str(span["from"]) <= period <= str(span["to"])


def rows_of(entry, data, snapshot_date):
    """The rows of an entry, for the places and periods it covers, sorted."""
    places = set(entry["geo"])
    updated = str(data.get("updated", ""))[:10] or snapshot_date
    unit = entry["unit"]["code"]
    rows = [
        (entry["id"], geo, period, unit, value, flag, updated, snapshot_date)
        for (geo, period), (value, flag) in decode(data).items()
        if geo in places and covers(entry, period)
    ]
    return sorted(rows, key=lambda r: (r[1], r[2]))


def carried_over(path, datasets):
    """The current rows of some datasets, from an existing facts.sqlite (none if there is none)."""
    if not path.exists() or not datasets:
        return []
    db = sqlite3.connect(path)
    try:
        marks = ",".join("?" * len(datasets))
        return db.execute(f"SELECT {', '.join(COLUMNS)} FROM facts WHERE dataset IN ({marks})", sorted(datasets)).fetchall()
    finally:
        db.close()


def write(rows, directory=HERE):
    """facts.sqlite and facts.csv, each written to a temporary file then moved into place."""
    rows = sorted(rows, key=lambda r: (r[0], r[1], r[2]))
    database, table = Path(directory) / "facts.sqlite", Path(directory) / "facts.csv"

    temporary = database.with_suffix(".sqlite.tmp")
    temporary.unlink(missing_ok=True)
    db = sqlite3.connect(temporary)
    try:
        with db:
            db.execute(SCHEMA)
            db.executemany(f"INSERT INTO facts VALUES ({','.join('?' * len(COLUMNS))})", rows)
    finally:
        db.close()
    os.replace(temporary, database)

    temporary = table.with_suffix(".csv.tmp")
    with open(temporary, "w", encoding="utf-8", newline="") as f:
        out = csv.writer(f, lineterminator="\n")
        out.writerow(COLUMNS)
        for row in rows:
            out.writerow([_cell(v) for v in row])
    os.replace(temporary, table)


def _cell(value):
    """A value as facts.csv writes it: empty when missing, an integer without ".0"."""
    if value is None:
        return ""
    if isinstance(value, float):
        return str(int(value)) if value.is_integer() else repr(value)
    return value


def refresh(entries, only=None, directory=HERE, download=fetch, snapshot_date=None, weather_cache=weather.CACHE):
    """Download the Eurostat entries (all of them, or the ones in only), compute the weather from
    its cache, carry the rest over."""
    snapshot_date = snapshot_date or datetime.date.today().isoformat()
    eurostat = [e for e in entries.values() if e["source"]["provider"] == "eurostat" and (not only or e["id"] in only)]
    normals = [e for e in entries.values() if e["source"]["provider"] == "open_meteo"]
    unknown = sorted(set(only or []) - set(entries))
    if unknown:
        raise SystemExit(f"Not in the catalog: {', '.join(unknown)}.")

    current = Path(directory) / "facts.sqlite"
    keep = set(entries) - {e["id"] for e in eurostat} - {e["id"] for e in normals}
    rows = carried_over(current, keep)
    if keep:
        print(f"Carried over from the current snapshot: {', '.join(sorted(keep))} ({len(rows)} rows).")
    for entry in eurostat:
        data = download(entry["source"]["dataset"], {k: str(v) for k, v in (entry.get("dimensions") or {}).items()}, entry["geo"])
        own = rows_of(entry, data, snapshot_date)
        if not own:
            raise SystemExit(f"{entry['id']}: no cell for its places and periods in {entry['source']['dataset']}. Check its dimensions; nothing was written.")
        empty = sorted(set(entry["geo"]) - {r[1] for r in own})
        flagged = sum(1 for r in own if r[5])
        missing = sum(1 for r in own if r[4] is None)
        print(f"{entry['id']}: {len(own)} cells ({missing} without a value, {flagged} flagged), updated {own[0][6] if own else '?'}"
              + (f"; no cell at all for {', '.join(empty)}" if empty else ""))
        rows += own
    for entry in normals:
        if not Path(weather_cache).exists():
            kept = carried_over(current, {entry["id"]})
            print(f"{entry['id']}: no weather cache on this machine, {len(kept)} rows carried over from the current snapshot.")
            rows += kept
            continue
        own, uncached, incomplete = weather.rows_of(entry, snapshot_date, weather_cache)
        print(f"{entry['id']}: {len(own)} normals computed from the cache"
              + (f"; not cached, so no row: {', '.join(uncached)} (make weather)" if uncached else "")
              + "".join(f"; {code} has no normal for {', '.join(months)}" for code, months in incomplete.items()))
        rows += own
    write(rows, directory)
    print(f"Wrote {len(rows)} rows to facts.sqlite and facts.csv (snapshot of {snapshot_date}).")
    return rows


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("only", nargs="*", help="entry ids to download (default: every Eurostat entry)")
    parser.add_argument("--date", help="the snapshot date to write, YYYY-MM-DD (default: today)")
    args = parser.parse_args(argv)
    refresh(load_catalog(), only=args.only, snapshot_date=args.date)


if __name__ == "__main__":
    sys.exit(main())
