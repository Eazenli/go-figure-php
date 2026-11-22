"""Draft a catalog entry from the real Eurostat data.

Computes the factual fields: the places and periods that actually have a value, the unit
and the update date. The written fields (title, scope, pitfalls) are left to a human.

A starting point for the data pipeline: move it or merge it into the extraction as needed.

Usage:
    python catalog_draft.py tour_occ_ninat c_resid=TOTAL nace_r2=I551 unit=NR
    python catalog_draft.py tour_occ_ninat c_resid=TOTAL nace_r2=I551 unit=NR --file data.json
"""
import argparse
import json
import urllib.parse
import urllib.request

API = "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/"
EU27 = "BE BG CZ DK DE EE IE EL ES FR HR IT CY LV LT LU HU MT NL AT PL PT RO SI SK FI SE".split()
# Popular destinations outside the EU that Eurostat still covers (decided on 2026-11-11).
EXTRA = "CH NO IS TR ME".split()
COUNTRIES = EU27 + EXTRA


def load(dataset, dims, path):
    if path:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    query = urllib.parse.urlencode([("format", "JSON"), *dims.items()])
    with urllib.request.urlopen(f"{API}{dataset}?{query}", timeout=120) as r:
        return json.load(r)


def main():
    p = argparse.ArgumentParser()
    p.add_argument("dataset")
    p.add_argument("dims", nargs="+", help="frozen dimensions, for example nace_r2=I551")
    p.add_argument("--file", help="a JSON-stat response already downloaded")
    a = p.parse_args()
    dims = dict(d.split("=", 1) for d in a.dims)
    d = load(a.dataset, dims, a.file)

    ids, sizes = d["id"], d["size"]
    index = {k: d["dimension"][k]["category"]["index"] for k in ids}
    labels = {k: d["dimension"][k]["category"].get("label", {}) for k in ids}
    for k, v in index.items():  # JSON-stat sends the index either as an object or a list
        if isinstance(v, list):
            index[k] = {code: i for i, code in enumerate(v)}
    strides = [1] * len(sizes)
    for i in range(len(sizes) - 2, -1, -1):
        strides[i] = strides[i + 1] * sizes[i + 1]

    # Every dimension must be frozen to a single value, except geo and time.
    loose = [k for k in ids if k not in ("geo", "time") and len(index[k]) != 1]
    if loose:
        raise SystemExit(f"Dimensions not frozen: {loose}. Pass them as arguments.")

    geo_pos, time_pos = ids.index("geo"), ids.index("time")
    values = d["value"]
    if isinstance(values, list):
        values = {str(i): v for i, v in enumerate(values) if v is not None}
    geo_codes = sorted(index["geo"], key=index["geo"].get)
    time_codes = sorted(index["time"], key=index["time"].get)
    periods = {}  # place -> the periods that have a value
    for pos in values:
        pos = int(pos)
        place = geo_codes[pos // strides[geo_pos] % sizes[geo_pos]]
        periods.setdefault(place, set()).add(time_codes[pos // strides[time_pos] % sizes[time_pos]])

    places = ["EU27_2020"] + COUNTRIES
    geo = [c for c in places if c in periods]
    missing = [c for c in COUNTRIES if c not in periods]
    times = sorted(set().union(*(periods[c] for c in geo)))
    unit = dims.get("unit") or next(iter(index.get("unit", {"?": 0})))

    print(f"# Draft generated from the Eurostat API (dataset updated {d.get('updated', '?')[:10]})")
    print(f"# Countries without any value: {', '.join(missing) or 'none'}")
    if "UK" in periods:
        print(f"# United Kingdom: values stop in {max(periods['UK'])} (no Eurostat data after Brexit); not listed.")
    # Per-place coverage, so a human can choose time.from and write the pitfalls.
    print("# Coverage (first-last, missing periods in between):")
    for c in geo:
        own = sorted(periods[c])
        gaps = [t for t in times if own[0] <= t <= own[-1] and t not in periods[c]]
        if own[0] != times[0] or own[-1] != times[-1] or gaps:
            print(f"#   {c}: {own[0]}-{own[-1]}" + (f", missing {', '.join(gaps)}" if gaps else ""))
    print("source:")
    print("  provider: eurostat")
    print(f"  dataset: {a.dataset}")
    print(f'  url: "https://ec.europa.eu/eurostat/databrowser/view/{a.dataset}/default/table"')
    print('  licence: "Eurostat, reuse authorised with source acknowledgement"')
    print("dimensions:")
    for k, v in dims.items():
        print(f"  {k}: {v}  # {labels[k].get(v, '')}")
    print(f"geo: [{', '.join(geo)}]")
    granularity = "monthly" if "-" in times[0] else "annual"
    print(f"time: {{ granularity: {granularity}, from: {times[0]}, to: {times[-1]} }}")
    print(f'unit: {{ code: {unit}, label: "{labels.get("unit", {}).get(unit, unit)}" }}')


if __name__ == "__main__":
    main()
