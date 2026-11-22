# Go Figure

**A generative UI that cannot make up a number.** Built with Symfony AI and the official PHP MCP SDK,
for the Symfony AI & MCP hackathon (November 2026).

You ask a question. A model writes the answer as a page (a sentence, tiles, charts, tables) and
says which figures it needs: a dataset, a place, a period. It never writes a value. The server reads
each value in its data and links it to its proof, or says it is missing. A number the model writes
in its own words is not shown.

> Every number on the page is proven. What the chat model says around it is its own.

Go Figure does not judge what anyone says. It stops the model from writing a number.

## Run it

You need Docker with Compose 2.24 or later (`docker compose version`). Clone this repository, then
in its folder:

```bash
docker compose up -d --wait
```

Open <http://localhost:8080>. No key, no account. To stop it: `docker compose down`.

The app has no login: it only listens on this machine (`127.0.0.1`), and is not meant to be put on
a network as it is. Anyone who reaches it can add data and, with a key set, spend it within the
limits.

- Port 8080 taken: `HTTP_PORT=8081 docker compose up -d --wait` (PowerShell:
  `$env:HTTP_PORT=8081; docker compose up -d --wait`), then open <http://localhost:8081>.
- Docker answers `denied` or cannot find the image: build it here, then start again: `make image`
  (or `docker build --target prod -t ghcr.io/eazenli/go-figure-php:latest .`).

## What to try, in five minutes

Everything below works without a key.

| Where | What |
| --- | --- |
| [`/`](http://localhost:8080/) | **Ask.** "Is Lisbon or Seville warmer in September?", "House prices in Portugal since 2015", "Which country has the most renewable energy?". Without a key the words of the question are matched to the data, and the page says what it matched and what it did not understand |
| A figure | **Click it**: where it was read, the source, the day. A figure the server computed shows its formula and each figure it used |
| Under an answer | **Copy with source**, the link of the page (opening it reads and proves every figure again), and **try to make it lie**: up to six attacks on that very answer, checked by the server |
| [`/how`](http://localhost:8080/how) | **How it works**, on one page a model could send: a proven figure, a missing one, a number the model wrote itself |
| [`/why`](http://localhost:8080/why) | **Why it is different** from an assistant that cites its sources |
| [`/data`](http://localhost:8080/data) | **Your own file**: deposit a CSV (an example is there to download), name its columns, ask about it |
| [`/import`](http://localhost:8080/import) | **Public data**: add a Eurostat dataset, an indicator of the World Bank or the weather of a city. [`/sources`](http://localhost:8080/sources) tells the two ways apart |
| [`/compose`](http://localhost:8080/compose) | **Explore the available data**: an indicator, places, periods, drawn without AI |
| [`/connect`](http://localhost:8080/connect) | **MCP**: the same pages from your own assistant (see below) |

The image ships twenty indicators: prices, tourism and transport by country (Eurostat), the weather
of 28 cities (Open-Meteo), and eight that have nothing to do with a trip (GDP per inhabitant,
inflation, house prices, renewable energy, municipal waste, forest area, car ownership,
population). Nothing in the mechanism is about travel.

Three example answers of a model replay in one click, through the real provider bridge of Symfony
AI (`demo/cassettes/`, recorded on 2026-11-21 with `claude-sonnet-5-5`): no key, no network.

## With a key

Put a key in `.env.local`, next to `compose.yaml`, then run `docker compose up -d --wait` again:

```dotenv
# anthropic, openai or huggingface
AI_PROVIDER=anthropic
# Leave empty for the default model of the provider
AI_MODEL=
AI_API_KEY=your-key
```

A question then goes through three steps, each built with Symfony AI:

1. **Routing** (`MultiAgent`): answer from the data here, send to the agent that gets data, or
   decline.
2. **Getting data** (an `Agent` and its toolbox): the agent looks for a dataset on Eurostat or at
   the World Bank and proposes it. The server checks it and reads every value itself.
3. **The answer** (structured output, streamed): when the model writes a number itself, the page
   does not show it, and the model is asked once to write its answer again.

At most 14 calls to the model for one question; the agent is limited to 4 runs an hour per visitor
and 40 a day. The key stays on the server. The details are in [DEVELOP.md](DEVELOP.md).

What ran against a real API, and what was only written:

| Provider | Model | State |
| --- | --- | --- |
| `anthropic` | `claude-sonnet-5-5` (the default) | Run live: free questions, the agent, the recorded examples |
| `anthropic` | `claude-haiku-4-5` | Run live; it often writes a year in a label, which the page then refuses |
| `anthropic` | `claude-sonnet-4-5` | Refused before any call: Symfony AI 1.0.0-BETA1 lists it without structured output |
| `openai` | `gpt-4.1-mini` (the default) | Written, never run with a real key |
| `huggingface` | `openai/gpt-oss-120b:cerebras` (the default) | Written, never run with a real key |

Anthropic needed three corrections before its first answer ([FRICTION.md](FRICTION.md)): expect the
two others to need theirs. A wrong setting never breaks the app: questions are then read by their
words, and the page says what failed.

## From an MCP client

- **HTTP**: `http://localhost:8080/mcp/go-figure`. Opening this folder in Claude Code, VS Code or
  Cursor offers the server (`.mcp.json`, `.vscode/mcp.json`, `.cursor/mcp.json`; change the port
  there too if you changed it).
- **STDIO**, for clients that start the server themselves, such as Claude Desktop:

  ```json
  {
      "mcpServers": {
          "go-figure": {
              "command": "docker",
              "args": ["exec", "-i", "go-figure", "php", "bin/console", "mcp:server"]
          }
      }
  }
  ```

  On macOS, give the full path of `docker`. On Windows with Docker in WSL, use
  `"command": "wsl.exe"` and put `"docker"` first in `args`.

Nine tools: `render_page`, `list_datasets`, `describe_dataset`, and, to add data from your own
assistant, `search_eurostat`, `describe_eurostat_dataset`, `add_dataset`, `search_world_bank`,
`add_world_bank_indicator`, `add_city`. One prompt: `proven_page`.

`render_page` takes a page in which the model names figures and never writes a number. A host that
shows MCP Apps displays the page; a host that reads text gets Markdown where every figure links to
its proof, and the link of the whole page.

Tried by hand: the MCPJam inspector, and a text host (Claude Code), on 2026-11-21. Not tried: a
host that shows MCP Apps, and the Hugging Face tiny agent of `agents/go-figure` (no token). What
the runs showed is in [DEVELOP.md](DEVELOP.md).

## How is this different from an assistant that cites its sources?

| | An assistant that cites its sources | Go Figure |
| --- | --- | --- |
| Who writes the number | The model, after reading some pages | The server; the model only names where to read it |
| What the source shows | A page the number is said to come from | Exactly where it was read: dataset, place, period, the day |
| When the data has no answer | It may still give a plausible number | A refusal, in full view |
| How you check a figure | You open the page and look for the number | One click: where it was read, the source, the day |

Such an assistant is usually right; what you cannot do is tell when it is not. It is not retrieval
(RAG) either: with retrieval the model is handed passages that hold the figures and copies them;
here it is handed titles, places and periods, never a value (a test checks it).

Prior art: *Proof-Carrying Numbers* ([arXiv:2509.06902](https://arxiv.org/abs/2509.06902)) lets a
model write numbers in its text, then checks each one against its source before it is shown. Go
Figure takes the other way round: the model never writes a value, it names where to read it, and
the server writes every figure.

## What it does not do, and what was measured

- It answers only on what its data holds, and cannot promise that the figure it read is the right
  one for the question. So each block says what its figures count, and the page says which words
  it did not understand.
- **The reading without a model was measured twice on questions we had not seen**, written by the
  second member of the team: first run 5 questions of 23 with the expected figures, then, on a
  second set after corrections, 9 of 15. Both sets were then used to correct the reader, so they
  measure nothing any more. The detail, failures included, is in [eval/README.md](eval/README.md).
- Not measured: the answers of a model, and the agent that gets data.
- Not tried: OpenAI and Hugging Face with a real key; an MCP host that shows the pages; the French
  interface with a native reader (hidden: `LANGUAGE_SWITCH=1` shows it).

## Where it goes next

- A figure measured on questions nobody here has seen, and the answers of a model scored.
- Your own data, further: a spreadsheet, a database read in place, a file deposited through MCP.
- The gap between two places, computed and proven: the one figure a model is tempted to work out.
- Follow-up questions with memory; the agent replayed without a key; short links to a page.
- A wider catalog, found by meaning (embeddings) rather than by a list of words.
- The pattern as a Symfony bundle, for any application that cannot afford a made-up number.

## Develop

`make up` starts a development container with the source mounted, `make test` and `make stan` check
it, and nothing runs on the host PHP. How the data, the catalog, the example questions, the
evaluation and the tests fit together: [DEVELOP.md](DEVELOP.md).

## Team

Built by [@Eazenli](https://github.com/Eazenli) (data) and [@StephenF13](https://github.com/StephenF13) (Symfony and MCP), for the Symfony AI & MCP hackathon.

## License

MIT, see [LICENSE](LICENSE).
