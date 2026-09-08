# Managed native duplication is not production-ready

The 2026-09-09 Terra review verified the official Yoast Duplicate Post **4.7**
ZIP against this capsule's artifact lock:
`8cbeebf7c2ee982d2ae33833088ba8ac2391cc316a4dd71800a07f5fa128b418`.
Its `admin-functions.php:59` registers the legacy metadata copier;
`:346-408` excludes only the configured/default blacklist and copies every
remaining key with `add_post_meta()`. `duplicate_post_create_duplicate()`
fires that hook at `:754`. Neither `src/utils.php:165-174` nor this capsule's
seeded blacklist excludes `_wprism_uuid`.

The only conformance call to `duplicate_post_create_duplicate()` is
`tests/conformance/seed.sh:105`, before the first WPrism Capture. It proves
native cloning of an unmanaged source, not cloning after the original has
durable WPrism identity. The certified native-clone claim therefore exceeded
its evidence. That initial review was source evidence; the follow-on native
post-Capture reproduction is recorded below. The separate
[Duplicate Page investigation](../../../docs/agents/managed-clone-identity.md)
executes that mechanism with another real plugin and verifies WPrism's exact
refusal plus complete SQL/canonical preservation.

The capsule is now **experimental / unready**. Earlier option, `_dp_original`,
role-provider, lifecycle, deletion and round-trip tests remain available;
they do not authorize the missing managed-clone workflow. No runtime provider
or manifest rule changed, and no fixture silently adds a UUID blacklist.
The same exact 4.7 ZIP is retained as an exercise fixture, not a certified
boundary; its URL and SHA-256 are unchanged.

## Native refusal checkpoint

Exact engine source: `da65c71d019c05f98effd13136e32d0e2e409253`.
One owned MariaDB pair, `ydclone01` on ports 9550/9551, ran WordPress 7.1,
PHP 8.3.33 and the platform-locked Twenty Twenty-One **2.8** theme. The
only active plugin was the exact 4.7 ZIP above. The shared conformance
runner consumed this capsule's existing seed and artifact lock with only
the supported `mode: capture-plan` fixture override. Its two Captures were
deterministic, lint passed, and the actual capability/Plan paths retained
the experimental `authored_state_not_certified` blocker. No deploy or Apply
was attempted; the historical round-trip fixture remains available, not green
production authority under this disposition.

After that completed profile, the native admin API duplicated already-captured
post **3100001** into post **3100004**, using the seeded blacklist unchanged.
The native writer was the same `duplicate_post_admin_init()` registration and
`duplicate_post_create_duplicate()` call chain as the original seed. This is
native API evidence, not a browser/UI claim. Both posts then owned the original
UUID. Two ordinary Captures independently exited **1** with a redacted
`capture_failed` envelope and a fresh, exact one-node private duplicate-identity
cause. Neither chose an owner, minted a replacement identity, or repaired the
clone.

Complete unfiltered SQL for **16 tables / 228,645 bytes** remained identical
at the cloned, first-refusal and second-refusal boundaries:
`3289b85c5c75b3c6a28f23735fbf8f0e8e96fe2d855a5851478350511848aea1`.
All **seven canonical state files** also matched the pre-clone baseline.
The shared SQL reader independently projected every column of the complete
posts, postmeta, map and state tables and matched the native observer and clone
result. Full native column metadata stayed unchanged. Original map/state rows
remained exact throughout; no fresh mapping or history was manufactured.
Debug/logging was enabled, display disabled, new WP-Cron spawning disabled,
and native debug output absent. No SQL rows were omitted from preservation.

Private host-only records in the owning worktree's `sandbox/tmp/`:

- `ydp-native-recon.RHc5lo/`: complete native streams, column rosters, SQL,
  state trees and source binding. `producer-inputs.tar` SHA-256 is
  `f900c50f5efa9217a296700bfa428d145fae546032c6fe2581a09d829615f5c4`;
  it retains four reviewed scratch inputs plus four macOS AppleDouble metadata
  members. Producer bytes were checked against it before and after observations.
- `wprism-conformance-capture.ydclone01.Vt58tG/` and
  `wprism-conformance-capture.ydclone01.bHqxOz/`: independent fresh-delta
  private cause transports, admitted by the shared exact-graph verifier.
- `ydp-admit-v1.*` and post-teardown `ydp-admit-v2.*`: exit 0, empty stderr;
  admission binds the reviewed engine/archive addresses, exact completed
  capture-plan marker, native premises, SQL projections and preservation.
- `ydp-native-recon.Npc4DN/` and `ydp-native-v1.*`: the retained first attempt.
  Capture-plan passed, but the diagnostic driver refused an underscore-bearing
  column-stage label before cloning. The corrected attempt used hyphenated
  labels and the platform lock's 2.8 theme premise, not the earlier standalone
  Duplicate Page pair's 2.9 theme. No failed attempt became a success record.

The clone was not deleted to simulate recovery. The shared ownership helper
destroyed the owned pair, both disposable databases/site roots and its lease;
the complete admission passed again afterward. No foreign pair was changed.
These private diagnostic archives are not shipped certificates or portable
reproduction tooling. This is one managed-clone refusal case, not complete
native cloning semantics, combinations, recovery, or readiness evidence.

## Restoration boundary

The native collision and exact refusal-preservation premise are now covered.
Restoration still requires a reviewed explicit identity-repair/recovery
workflow and complete successful managed-clone round-trip evidence, including
its failure/uncertainty cases. A deliberately configured safe
blacklist is a separate case: prove its native exclusion, fresh Capture mint,
full round-trip and continued operator ownership of that setting; do not
substitute it for the default workflow.

The changed disposition moves this adapter's digest and combined compiled
policy identities. Existing deployments must recompile and explicitly re-pin
the reviewed claim. Do not fall back to the former certification or conceal
the experimental readiness refusal.
