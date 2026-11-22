# Upstream contributions

Issues and pull requests sent to Symfony AI and the PHP MCP SDK, with their status and the
workaround this repository uses meanwhile.

## Found while building this project

| Package | Issue / PR | Found while building | Status | Workaround in this repo |
| --- | --- | --- | --- | --- |

Nothing reported by us yet. One surprise was already fixed upstream when we met it (the profiler's
`TraceablePlatform` breaking a streamed structured output: symfony/ai#2843, see `src/Ai/Platforms.php`).
Three others, met on 2026-11-19 with the Anthropic bridge of Symfony AI
(a schema with `anyOf` next to `type`, a schema too large once every class is written in full,
`"pattern": null` for a constraint without an HTML pattern) are described in
[FRICTION.md](FRICTION.md) with their workaround; they will be reported here once we have checked
that they are not already known.

## Spotted while reading the code

| Package | Issue / PR | Found while building | Status | Workaround in this repo |
| --- | --- | --- | --- | --- |

Nothing reported yet.
