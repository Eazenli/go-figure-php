# Data pipeline

Python code that builds `facts.sqlite`, the table the app reads every number from. It runs in
its own container (`python:3.13-slim`), on demand; the demo image only ships the resulting file.

| File | Role |
| --- | --- |
| `refresh.py` | Downloads every Eurostat entry of the catalog and writes `facts.sqlite` and `facts.csv` |
| `weather.py` | Downloads the daily weather of each city of `cities.csv` into `raw/open-meteo/`, and computes the monthly normals `refresh.py` writes |
| `cities.csv` | The cities of the weather: Eurostat city code, English name, country, the point asked for (GISCO label point), a note. A proposal: its order is the order of download |
| `catalog_draft.py` | Drafts the factual part of a catalog entry from the Eurostat API |
| `tests/` | pytest tests |
| `requirements.txt` | Python dependencies |
| `facts.sqlite`, `facts.csv` | The output, committed: the image and the CI build from it |

From `app/`:

```bash
make refresh       # rebuild facts.sqlite and facts.csv
make refresh c="nights_total prices_food"   # download these entries only, keep the others
make weather       # cache the daily weather of the cities not cached yet (stops at the quota)
make data-test     # run the tests
make data-sh       # a shell in the Python container
```

`refresh.py` downloads each Eurostat entry from the Statistics API 1.0 (JSON-stat), with every
dimension frozen as the entry says, and keeps the entry's places and periods. A cell the source
publishes without a value gets a row with no value; its flag is kept in lower case (`e`, `b`, `c`
for confidential). Rows of other providers (the weather, computed by its own extractor) are
carried over from the current `facts.sqlite`. Both files are written to a temporary file first,
so a failed download leaves the snapshot as it was.

`weather.py` asks Open-Meteo, once per city, for the daily maximum temperature and precipitation
of 1991-2020 (CERRA reanalysis) and keeps the answer in `raw/open-meteo/<code>.json`. A city costs
about 780 calls of the free API, which allows 10,000 a day: the script waits 10 minutes between two
cities (`--pause`), stops at the first "429 Too Many Requests", and starts again where it stopped
the next time. `refresh.py` then computes the normals from that cache, without any call: the mean of
the daily maxima of each month, and the mean of the monthly totals of precipitation (a month of a
year with a day missing has no total). A month with more than a tenth of its days (of its years,
for precipitation) missing gets no row. Means are rounded half up to one decimal. The point of each city must be the one its
catalog entry links to (`geo_map`): the tests check it.

The first run creates a virtualenv in `data/.venv` (ignored by git); it is rebuilt whenever
`requirements.txt` changes.

`raw/open-meteo/` is committed on purpose: it is what the weather normals were computed from, and
downloading it again takes days of a free quota. Source and licence: Open-Meteo.com, CC BY 4.0,
with modified Copernicus Climate Change Service information.
