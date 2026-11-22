# AGENTS.md

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Everything runs in Docker, never on the host PHP. Start the app with `make up`
  (`docker compose -f compose.dev.yaml up -d --wait`), then run commands with
  `make console c="..."` and `make composer c="..."`. `make help` lists the rest.
- `compose.yaml` is the visitor's demo (prebuilt image, no build, no mount):
  keep it that way. Development changes go in `compose.dev.yaml`.
- When something fails, read `var/log/dev.log` (or `make app-logs`) before changing
  code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Functional/HTTP tests extend `WebTestCase`; service-level tests extend
`KernelTestCase`. Run `make test` (PHPUnit in the container) and `make stan` (PHPStan,
level 8, no baseline: fix what it reports). After changing a
class in `src/Layout/`, run `make layout-schema` to regenerate
`src/Layout/LayoutSchema.php`; never edit that file by hand. A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Project rules

- A value reaches a page only through the `|figure` Twig filter, from a proof built by
  `App\Proof\Resolver`. Never print a number from a template variable.
- Text written by a model holds no digit: every free-text property in `src/Layout/` carries
  `#[NoDigits]`, in the `render` validation group. Keep that group: Symfony AI validates the
  default group of the final structured output and throws on the first violation, and turns
  built-in constraints (`Regex`, `Length`…) into JSON Schema keywords.
- Tests that need data write their own small table with `tests/Support/FactsFixture.php`.
- The free-text rule lives in `App\Layout\Constraint\FreeText`: digits, numbers in English or
  French words (whole, joined, accented or look-alike, and multipliers; the vocabulary comes from
  ICU for both languages, see `NumberWords`), number signs (`%`, `+`, `−`, currency…), invisible characters (a right-to-left
  override reverses the figure that follows it) and disguised letters. `#[NoDigits]`, catalog
  titles, refusal cards and the `/lie` error list all use it. In a note, consecutive text
  segments are checked together (`PageRenderer::joined()`), and every figure and period is kept
  apart from its neighbours (`PageRenderer::spaced()`).
- Every string of a layout that reaches the page is checked, not only free text: a period is shown
  only if an entry has such a period (`Catalog::knowsPeriod()`), and a refusal card echoes only
  what cannot carry a value (`FigureExtension::requested()`). The axis of a line chart shows only
  periods the dataset covers, and its scale only proven values of its own points, each a link to
  its proof. A new field shown on the page needs the same care: `/lie` (`tests/Lie/`) is where
  visitors try to get a number through.
- A chart has one scale: a bar or a series in another unit, or another kind of period, than the
  first proven one is refused (`RefusalReason::ScaleMismatch`), and a value at or below zero draws
  no bar.
- A value is never shown without its unit: `|figure` writes `unit.label` after it, and the
  Markdown does the same. A unit code with a scale anywhere in it (`THS_PAS`, `CLV15_MEUR`,
  `KTOE`, `P_THAB`…) needs a label that says it ("thousand", "million"): the catalog loader
  refuses it otherwise.
- In a note, the server writes the cell after each figure ("457,644,262 nights (France, 2024)"):
  the words around it cannot say which cell it is. When the model repeats the unit right after the
  figure, its word goes, the server's stays (`FigureExtension::afterUnit()`).
- Free text also refuses web and mail addresses and emoji codes (":one:"): the only links of a
  page are written by the server (its proofs, the sources' credits, the link to the whole page). The Markdown fallback escapes the rest
  (dots before a letter, `@`) as a second line of defence.
- A page shows at most `PageRenderer::MAX_COMPONENTS` components, `MAX_ITEMS` bars or rows,
  `MAX_SERIES` series and `MAX_SEGMENTS` note segments: a small layout (a `/p` link) cannot make
  the server resolve thousands of cells. A line chart places every period asked for that the
  dataset covers, so a missing year leaves a gap.
- `/p/{token}` and the question page (`/`) follow the rules above: a page link is just another layout, checked
  against the same schema as `render_page`, and every figure is proven again.
- The question page builds its platform at run time (`App\Ai\Platforms`) from `AI_PROVIDER`, `AI_MODEL` and
  `AI_API_KEY`. Do not wrap it in the AI Bundle's `TraceablePlatform`: in 1.0.0-BETA1 it breaks
  streamed structured output (symfony/ai#2843). A change to `App\Ai\AskPrompt`, the layout
  schema or `catalog/` invalidates the cassettes of `demo/`: re-record them (`make cassette`).
- Never log to standard output (`php://stdout`): over STDIO it carries the MCP protocol, and one
  log line breaks the host's parser (`tests/Mcp/StdioTest.php`). A page that is shown goes
  through `App\Ui\PageLog`, which logs what it refused on the `go_figure` channel
  (`#[WithMonologChannel('go_figure')]`). Two exceptions: the home page's example
  (`demo/home.json`, fixed and tested), never logged, and the first-visit example of `/compare`,
  logged as `compare-example` so it is not counted as a visitor's own choice.
- The interface is in English and in French (`translations/`, `App\Http\Language`: a cookie set by
  `/language/{en|fr}`). Short texts are their own key (`{{ 'Show the page'|trans }}`), paragraphs
  have one (`home.lead`, with the English in `messages.en.yaml`). Numbers, months and country
  names follow the locale (`FigureExtension`, `Places` and `PageComposer::monthName()` use ICU);
  unit labels of the catalog are translated in the `units` domain, and a catalog entry may give
  `title.fr`, `scope.fr` and `method.fr`; city names and licence texts are translated in the
  `places`, `licences` and `providers` domains. Examples follow the locale: a question of
  `demo/questions.yaml` has a `language` (its answer is written in it) and may name its `twin` in
  the other language (switching the language on `/?q=…` goes to it), and `demo/home.json` /
  `demo/lie/*.json` may have a `.fr.json` twin. `tests/Demo/ShippedLayoutsTest.php` checks what
  each shipped layout hides. MCP (`/mcp…`) always answers in English. Text a model writes is never
  translated. The free-text rule refuses numbers in English and French words, whatever the locale.
- The extended mode (`src/Import/`, `/import`, the MCP tools of `App\Mcp\ImportTools`) adds a
  dataset on this machine. `EurostatApi`, `WorldBankApi` and `CityWeather` fetch from outside, each
  from its own fixed host; for Eurostat a code is checked against `EurostatApi::CODE`, dimension codes against the
  dataset's own, with no redirect and a size and time limit. A proposal names codes and writes
  words (`Proposal`, every rule in the `render` group); `Importer` checks it, keeps the countries of
  the shipped catalog, and stores the entry and its cells in `var/data/imported.sqlite`
  (`ImportStore`, ids start with `x_`). `Catalog::all()` adds them after the shipped entries;
  `Catalog::shipped()` is what the example questions were recorded with (`AskPrompt::system()`
  without imports), so an import never breaks a cassette. The scale of an imported unit is written
  by the server (`UnitLabel`), never by free text: the unit a visitor writes uses only words of
  the dataset's own labels, and no scale word. Subjects about people's lives are left out
  (`EurostatApi::FAMILIES` and `SENSITIVE`, `WorldBankApi::TOPICS`), never the same choice twice (its proofs
  carry the date of the import).
- A calculation (`{"type": "computed"}`) is computed by `Resolver::computed()` from
  proven cells, at one level: the model names the operation and the cells, never a result. Its
  proof (`ComputedProof`) shows the formula and each cell with its own proof; its link is recomputed
  on opening. Never a composite score: an operation stays within one dataset, or one source and
  unit. A new operation needs its rule there, its formula on `proof_computed.html.twig`, its caption
  in `FigureExtension::caption()`, and tests in `tests/Proof/ComputedTest.php`.
- "Add a city" (`App\Import\CityWeather`, `/import/city`) adds the weather of a city of
  `data/cities.csv`: one request to Open-Meteo, the normals computed by `App\Import\Normals`
  exactly as `data/weather.py` does (change both together; their tests share the same cases), stored
  in the import store. `Catalog::all()` adds the city to the shipped climate normals;
  `Catalog::shipped()` never has it.
- The pitfalls of an entry are notes for the model, with hand-written numbers: never on a page or
  a proof page.
- A problem of a page (`Words::$problem`, a period's) holds the English message template; the
  templates translate it where they show it, so logs and MCP stay in English. A streamed response
  runs after Symfony has reset the locale: it sets it again (`LocaleSwitcher::runWithLocale()`,
  see `AskController::events()`).

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).

## The one page, and the data of a visitor (since 2026-11-21)

- `/` is the question page (`AskController::page`, `templates/home.html.twig`); `/ask` redirects to
  it. `/compare`, `/compose`, `/lie` and `/import` still answer, off the menu.
- Without a key, or when the visitor ticks "Answer without AI", a question is read by its words
  (`App\Ask\QuestionReader`): it matches, it does not understand. A change there needs a fresh
  set of questions to measure it (`eval/`, see `DEVELOP.md`): every existing set was used to
  correct it.
- With a key, a question is routed first (`App\Ai\QuestionRouter`, Symfony AI's `MultiAgent`),
  and one question costs at most 14 calls: keep that bound when touching `AskController::events()`,
  `DataScout` or `ProposalAgent`.
- `/data` (`App\Import\FileImporter`) keeps a deposited CSV as datasets. Its places have codes of
  their own (`Places::OWN`), and their names may hold digits: do not show such a name through the
  path of a model's text.
- Three outcomes have three words, on the page and in the Markdown of `render_page`: "Missing" (the
  source has no figure there), "Unsupported" (the model wrote a number itself), "Refused" (the rest).

