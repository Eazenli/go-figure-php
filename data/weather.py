"""The weather of the cities: daily values from Open-Meteo, monthly normals computed by Go Figure.

Two steps:
  1. download (make weather): for each city of cities.csv not cached yet, the daily maximum
     temperature and precipitation of 1991-2020 (CERRA reanalysis), kept as it came in
     raw/open-meteo/<code>.json. A city costs about 780 calls of the free API (10,000 a day): the
     script waits between two cities, stops at the first "429 Too Many Requests" and starts again
     where it stopped the next time. No call is made for a city already cached.
  2. compute (make refresh, no network): the normal of each month, from the cache, for the
     cities of each Open-Meteo entry of the catalog. refresh.py writes them into facts.sqlite.

The normals:
  - temperature: the mean of every daily maximum of that month over the 30 years;
  - precipitation: the total of each month of each year, then the mean of those totals; a month
    of a year with a day missing is left out (its total would be wrong).
A month of the year with more than 10% of its days (or of its years, for precipitation) missing
gets no row: the app then says the source has no data, rather than showing a weak figure. The mean
is computed in decimal and rounded half up to one decimal, as anyone redoing it in a spreadsheet
would find.
"""
import argparse
import csv
import datetime
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import defaultdict
from decimal import ROUND_HALF_UP, Decimal
from pathlib import Path

HERE = Path(__file__).resolve().parent
CITIES = HERE / "cities.csv"
CACHE = HERE / "raw" / "open-meteo"
ARCHIVE = "https://archive-api.open-meteo.com/v1/archive"
START, END, DAYS = "1991-01-01", "2020-12-31", 10958
VARIABLES = {"temperature_2m_max": "°C", "precipitation_sum": "mm"}
# The unit code a catalog entry of each variable must give (like NR for Eurostat).
UNITS = {"temperature_2m_max": "degC", "precipitation_sum": "mm"}
MISSING = 0.10


class QuotaReached(Exception):
    """Open-Meteo answered 429: the daily (or hourly) quota of the free API is used up."""


def load_cities(path=CITIES):
    """The cities, in the order of the file (its order is the order of download)."""
    with open(path, encoding="utf-8", newline="") as f:
        return [row for row in csv.DictReader(f) if row["code"].strip()]


def place(city):
    """The point of a city as the source link of the catalog writes it (geo_map)."""
    return f"latitude={city['latitude']}&longitude={city['longitude']}"


def request_url(city):
    """One request for both variables, the whole reference period, the CERRA reanalysis."""
    query = urllib.parse.urlencode({
        "start_date": START, "end_date": END, "daily": ",".join(VARIABLES), "models": "cerra", "timezone": "GMT",
    })
    return f"{ARCHIVE}?{place(city)}&{query}"


def check(response):
    """Stop on a response that is not what the normals are computed from."""
    units = response.get("daily_units", {})
    for variable, unit in VARIABLES.items():
        if units.get(variable) != unit:
            raise ValueError(f"{variable}: unit {units.get(variable)!r}, expected {unit!r}")
    daily = response.get("daily", {})
    days = daily.get("time", [])
    if len(days) != DAYS or days[0] != START or days[-1] != END:
        raise ValueError(f"expected {DAYS} days from {START} to {END}, got {len(days)}")
    for variable in VARIABLES:
        if len(daily.get(variable, [])) != DAYS:
            raise ValueError(f"{variable}: {len(daily.get(variable, []))} values for {DAYS} days")


def download(city, directory=CACHE, opener=urllib.request.urlopen, today=None):
    """Cache the daily values of a city; False if it was cached already (no call made)."""
    directory = Path(directory)
    path = directory / f"{city['code']}.json"
    if path.exists():
        return False
    url = request_url(city)
    try:
        with opener(url, timeout=300) as answer:
            response = json.load(answer)
    except urllib.error.HTTPError as error:
        if error.code == 429:
            raise QuotaReached(f"{city['code']}: {error}") from error
        raise
    directory.mkdir(parents=True, exist_ok=True)
    try:
        check(response)
    except ValueError as error:
        # Kept for a look, and never asked again by itself: each try costs the quota of a city.
        with open(directory / f"{city['code']}.rejected.json", "w", encoding="utf-8") as f:
            json.dump({"error": str(error), "request": url, "response": response}, f)
        raise
    temporary = path.with_suffix(".json.tmp")
    with open(temporary, "w", encoding="utf-8") as f:
        json.dump({"downloaded": today or datetime.date.today().isoformat(), "request": url, "response": response}, f)
    os.replace(temporary, path)
    return True


def normals(response, variable):
    """{"M01": value, …, "M12": value}, rounded to one decimal; a month too incomplete is left out."""
    days = response["daily"]["time"]
    values = [None if v is None else Decimal(str(v)) for v in response["daily"][variable]]
    series = defaultdict(list)
    if variable == "precipitation_sum":
        totals = defaultdict(list)
        for day, value in zip(days, values):
            totals[(day[:4], day[5:7])].append(value)
        for (_, month), month_values in sorted(totals.items()):
            series[month].append(None if None in month_values else sum(month_values))
    else:
        for day, value in zip(days, values):
            series[day[5:7]].append(value)

    result = {}
    for month, month_values in sorted(series.items()):
        known = [v for v in month_values if v is not None]
        if not known or (len(month_values) - len(known)) / len(month_values) > MISSING:
            continue
        result[f"M{month}"] = float((sum(known) / len(known)).quantize(Decimal("0.1"), rounding=ROUND_HALF_UP))
    return result


def variable_of(entry):
    """The daily variable an entry averages, read from its source link (daily=…)."""
    query = urllib.parse.parse_qs(urllib.parse.urlsplit(entry["source"]["url"]).query)
    variable = query.get("daily", [""])[0]
    if variable not in VARIABLES:
        raise ValueError(f"{entry['id']}: daily={variable!r} is not one of {', '.join(VARIABLES)}")
    if entry["unit"]["code"] != UNITS[variable]:
        raise ValueError(f"{entry['id']}: {variable} is in {UNITS[variable]}, not {entry['unit']['code']}")
    return variable


def asks_what_is_cached(entry, code, request):
    """Whether the source link of a city (the proof) asks for what its cache holds: the same point,
    model, period and time zone, and a variable the cache has."""
    point = (entry.get("geo_map") or {}).get(code, "")
    link = urllib.parse.parse_qs(urllib.parse.urlsplit(entry["source"]["url"].replace("{place}", point)).query)
    cached = urllib.parse.parse_qs(urllib.parse.urlsplit(request).query)
    asked, held = link.pop("daily", [""])[0], cached.pop("daily", [""])[0].split(",")
    return bool(point) and link == cached and asked in held


def rows_of(entry, snapshot_date, directory=CACHE):
    """The rows of an Open-Meteo entry, from the cache: the cities of the entry that are cached,
    the cities that are not, and the months a cached city has no normal for.

    The source link of each city (its proof) must ask for what its cache holds: otherwise the proof
    would link to other values than the ones the normal was computed from.
    """
    variable, rows, missing, incomplete = variable_of(entry), [], [], {}
    for code in entry["geo"]:
        path = Path(directory) / f"{code}.json"
        if not path.exists():
            missing.append(code)
            continue
        with open(path, encoding="utf-8") as f:
            cached = json.load(f)
        if not asks_what_is_cached(entry, code, cached["request"]):
            raise ValueError(f"{entry['id']}: the source link of {code} does not ask for what its cache holds ({cached['request']})")
        check(cached["response"])
        months = normals(cached["response"], variable)
        if len(months) < 12:
            incomplete[code] = [f"M{m:02d}" for m in range(1, 13) if f"M{m:02d}" not in months]
        for month, value in months.items():
            rows.append((entry["id"], code, month, entry["unit"]["code"], value, "", cached["downloaded"], snapshot_date))
    return sorted(rows, key=lambda r: (r[1], r[2])), missing, incomplete


def main(argv=None):
    parser = argparse.ArgumentParser(description="Download the daily weather of the cities of cities.csv not cached yet.")
    parser.add_argument("only", nargs="*", help="city codes to download (default: every city of cities.csv, in order)")
    parser.add_argument("--pause", type=int, default=600, help="seconds to wait between two cities (default: 600)")
    args = parser.parse_args(argv)

    cities = [c for c in load_cities() if not args.only or c["code"] in args.only]
    unknown = sorted(set(args.only) - {c["code"] for c in cities})
    if unknown:
        raise SystemExit(f"Not in cities.csv: {', '.join(unknown)}.")
    waiting = False
    for city in cities:
        if (CACHE / f"{city['code']}.json").exists():
            continue
        if "before downloading" in city.get("note", ""):
            print(f"{city['code']} {city['name']}: skipped. {city['note']}.", flush=True)
            continue
        if (CACHE / f"{city['code']}.rejected.json").exists():
            print(f"{city['code']} {city['name']}: skipped, its answer was rejected (raw/open-meteo/{city['code']}.rejected.json; delete it to try again).", flush=True)
            continue
        if waiting:
            print(f"Waiting {args.pause} s (the quota of the free API)…", flush=True)
            time.sleep(args.pause)
        try:
            download(city)
        except QuotaReached as error:
            print(f"Quota of Open-Meteo reached ({error}): run make weather again later, it starts where it stopped.")
            return 1
        except (ValueError, OSError) as error:  # a rejected answer, another HTTP error, a timeout
            print(f"{city['code']} {city['name']}: not cached, {error}", flush=True)
            waiting = True
            continue
        print(f"{city['code']} {city['name']}: cached.", flush=True)
        waiting = True
    cached = sum(1 for c in cities if (CACHE / f"{c['code']}.json").exists())
    print(f"{cached} of {len(cities)} cities cached. Then: make refresh.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
