# Daily weather, as downloaded

One file per city: the answer of the Open-Meteo archive API for 1991-2020 (daily maximum
temperature and precipitation, CERRA reanalysis), kept as it came, with the date of the download
and the request that was sent. `weather.py` computes the monthly normals of the catalog from
these files, without the network: anyone can recompute a normal shown on a page.

Source: [Open-Meteo](https://open-meteo.com/), CC BY 4.0. Contains modified Copernicus Climate
Change Service information; neither the European Commission nor ECMWF is responsible for any use
that may be made of it.

To add a city: `make weather` (see `../../README.md`). The free API allows about ten cities a
day from one machine.
