"""weather.py on responses built in the test: no network.

The normals (a leap year, a month too incomplete), the checks of a response, the cache (no call for
a city already cached, a cut download leaves nothing behind, the quota stops the run), and the
rows refresh.py writes: computed from the cache, for the point the proof links to.
"""
import datetime
import io
import json
import re
import sys
import urllib.error
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import refresh  # noqa: E402
import weather  # noqa: E402

SEVILLE = {"code": "ES004C", "name": "Seville", "country": "ES", "latitude": "37.393", "longitude": "-5.956", "note": ""}
ENTRY = {
    "id": "rain_city",
    "source": {"provider": "open_meteo", "url": "https://archive-api.open-meteo.com/v1/archive?{place}&start_date=1991-01-01&end_date=2020-12-31&daily=precipitation_sum&models=cerra&timezone=GMT"},
    "geo_map": {"ES004C": "latitude=37.393&longitude=-5.956", "PT001C": "latitude=38.807&longitude=-9.174"},
    "geo": ["ES004C", "PT001C"],
    "time": {"granularity": "month_of_year", "normal": "1991-2020"},
    "unit": {"code": "mm", "label": "mm"},
}


def response(temperature=lambda day: 10 + day.month, rain=lambda day: 1.0):
    """30 years of days: by default the maximum is 10 + the month, and it rains 1 mm every day."""
    days, day = [], datetime.date(1991, 1, 1)
    while day <= datetime.date(2020, 12, 31):
        days.append(day)
        day += datetime.timedelta(days=1)
    return {
        "latitude": 37.4, "longitude": -5.95, "elevation": 21.0,
        "daily_units": {"time": "iso8601", "temperature_2m_max": "°C", "precipitation_sum": "mm"},
        "daily": {
            "time": [d.isoformat() for d in days],
            "temperature_2m_max": [temperature(d) for d in days],
            "precipitation_sum": [rain(d) for d in days],
        },
    }


def test_the_temperature_normal_is_the_mean_of_every_day_of_the_month():
    normals = weather.normals(response(), "temperature_2m_max")
    assert normals == {f"M{m:02d}": 10.0 + m for m in range(1, 13)}


def test_the_rain_normal_is_the_mean_of_the_monthly_totals_leap_years_included():
    normals = weather.normals(response(), "precipitation_sum")
    assert normals["M01"] == 31.0
    # 22 Februaries of 28 days and 8 of 29 (1992, 1996 … 2020): 848 / 30.
    assert normals["M02"] == 28.3


def test_a_month_of_a_year_with_a_day_missing_is_left_out_of_the_rain():
    one_gap = response(rain=lambda d: None if d == datetime.date(1995, 3, 10) else 2.0)
    assert weather.normals(one_gap, "precipitation_sum")["M03"] == 62.0

    four_gaps = response(rain=lambda d: None if d.month == 3 and d.day == 10 and d.year in (1995, 1996, 1997, 1998) else 2.0)
    assert "M03" not in weather.normals(four_gaps, "precipitation_sum")


def test_a_normal_is_rounded_half_up_as_a_spreadsheet_would():
    # In binary floats, round(2.25, 1) gives 2.2.
    assert weather.normals(response(temperature=lambda d: 2.25), "temperature_2m_max")["M01"] == 2.3


def test_a_month_with_too_many_days_missing_gets_no_normal():
    # Four Septembers missing out of thirty: more than a tenth of the days.
    gaps = response(temperature=lambda d: None if d.month == 9 and d.year < 1995 else 30.0)
    normals = weather.normals(gaps, "temperature_2m_max")
    assert "M09" not in normals
    assert normals["M08"] == 30.0


@pytest.mark.parametrize("spoil", [
    lambda r: r["daily_units"].update(temperature_2m_max="°F"),
    lambda r: r["daily_units"].update(precipitation_sum="inch"),
    lambda r: r["daily"]["time"].pop(),
    lambda r: r["daily"]["precipitation_sum"].pop(),
])
def test_an_unexpected_response_stops_the_extraction(spoil):
    spoilt = response()
    spoil(spoilt)
    with pytest.raises(ValueError):
        weather.check(spoilt)


def answer(body):
    def opener(url, timeout):
        opener.urls.append(url)
        return io.BytesIO(json.dumps(body).encode())
    opener.urls = []
    return opener


def test_a_city_is_downloaded_once_into_the_cache(tmp_path):
    opener = answer(response())
    assert weather.download(SEVILLE, tmp_path, opener, today="2026-11-18") is True
    assert weather.download(SEVILLE, tmp_path, opener, today="2026-11-19") is False

    assert opener.urls == [
        "https://archive-api.open-meteo.com/v1/archive?latitude=37.393&longitude=-5.956&start_date=1991-01-01"
        "&end_date=2020-12-31&daily=temperature_2m_max%2Cprecipitation_sum&models=cerra&timezone=GMT"
    ]
    cached = json.loads((tmp_path / "ES004C.json").read_text(encoding="utf-8"))
    assert cached["downloaded"] == "2026-11-18"
    assert cached["response"]["elevation"] == 21.0
    assert not list(tmp_path.glob("*.tmp"))


def test_a_download_cut_short_leaves_no_cache_and_is_done_again(tmp_path):
    (tmp_path / "ES004C.json.tmp").write_text("{\"daily\": {", encoding="utf-8")
    spoilt = response()
    spoilt["daily"]["time"].pop()
    with pytest.raises(ValueError):
        weather.download(SEVILLE, tmp_path, answer(spoilt))
    assert not (tmp_path / "ES004C.json").exists()
    # The rejected answer is kept for a look: make weather does not ask for it again by itself.
    assert json.loads((tmp_path / "ES004C.rejected.json").read_text(encoding="utf-8"))["error"].startswith("expected")

    assert weather.download(SEVILLE, tmp_path, answer(response())) is True
    assert not list(tmp_path.glob("*.tmp"))


def test_the_quota_stops_the_download(tmp_path):
    def opener(url, timeout):
        raise urllib.error.HTTPError(url, 429, "Too Many Requests", None, None)

    with pytest.raises(weather.QuotaReached):
        weather.download(SEVILLE, tmp_path, opener)
    assert not list(tmp_path.iterdir())


def test_the_rows_come_from_the_cache_for_the_point_the_proof_links_to(tmp_path):
    weather.download(SEVILLE, tmp_path, answer(response()), today="2026-11-18")

    rows, uncached, incomplete = weather.rows_of(ENTRY, "2026-11-20", tmp_path)

    assert uncached == ["PT001C"]
    assert incomplete == {}
    assert len(rows) == 12
    assert rows[0] == ("rain_city", "ES004C", "M01", "mm", 31.0, "", "2026-11-18", "2026-11-20")

    moved = dict(ENTRY, geo_map={"ES004C": "latitude=37.39&longitude=-5.99"})
    with pytest.raises(ValueError, match="ES004C"):
        weather.rows_of(moved, "2026-11-20", tmp_path)


@pytest.mark.parametrize("change", [
    lambda url: url.replace("&models=cerra", ""),
    lambda url: url.replace("models=cerra", "models=era5"),
    lambda url: url.replace("start_date=1991-01-01", "start_date=1981-01-01"),
    lambda url: url.replace("timezone=GMT", "timezone=Europe%2FMadrid"),
])
def test_a_source_link_asking_for_other_values_than_the_cache_is_refused(tmp_path, change):
    weather.download(SEVILLE, tmp_path, answer(response()))
    other = dict(ENTRY, source=dict(ENTRY["source"], url=change(ENTRY["source"]["url"])))

    with pytest.raises(ValueError, match="does not ask for what its cache holds"):
        weather.rows_of(other, "2026-11-20", tmp_path)


def test_the_unit_of_an_entry_must_be_the_unit_of_its_variable(tmp_path):
    with pytest.raises(ValueError, match="degC"):
        weather.variable_of(dict(ENTRY, source=dict(ENTRY["source"], url=ENTRY["source"]["url"].replace("precipitation_sum", "temperature_2m_max"))))


def test_the_months_a_city_has_no_normal_for_are_named(tmp_path):
    weather.download(SEVILLE, tmp_path, answer(response(rain=lambda d: None if d.month == 2 else 1.0)))

    _, _, incomplete = weather.rows_of(ENTRY, "2026-11-20", tmp_path)

    assert incomplete == {"ES004C": ["M02"]}


def test_refresh_writes_the_normals_of_the_cache(tmp_path):
    cache = tmp_path / "cache"
    weather.download(SEVILLE, cache, answer(response()), today="2026-11-18")

    rows = refresh.refresh({"rain_city": ENTRY}, directory=tmp_path, snapshot_date="2026-11-20", weather_cache=cache)

    assert [r[2] for r in rows] == [f"M{m:02d}" for m in range(1, 13)]
    assert {r[1] for r in rows} == {"ES004C"}


def test_where_the_cache_exists_a_city_it_does_not_hold_gets_no_row(tmp_path):
    # Lisbon's normals of an earlier snapshot, then its point is corrected and its cache deleted.
    refresh.write([("rain_city", "PT001C", "M09", "mm", 40.0, "", "2026-11-18", "2026-11-18")], tmp_path)
    cache = tmp_path / "cache"
    weather.download(SEVILLE, cache, answer(response()), today="2026-11-18")

    rows = refresh.refresh({"rain_city": ENTRY}, directory=tmp_path, snapshot_date="2026-11-20", weather_cache=cache)

    assert {r[1] for r in rows} == {"ES004C"}


def test_the_shipped_cities_have_eurostat_codes_and_points():
    cities = weather.load_cities()
    codes = [c["code"] for c in cities]
    assert len(codes) == len(set(codes))
    for city in cities:
        assert re.fullmatch(r"[A-Z]{2}\d{3}C", city["code"]), city
        assert city["code"].startswith(city["country"]), city
        assert -90 <= float(city["latitude"]) <= 90 and -180 <= float(city["longitude"]) <= 180, city
        assert city["name"] and not re.search(r"\d", city["name"]), city


def test_every_weather_entry_of_the_catalog_links_to_the_point_of_its_cities():
    points = {c["code"]: weather.place(c) for c in weather.load_cities()}
    for entry in refresh.load_catalog().values():
        if entry["source"]["provider"] != "open_meteo":
            continue
        weather.variable_of(entry)
        for code in entry["geo"]:
            assert entry["geo_map"][code] == points[code], (entry["id"], code)
