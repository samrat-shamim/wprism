# Qi Blocks + Visual Portfolio

This scenario runs the exact Qi Blocks 1.5.2 and Visual Portfolio 3.8.1
artifacts through one shared `agent-apply-roundtrip`. It composes the existing
package-owned native fixtures, captures both manifests into one repository,
settles Visual Portfolio's host-owned storage prerequisite, applies both
adapters to a divergent target, and checks each plugin through its native and
HTTP consumers before a zero-write repeat.

The scenario is bounded evidence for these two exact free artifacts together.
It does not authorize production promotion or qualify other versions,
lifecycle transitions, recovery faults, premium code, or other plugin
combinations.

Run it from a clean committed candidate with an owned pair:

```sh
CONF_PAIR=<owned> CONF1_PORT=<even> CONF2_PORT=<next> \
WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
bash integration-scenarios/qi-blocks-visual-portfolio/tests/live/regress_qi_blocks_visual_portfolio.sh
```

The wrapper resolves and validates its physical worktree, acquires the complete
pair namespace, and proves teardown before publishing its sole final PASS.

The exact-source run at commit
`6e31d9194bd05d31482c0c46720ae69feff13bf2` passed on 2026-09-15. Its retained
local transcript was 2,327,433 bytes (527 lines), SHA-256
`c5ab3b0af17b2f41624f52218ef9833394740a9eb85c093528f9e34119905591`.
Capture selected 13 posts, 11 terms, and three media blobs. Initial Apply
reported 22 creates, two updates, two adoptions, one verified provider action,
26 applied/live entities, and no drift. The target retained all 40 deliberately
colliding local rows; Visual Portfolio contributed two selected derivatives to
Qi's image and all selected bytes transferred exactly. Canonical recapture was
byte-identical, both native frontends passed, Qi emitted all 204 CSS owner
frames, and repeat Apply reported 26 unchanged entities with zero creates,
updates, adoptions, actions, warnings, or writes.
