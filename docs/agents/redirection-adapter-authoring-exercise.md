# Redirection adapter authoring exercise

Date: 2026-08-27  
Candidate base: `b7df34403f63759e426dd3a5ce39fee27f35a380`  
Subject: Redirection 5.9.0 (`redirection/redirection.php`)  
Exercise mode: one user prompt; coding agent performs both the product-user
workflow and adapter implementation.

This is an interaction and evidence record, not a transcript of private model
reasoning. It records every user message in this exercise, the agent's visible
updates, commands and outcomes, including failed attempts and autonomous
recovery. Secrets, raw request logs and hidden chain-of-thought are not
recorded.

## User interaction transcript

There was one user interaction and no follow-up question was required.

### U1 — user

> now lets test and harden the adapter authoring and management system through
> real life exercises. select one popular plugin. author the adapter for it,
> refine the guide/machinary along the way. imagine that a user of the platform
> will instruct their coding agent to author the adapter. The agent should be
> able to follow the process and complete the work without more user
> interaction in most cases. you play the role of the user and the agent both.
> record all user interactions along the way.

### A1 — agent-visible response and updates

The agent accepted the task without a clarification question and stated these
working decisions as it progressed:

1. Use a clean worktree from latest `origin/main`, select a popular unsupported
   plugin from repository evidence, and preserve all interaction/recovery
   evidence.
2. Select Redirection because its
   [official WordPress.org listing](https://wordpress.org/plugins/redirection/)
   reports more than two million active installations and the repository's
   engine-gap ledger already named an unresolved typed-table boundary for it.
3. Treat the first draft as a user-facing usability test, including command
   refusals and recovery, rather than skipping directly to a hand-authored
   shipped manifest.
4. Widen the engine only after native API rows proved that one column genuinely
   uses plain, serialized and `NULL` storage frames.
5. Test a hostile target with divergent IDs, activation residue, a target-only
   redirect, request logs, 404 history and a primed negative cache.
6. Add an explicit native-writer framing question to the draft machinery and a
   one-prompt autonomy/escalation contract to the guide.
7. Treat a clean-room live failure as new authoring evidence: activation alone
   is not onboarding, so use and verify the plugin's public database installer
   on both sides without asking the user how to recover.

No user choice was requested after U1. All recoveries below followed typed
command output or evidence already available in the repository, official
artifact or disposable target.

## Agent exercise log

### 1. Select and pin the subject

- Created worktree `duo-wp-redirection-adapter` and branch
  `codex/redirection-adapter-exercise` from the exact base above.
- Downloaded official WordPress.org artifacts into ignored
  `sandbox/tmp/redirection-authoring/` scratch.
- Measured SHA-256:
  - 5.9.0: `b6dd341a6b383440cbb1d5647fa59a82fc5e48afe2165539284c56edaf066842`
  - 5.8.1: `6256d0829120a6661da5e6698cab5f3ead6a830c6d67e4a89a064649fb59ef79`
- Chose the honest compatibility interval `[5.9.0, 5.9.1)`: 5.9.0 is the
  admitted exact artifact; 5.8.1 is an official below-range refusal control.

### 2. Exercise the product through native APIs

The first frozen-candidate conformance run exposed a missing premise before Duo
captured or applied anything. Exact Redirection 5.9.0 activated successfully,
but its four tables were absent; the first `Red_Group::create()` failed with
`Table '...redirection_groups' doesn't exist`. Inspection of the same pinned
artifact found Redirection's documented public command, `wp redirection
database install`. The agent stopped the idle pair, added that native setup to
both source and post-deploy target fixtures, and added value-level readiness
checks for all four tables, the database version marker and default groups.
No raw SQL schema creation and no user decision were needed.

The replacement run found a second fixture assumption at the target identity
boundary. A target-only mapped row must be minted before first apply, but a
capture against `/siterepo` already sees the source's canonical mapped UUIDs;
with no target ledger for those source UUIDs, capture correctly refused with
`mapped identity history is missing`. The agent retained the target for
diagnosis, proved its onboarding and hostile rows were intact, then used the
normal minting capture against an isolated disposable policy root. The first
isolated version copied the whole site policy and exposed another real ordering
error: it also minted the target's default category, so first apply correctly
refused `adopt term identity contradicts the exact physical identity row`.
Narrowing the disposable policy to only the Redirection manifest, with empty
post and taxonomy scope, then exposed more of capture's deliberate global
audit before the next apply. WordPress's default block widgets caused an
undeclared-sidebar refusal, and omitting the core manifest caused the required
`active_plugins` option to refuse rather than guess its autoload semantics.
Keeping core's grammar then made its `default_category` reference visibly
unresolvable while the category stayed out of scope. The agent retained core
grammar, classified both the default category and its referencing option as
runtime in the disposable policy, and quiesced the unrelated widget options
through WordPress APIs around that one capture, with an exit trap and a hash
assertion proving exact restoration. A diagnostic capture then wrote only
Redirection group/item identity files plus disposable scalar core options.
That established mappings only for Redirection's current rows and left core
identities, core content and the real source branch untouched. The fixture now
encodes the ordering and an offline regression prevents the canonical-root
shortcut, a cross-plugin identity mint, or leaked widget mutation from
returning.

The next clean-room run passed that bootstrap and reached the real apply, which
refused `options/core.json` as changed on both sides. Private refusal evidence
showed the disposable capture had updated the target's three-way base; restoring
the widgets then looked like a target edit while the source repository was the
other edit. The agent restored capture's existing `--out` mode to the isolated
pass. That mode still commits new `duo_map` identities but deliberately skips
canonical `duo_state` and media publication, exactly matching this fixture's
intent. The regression now requires both the isolated policy and its output-only
destination. The same activation finding was propagated to the exact-version
matrix target before running it: that target now completes and verifies the
public database install before it truncates any boundary fixture tables.

After that public onboarding step, the agent used Redirection's `Red_Group`,
`Red_Item` and `Red_Options` APIs to create a realistic summer marketplace
campaign:

- ordinary `/summer` -> marketplace 302;
- regex vendor route -> provider route 307;
- French `Accept-Language` conditional 302;
- retired-service 410;
- Unicode group/title/content;
- portable options including a group-id reference;
- real source requests that populated hit counters and request logs.

The native rows established four independently reviewed boundaries:

- `redirection_groups` and `redirection_items` are authored; IDs are local and
  require mapped identities;
- `group_id` and `redirection_options.monitor_post` are `red_group` references;
- `redirection_logs`, `redirection_404`, hit counts and last-access time are
  runtime state;
- `redirection_items.action_data` is a tagged storage union: two plain URL
  strings, one canonical PHP-serialized conditional map and one SQL `NULL`.

### 3. Run the unknown-plugin authoring workflow

The initial `wp duo init --allow-unmanaged-plugins` correctly captured core,
left Redirection unknown, and preserved the user's ability to author an
adapter. Coverage then reported one invisible option group with prefix
`redirection` and four undeclared tables. `adapter-observe` remained
`authority:false` and value-redacted. `adapter-probe` returned schema, keys,
indexes and nullability but no row values.

The draft interaction found two recoverable usability edges:

1. The first seed filter was
   `^(redirection_options|redirection_(groups|items|logs|404))$`. It selected
   the four logical table names but no option proposal, because coverage
   publishes the ownership prefix `redirection`, not the hidden option name.
2. Re-running to the existing output correctly refused with
   `draft_output_exists` and named `--force` as the recovery.

The agent did not ask the user what to do. It inspected the seed candidate
counts, changed the filter to `^redirection`, and reran with `--force`. The
corrected draft contained the option namespace/pattern and all four tables,
while the create-only guard preserved the rule that a human-edited draft is
never silently overwritten.

One operator error also remained in the record: an early manually composed
pair command omitted the pair's exported Compose variables and produced a
database connection failure. The agent recovered by supplying the exact
`DUO_PAIR`, ports and CLI image printed for that pair. No product code or user
decision was involved.

### 4. Refine generic machinery

The draft/probe boundary was the substantive gap. A rowless schema probe can
say `mediumtext`; it cannot reveal that different native writer variants use
plain text, serialization and `NULL`. The exercise changed generic machinery:

- `duo adapter-draft` now adds a review question requiring every native writer
  variant for text/blob columns and explicitly says `adapter-probe` reads no
  row values;
- typed-table proposals now say activation is not proof of onboarding and
  require the plugin's public admin/API/CLI setup plus expected-storage
  verification before inventory or probe evidence is trusted;
- the authoring guide explains prefix-scoped seed recovery, safe `--force`
  recovery, agent autonomy/escalation, and storage-framing review;
- `php_serialized_or_text` is a closed, feature-gated column codec under
  `mixed-column-codecs/v1`; strict `php_serialized` semantics did not change;
- plain text uses ordinary tokenization, canonical serialized arrays rewrite
  only string leaves, `NULL` passes through, and malformed serialized-looking
  bytes, secrets and other PHP types fail closed.

### 5. Graduate to a shipped adapter

The site draft was evidence, not authority. The reviewed shipped result is:

- `manifests/redirection.json`;
- `manifests/dispositions/redirection.json`;
- `manifests/providers/redirection-state.php`;
- exact artifact locks and conformance entry;
- offline grammar/provider/manifest regression coverage;
- exact-artifact boundary and below-range refusal coverage;
- a full live hostile-target conformance scenario.

The provider accepts only exact 5.9.0, single-site, WordPress module id 1. It
uses Redirection's public module/cache APIs, advances the plugin's cache
generation only when enabled, and verifies every group/item through native API
and lookup readback. Its receipt contains counts, hashes and cache-generation
state, never redirect values or request history. Apache/Nginx server-file
modules, multisite and deletion remain explicit refusals.

### 6. Real-world acceptance matrix

The live scenario exercises:

- exact artifact install, deploy, apply and byte-identical recapture;
- clean and dirty targets;
- source/target group and item IDs forced into different ranges;
- activation-created and target-only authored residue;
- ordinary, regex, language-conditional and error HTTP behavior;
- plain/serialized/`NULL` raw framing and native API agreement;
- target-only logs, 404 history, hit counters, neighbor options and negative
  cache preservation;
- provider cache rotation with a value-free receipt;
- zero-plan/zero-apply idempotence;
- malformed serialized-looking and credential-shaped capture refusals;
- native competing edits, atomic unforced conflict refusal and explicit
  repository-authority convergence;
- deactivate/deploy/reactivate recovery;
- unsupported server-module post-commit failure, retained retry authority,
  no premature cache effect/revision advance, and exact repair;
- deletion refusal and provider fault/stale-read cases offline;
- policy coexistence with WooCommerce, ACF, Polylang and the Rank Math site
  adapter under both tested pin orders.

## Outcome

The one-prompt path required no additional user interaction. The important
result is not merely a seventeenth manifest: the authoring system now tells the
next coding agent where schema evidence ends, how to recover a seed filter and
an existing draft safely, which decisions it can make autonomously, and which
ones require new user authority.
