# tools/codemod

One-shot migrations. Each script here is written for a specific train, keeps
working afterwards (re-running is a no-op), and is covered by a self-test in
`tests/Tooling/`.

## move-modules.php — the ROUND 3 TRAIN 1 module move

Moves the flat `agent/src` (224 files) and `cli/src` (48 files) trees into the
module directories named by `tools/modules.json`, **keeping the flat
`namespace Duo;` / `namespace Duo\Orchestrator;` declarations**. Directory and
namespace are already decoupled — `agent/duo-classmap.php` and
`cli/duo-classmap.php` map FQCN to path — so `agent/src/Kernel/Canon.php` may
go on declaring `namespace Duo;`. Nothing about the dependency-free contract
changes: every hand-written `require_once` survives, re-pointed at the file's
new location.

```bash
php tools/codemod/move-modules.php --plan          # print every move and rewrite; change nothing
php tools/codemod/move-modules.php --apply         # do it, regenerate both classmaps, lint the result
php tools/codemod/move-modules.php --plan --tree=agent
php tools/codemod/move-modules.php --plan --root=/some/checkout --map=/some/modules.json
```

| flag | meaning |
| --- | --- |
| `--plan` | print the full diff it would make; touches nothing |
| `--apply` | perform it; exits non-zero if any `php -l` fails |
| `--root=<dir>` | repository root (default: this script's own repo) |
| `--map=<file>` | module map (default: `<root>/tools/modules.json`) |
| `--tree=agent\|cli\|all` | restrict to one tree (default `all`) |
| `--allow-partial` | skip the totality guard. Rehearsal and tests only — the real train needs a total map, and that guard is what proves it is one |

The map's top level also carries `$comment`, `ladder`, `rules` and `not_moved`;
a key is treated as a tree only when it carries both a string `root` and a
`modules` object. A module named `"."`, and anything in a tree's optional
`keep` list, stays where it is.

### What it rewrites

1. **`__DIR__`-relative requires inside moved files.** Same-module targets stay
   `__DIR__ . '/X.php'`; cross-module ones become `__DIR__ . '/../Kernel/X.php'`.
   Token-aware, so the guarded form
   (`if (!class_exists(X::class, false)) { require_once __DIR__ . …; }`) is
   preserved by construction — only the string literal is replaced, never the
   `false` argument that keeps every shadow-block suite working.
2. **`dirname(__DIR__, N)` path bases** in moved files become `N+1` (the delta
   is computed from the real old/new depths). Every site is listed in `--plan`;
   sites whose context is not a `. '<string>'` concatenation are additionally
   listed under REVIEW.
3. **Literal `agent/src/<Basename>.php` / `cli/src/<Basename>.php`** anywhere in
   the repo — PHP, shell, Makefile, YAML, Markdown, `phpstan-baseline.neon`
   `path:` lines. A negative lookbehind keeps `myagent/src/Canon.php` out.
   Directory-level mentions (`agent/src` not followed by `/<Basename>.php`) are
   left alone, so `phpstan.neon.dist`'s `paths:` entries do not grow a segment.
4. **The two loaders**: `agent/duo.php`'s 92 `__DIR__ . '/src/X.php'` lines and
   `cli/duo`'s equivalents. Every other byte — including the
   `define('DUO_AGENT_VERSION', …)` / `define('DUO_SPEC_VERSION', …)` lines the
   registry and every certification bundle bind — is preserved.
5. **Templated per-class requires** (`require_once "$root/agent/src/$file.php";`
   inside a `foreach`), which no path rewrite can reach because the filename is
   a runtime value. They are re-pointed through `agent/duo-classmap.php`. The
   four sites in `cli/src` (`AdapterCatalog`, `AdapterDraft`, `ManifestValidate`,
   `RefreshPlan`) are named explicitly and their absence is a hard failure.
6. **Tree-root variables** (`$realSrc = "$repoRoot/agent/src";` then
   `"$realSrc/PublicationJournal.php"`), including a destination the file
   establishes with `cp -R`; **templated lists** whose entries are bare class
   names (`foreach (['StatusCommand', …] as $parser)` joined with
   `'/cli/src/' . $parser . '.php'`); and **`<base> . '/src/Name.php'`** where
   the base is bound in another process, which is resolved only when the name
   belongs to exactly one tree (four basenames exist under both, and those are
   reported rather than guessed).
7. **Source-text require assertions** — 45 suites assert a require by its text
   (`str_contains($captureSource, "require_once __DIR__ . '/Workflow.php';")`).
   The haystack's origin is resolved from its own assignment line, so the
   needle can be re-based. One that cannot be resolved is reported, never
   guessed.
8. **Data and scanner files**: `tools/layers.json` keys, the sorted
   `tools/layers-exceptions.json` edge list (re-sorted, because
   `regress_agent_src_requires.php` asserts SORT_STRING order), and every
   non-recursive `glob()`/`scandir()` over a source tree that would otherwise
   return zero files and pass vacuously.

After `--apply` it regenerates both classmaps, deletes the stale
`sandbox/tmp/affected-index.json`, re-pins the golden block hashes in
`regress_ecommerce_developer_static.sh` from the suite's own hash function, and
runs `php -l` over every moved or rewritten PHP file.

### What it deliberately does not touch

- **`manifests/**`** — digest-bound certification evidence. Its recorded
  closure paths are re-earned by re-certifying, never by a codemod. Every
  mention is counted and reported instead.
- **`tools/codemod/**` and `tests/Tooling/MoveModulesTest.php`** — they speak
  about the pre-move layout by construction (the expectation table is keyed on
  pre-move paths; the self-test asserts `git mv agent/src/Canon.php …`).
  Rewriting them would make the tool describe a move it can no longer perform.

### Constraints the MAP must satisfy (the codemod cannot fix these)

`sandbox/tests/regress_agent_src_requires.php` reserves the `src/Kernel/` rung:
it must have **no upward reference at all**, and `tools/layers-exceptions.json`
may **never** baseline a `src/Kernel/` edge. A map that puts a file with an
upward edge into `Kernel` fails that suite after the move, and the fix is the
map, not the codemod.

### Re-running

`--apply` is idempotent. A file already at its module path is recorded as
already-moved, every rewrite either no-ops or is recognised in its applied
form, and a second run leaves the tree byte-identical.
