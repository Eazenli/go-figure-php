# Developing Go Figure

How the code, the data and the tests fit together. To run the demo, see the [README](README.md).

```bash
make up        # development container with the source bind-mounted
make test      # PHPUnit
make stan      # PHPStan, level 8, on the code and the tests
make app-logs  # the application log: requests, MCP calls, refused figures
make cassette q=<id>   # record an example question (scripted=1: its hand-written answer)
make key-check # with a key in .env.local: a live question; c=--agent tries the import agent,
               # c=--record records the example questions with that model
make refresh   # rebuild data/facts.sqlite from Eurostat, and the weather from its cache
make weather   # download the daily weather of more cities (see data/README.md)
make data-test # the tests of the data pipeline (Python)
make help      # every target
```

Nothing runs on the host PHP: Composer and the Symfony console run in the container.

Logs go through Monolog. In development, everything goes to `var/log/dev.log` (`make app-logs`),
on the `go_figure` channel:
- each page shown, by `render_page` or the composer;
- each figure, text or period it refused, and why;
- each tool call refused against its input schema.

The demo container logs errors only, as JSON, on standard error. `docker logs go-figure` shows the
web server's startup lines, then the errors of web requests. Over STDIO, errors go to the MCP
client's log instead.

Logs never go to standard output, which carries the MCP protocol over STDIO. A test runs the STDIO
server to check it, and the CI checks the production image.

The data (`data/facts.sqlite`, with `data/facts.csv` for review) is built by a Python pipeline in
`data/`, run in its own container; the image only ships the resulting file. See `data/README.md`.

The catalog (`catalog/*.yaml`) lists what a page may show: one file per dataset entry. The tests
load every file, and an invalid entry fails them with its file and field. A title follows the rule
of the page, since it is shown as one: no digits, no numbers in words, no number signs. A scale
such as "per thousand inhabitants" goes in `unit.label`.

To add an entry: draft its factual fields with `data/catalog_draft.py`, write the title, scope and
pitfalls by hand, run `make refresh c="<id>"`, then give the reader without a model its words
(`QuestionReader::WORDS`, English and French) and a few questions in `eval/`. The tests replay the
sets of `eval/` against the shipped catalog (`tests/Eval/ShippedQuestionsTest.php`): a new entry
that takes the words of another one fails them. A new entry changes the prompt, so the examples of
`demo/` have to be recorded again.

- Places are Eurostat codes: `ES` for Spain, `EL` for Greece, and city codes such as `ES004C`
  (Seville), which start with the code of their country. A city needs its English name in
  `places` (`ES004C: Seville`), and two entries cannot name a place differently.
- A source URL that varies by place holds `{place}`, replaced with the place's value in
  `geo_map`: the source's own code (`GRC`), or its query (`latitude=37.393&longitude=-5.956`).
- `method` says how Go Figure computes a value from what the source publishes, when it does
  (a monthly normal averaged from daily values). The proof shows it, next to the source link
  that returns those values.
- `theme` is the question of the traveller the entry answers (`cost`, `crowds`, `weather`,
  `access`), or `other` for an added dataset that is not about travel: `/compare` groups its
  charts by theme.
- `source.attribution` lists what a source asks to show next to its figures, such as "Weather
  data by Open-Meteo.com" with its link. Every page that shows one of its figures shows it, as
  does the proof.

The layouts of "Try to make it lie" are in `demo/lie/`: `start.json`, plus one file per prepared
attack. The tests check that they name datasets of the catalog, and use copies of their own in
`tests/fixtures/lie/`.

The example questions of `/` are listed in `demo/questions.yaml`. Each one replays its
cassette (`demo/cassettes/`), which holds the exact request sent to the provider and the answer it
streamed back, through the real provider bridge. A change to the prompt, the layout schema or the
catalog makes the replay fail, and `tests/Ai/DemoCassettesTest.php` with it: record the question
again. An answer not yet recorded from a model is written by hand (`demo/scripts/`) and streamed in
the provider's format; the page says so. `make key-check c=--record` records them all with the
model of `.env.local` and notes it in `demo/questions.yaml`.

`app:eval` measures, for a set of questions, how many of the cells an answer names are the expected
ones (good cells), proven but not the ones expected (sourced but wrong, the worst kind), or
refused. Each question of `eval/questions.jsonl` lists the cells a right answer names; one with
none is a trap, which the data cannot answer, and whose right outcome is no page.

- `make console c="app:eval --words"` scores the questions read without a model: no key, no
  network. It fails the run on any figure sourced but wrong, any question missed, any trap answered.
- `make console c="app:eval"` scores a model's answers, replayed from `eval/cassettes/` or called
  with `--live` and recorded. No answer of a model is recorded yet: this half is not measured.

Every set of `eval/` but `questions-fresh.jsonl` was used to correct the reader after a run, so
their scores say little: `eval/README.md` lists them, with the first run of the two sets written
by the second member of the team. A new set is needed each time the reader changes. The tests
replay the sets against the shipped catalog (`tests/Eval/ShippedQuestionsTest.php`); on a machine
where datasets or a file were added, they read those too.

The pages are rendered by the server. The site's own scripts (`public/*.js`) only enhance pages
that work without them, except the steps of a model's answer, which are streamed.

The page layout a model may ask for is a set of PHP classes in `src/Layout/`. Symfony AI turns
them into the JSON Schema sent to the model; the MCP tool declares the same schema as a
constant. After changing a class there, run `make layout-schema`: the tests fail until you do.

On every push, the CI (`.github/workflows/ci.yaml`) audits the dependencies, runs PHPStan and
the tests, then builds the production image and starts it the way the demo does. In the public
repository, it then publishes the image for amd64 and arm64: `latest` from `main`, and a version
from each `v*` tag.

In development, the Symfony profiler is on (`/_profiler`): its HTTP Client panel shows the call to
the provider, and its MCP panel the server's tools. That panel shows the request headers too,
key included: do not share or film it with a real key.

## What happens to a question (with a key)

1. **It is routed**, by Symfony AI's `MultiAgent` (`App\Ai\QuestionRouter`): an orchestrator reads
   the question and hands it to one of three routes. Answer it from the data here; send it to the
   agent that gets data; or decline it (another subject, or one left out on purpose). One call,
   before any page is written. When the decision fails, the question is answered from the data here.
   `MultiAgent` is used here as a classifier, mostly to try the module on a real need: its three
   routes are stand-ins that call no model. One call with a structured output would do as much.
2. **Getting data**: an agent built with Symfony AI (`App\Ai\DataScout`, the agent of `/import`,
   its tools behind a `FaultTolerantToolbox`) looks for a dataset on Eurostat, or for an indicator
   of the World Bank when the question goes beyond Europe, reads its dimensions and proposes it. The
   server checks every code and reads every value from the source itself; a refusal goes back to
   the agent once, as feedback. Within the import limits of `/import`.
3. **The answer**: a sentence or two first, each figure of it read by the server, then the charts.
   When the model writes a number itself, the page does not show it, the refusal goes back to the
   model once and it writes its answer again: the page shows that step.
4. **The right cell, checked without a model**: the server reads the words of the question as it
   does without a key, and says when they fit a figure the model did not show.

**What a question can cost.** At most 14 calls to the model for one question, in the worst case:
the routing (one, two if its decision does not parse), two runs of the agent of five calls each,
the answer, and one more answer after a refused text. The agent runs only when the routing sends
the question to it: an answer that shows no figure is left as it is. A run of the agent is counted
before it is paid for: 4 an hour for one visitor, 40 a day for everyone, on top of the limits on
questions (10 per visitor every 10 minutes, 200 a day) and on imports. A question is given to the
router on one line and without quotes; the titles of datasets added on the machine are reduced
to their letters. A question crafted to send every visitor's question to the agent can still use
up the 40 runs of the day: the agent then stops, the answers from the data here go on.

The page shows each step as it happens, only from the streamed page (with JavaScript). The agent
never writes a value either: it names a dataset and codes.

What ran against a real model (`claude-sonnet-5-5`), by hand:

- **2026-11-21**: the three routes, one question each (a question the data answers; wine
  production, which the agent went and got on Eurostat; an election forecast, declined); the
  sentence first; and the second try, which removed the refused text in two answers out of two.
  That run also found a dataset whose scale was written as a number ("1 000 hl") and shown a
  thousand times too low: such a choice is now refused at import.
- **2026-11-22**: the same wine question no longer gets data: the agent proposed two Eurostat
  datasets and the server refused both for that reason, after eleven calls. "Cereal production in
  Brazil and India since 2010" went through the World Bank in five calls. A question about a city
  the data does not have used to send the agent for nothing: an answer without a figure is now
  left as it is, and a visitor whose runs are used up is told so.

## Public data (`/import`)

Three sources, one proof each: a Eurostat dataset, an indicator of the World Bank (World Development
Indicators: the whole world, by country and year, from the year 2000 on), or the weather of one more
city from Open-Meteo. For the World Bank nothing is written by a visitor or a model: the server derives the
title and the unit from the name the World Bank gives the indicator ("Forest area (% of land area)"),
and refuses an indicator whose unit would put a number on the page ("constant 2015 US$") or that is
in each country's own currency. The same subjects are left out as for Eurostat, by the World Bank's
topics and by the name of the indicator. A Eurostat dataset that has no unit code takes its unit from
the words of its title and of the codes chosen, and is refused when one of those codes carries a
scale ("million euro") that nothing could check.

- **An indicator of the World Bank**: look for it by its words on `/import` (no AI) and add it in
  one click, or call `search_world_bank` then `add_world_bank_indicator` from an MCP client.
- **A Eurostat dataset**: look for it by its words (Eurostat's table of contents, no AI), fix
  every dimension but the place and the period, write its title and scope, and import it. Then
  draw it, compare it or ask about it like the shipped ones. With a key, an agent (Symfony AI's
  Agent and its toolbox) searches Eurostat, reads the dimensions and proposes the entry; you review
  it before anything is imported.
- **The weather of a city**: one request to Open-Meteo (on your own free quota, about a dozen
  cities a day), the monthly normals computed by the server as for the shipped cities.

Either way the server checks every code, reads every value from the source itself, and keeps the
date of the import. The words of an imported entry follow the rule of a model's text (no number),
its unit uses the words of the dataset only, and its scale ("thousand") is written by the server.
Some 2,700 of Eurostat's datasets are accepted: the economy, businesses, agriculture, energy, the
environment, transport, tourism, prices and culture. Subjects about people's lives (health,
migration, justice, living conditions, work) are left out on purpose: by family, by title, and by
the label of every code chosen. Imports
stay in the container (`var/data/imported.sqlite`), at most 20 datasets and 30 cities, and never
change the example questions.

## MCP: what was run by hand

Every tool was called over the protocol, by the tests and by hand over HTTP (a real session:
`initialize`, `tools/call`), and by hand in the MCPJam inspector on 2026-11-21 (the nine tools
listed, `list_datasets` and `render_page` called), then in an assistant that reads text (Claude
Code, the same day, four runs of one question). What those runs showed:
- the figures the assistant quoted were the server's, and it gave the link of the page;
- twice it also wrote a gap of its own ("about 5 °C"), once saying so: the server proves the page,
  not what an assistant writes around it, and it has no calculation for a gap between two places;
- the client handed its assistant the structured content of the result in place of its text:
  first the whole page as HTML, which the assistant saved to a file and searched; then, once that
  was cut down to the link, the link alone, so that it quoted no figure at all. A host that reads
  text now gets text only: the figures as Markdown with their proof links, the link of the page,
  and a first line asking for no number of its own. Run again after that change, the assistant
  quoted each figure with its proof link and wrote no number of its own. The price is in plain
  sight: a proof link holds the whole proof and is some four hundred characters long.

In a host that shows MCP Apps: not yet.

The Hugging Face tiny agent of `agents/go-figure` was written from the documentation and not run: we had no token.

## Your own data (`/data`)

`App\Import\FileImporter` reads a CSV file (`;`, `,` or tab; UTF-8; 256 KB at most), finds the
column of periods (years or `2024-03` months), the column of places and the columns of numbers,
and keeps one dataset per column the depositor names, in the store of the imports
(`var/data/imported.sqlite`, with the datasets added from Eurostat and the World Bank; at most 20
in all). Nothing is kept between the two steps of the page: the second one carries the file again.

- A place of a file is neither a country nor a city: its code is made from its name
  (`Places::ownCode()`, `Y_` and ten hex digits), so the same name is the same place in every
  file, and its name may hold digits ("Store 12"), unlike a text a model writes.
- The proof (`templates/proof.html.twig`) names the file, the column, the day and the SHA-256 of
  the file, kept in the `dimensions` of the entry; there is no source to open.
- Refused: a header that names a person (`FileImporter::PERSONAL`), more than 200 places, two
  lines for one place and period, a title or a unit that holds a number. The check on people is a
  list of words, not a guarantee.
- A model is sent what it is sent for any dataset: the title, the unit, the places, the periods.
