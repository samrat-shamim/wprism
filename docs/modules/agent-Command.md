# agent: Command

**Purpose.** The `wp duo …` WP-CLI surface: verb dispatch, argument parsing, refusal envelopes and operator output.

**Directory** `agent/src/Command/` &middot; **layer** `surface` &middot; **files** 1 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Cli`.

**May depend on:** `Adapter`, `Apply`, `Assess`, `Capture`, `Cloud`, `Code`, `Command`, `Init`, `Kernel`, `Policy`, `Promotion`, `Publication`, `Repository`, `Review`, `Scope`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Assess` (intra-layer, 1 edge) — designed: Command dispatches the Assess projection; the reverse edge is forbidden.

**Must not depend on.** Nothing below it is forbidden, but Command must hold no mechanism of its own: every branch delegates to a module entry point and every refusal goes through CommandRefusal.

**Known debts.**

- `Cli.php` references 37 other files and is the whole surface layer. It carries the undocumented `wp duo` verbs (verify-canonical, identity-export/import, journal-report/reset, orphans, promotion-begin/abort, code-preflight/stage/finalize, refresh-export, policy-to-manifest, manifest-pin, adapter-survey).
- `sandbox/tests/spike/check_guide_commands.sh` reads this file by path; the move must update it.

**Sub-namespace plan.** Target `Duo\Command\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.
