# Evaluation questions

`questions.jsonl`: one JSON object per line, with the question and the cells a right answer names
(`dataset`, and `geo` or `time` when the question says them). An empty `expected` is a trap: the
data cannot answer it, and the right outcome is no page.

The expected cells were written from what each question asks, before running anything.

```bash
make console c="app:eval --words"   # the questions read without a model: no key, no network
make console c="app:eval"           # a model's answers, replayed from eval/cassettes/ (none recorded yet)
```

`questions-wide.jsonl` covers the indicators added on 2026-11-21 (GDP per inhabitant, inflation,
house prices, renewable energy, waste, forest, cars, population). It was written with the words it
tests: a guard against regressions, not a measure. `tests/Eval/ShippedQuestionsTest.php` replays
every other set but `questions-fresh.jsonl` against the shipped catalog and data (two questions of that
set do not pass, and are kept as they are: one in French asked on the English page, and "cheap
flights", answered with the flights while the page says that "cheap" was left out).

`questions-team2-fr.jsonl` and `-en.jsonl`: a second set by the second member of the team
(2026-11-21), twenty questions; the English file is a translation. Its first run, before any
correction, is in the README. For two of its questions (the nights in France, the tourism of
2023) the expected cells were widened afterwards to the two readings the page shows.

`must` in an expected cell lists the places a right answer cannot do without (the cheapest of all,
on a page that says "the cheapest"), read from the data by a query, not from the page: without
it, a ranking the wrong way round would count as good, since it shows ten real figures.

## What was measured

A figure can be exact and still not be the one the question was about: the proof says the value is
the cell's, not that the cell is the right one. Two things are done about it, and one is measured.

- Under its title, each block says what its figures count, in a sentence of the catalog ("hotels
  and similar accommodation", not all tourist accommodation).
- Without a model, a vague word gets both readings side by side ("tourism": all accommodation, and
  hotels alone); the words that were not understood are said above the answer ("left out: beer"),
  as is a year the data does not have; a question whose only clue is a vague word next to a word
  that was not understood gets no page; nor does a comparison with a name the data does not know,
  when that name is written with its capital.
- **Measured once, on questions we did not write for the reader** (`eval/questions-team-en.jsonl`
  and `-fr.jsonl`, thirty questions each, written by the second member of the team as a visitor
  would ask them; `make console c="app:eval --words --lang=en --questions=eval/questions-team-en.jsonl"`).
  First run, the reader untouched: 5 questions of 23 answered with the expected cells, 17 with no
  page, 7 figures sourced but wrong, 6 traps of 7 declined. The main cause: "which country is
  the cheapest?" names no place, and the reader wanted one.
- **Corrected since**: a question that names no place gets the ten highest or lowest of all the
  places; a price word with "hotel" is the price index, not the nights; a year the data does not
  have, asked alone, gets no page; a season is its three months. Every set but `questions-fresh.jsonl` now passes (the team's two, and ours), which says little:
  each was used to correct the reader. Our own sets passed almost whole while the team's first
  run answered 5 questions of 23: questions written by the author of the reader measure what it
  already reads. The next honest figure needs questions nobody here has seen.
- **Measured a second time, on a second set nobody here had seen** (`eval/questions-team2-fr.jsonl`,
  twenty questions in French by the same second member, asked as a visitor would; the English
  file is our translation). First run, the reader untouched: 9 questions of 15 answered with the
  expected cells, 3 with no page, 3 with the wrong figures (the ten most expensive countries for
  "the most affordable", rail passengers for "take the train most often"), 2 traps of 5 declined.
  The three traps answered: the water temperature at Nice (the air was shown), the rain of tomorrow
  (the monthly average was shown), and the satisfaction of inhabitants (the population was shown,
  through a word added the same evening with the wider catalog).
- **Corrected since, so this set measures nothing any more**: "affordable" and "not too expensive"
  rank from the lowest; two months named are both shown; a country asked for a figure that only
  its cities have gets its cities, said first; "water", "tomorrow" or "forecast" get no page;
  "inhabitants" alone names nothing. For two questions the expected answer was widened by us:
  it named all tourist accommodation, the page shows that and hotels alone side by side, and we
  kept the two readings.
- `eval/questions-wide.jsonl` (the eight indicators added last) is of that kind: written by the
  author of the words it tests. It measures nothing; with the other sets it is replayed by the
  tests against the shipped catalog, so that a new entry cannot quietly take the words of another.
  Adding these entries turned one trap of ours into a question: "What is the GDP of France?" is
  now answered with the GDP per inhabitant, which the page says in its title.

Not measured: a model's answers (no evaluation recording yet) and the agent that goes and gets data.
