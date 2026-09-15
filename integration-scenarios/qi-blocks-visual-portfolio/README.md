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
CONF_EXPECTED_SOURCE_SHA=<candidate-sha> WPRISM_SOURCE_ROOT=<candidate-worktree> \
bash integration-scenarios/qi-blocks-visual-portfolio/tests/live/regress_qi_blocks_visual_portfolio.sh
```
