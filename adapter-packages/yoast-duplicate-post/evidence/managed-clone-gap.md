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
its evidence. This review is source evidence; a native Yoast post-Capture
clone run remains pending. The separate
[Duplicate Page investigation](../../../docs/agents/managed-clone-identity.md)
executes that mechanism with another real plugin and verifies WPrism's exact
refusal plus complete SQL/canonical preservation.

The capsule is now **experimental / unready**. Earlier option, `_dp_original`,
role-provider, lifecycle, deletion and round-trip tests remain available;
they do not authorize the missing managed-clone workflow. No runtime provider
or manifest rule changed, and no fixture silently adds a UUID blacklist.
The same exact 4.7 ZIP is retained as an exercise fixture, not a certified
boundary; its URL and SHA-256 are unchanged.

Restoration requires native post-Capture duplication with the ordinary
blacklist, an exact two-owner/private-cause preservation proof, and a reviewed
explicit identity-repair/recovery workflow. A deliberately configured safe
blacklist is a separate case: prove its native exclusion, fresh Capture mint,
full round-trip and continued operator ownership of that setting; do not
substitute it for the default workflow.

The changed disposition moves this adapter's digest and combined compiled
policy identities. Existing deployments must recompile and explicitly re-pin
the reviewed claim. Do not fall back to the former certification or conceal
the experimental readiness refusal.
