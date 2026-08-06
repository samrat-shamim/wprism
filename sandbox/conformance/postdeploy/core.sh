#!/usr/bin/env bash
# DUO-3209: parent-aware adoption and ambiguous full-key refusal.
set -euo pipefail

# Reverse creation order from the source so a slug-only LIMIT 1 lookup is
# guaranteed to choose the wrong branch for at least one child.
B=$(wp_conf2 post create --post_type=page --post_title='Branch B' --post_name=branch-b --post_status=publish --porcelain)
B_CHILD=$(wp_conf2 post create --post_type=page --post_title='Child B target' --post_name=shared-child --post_parent="$B" --post_status=publish --porcelain)
A=$(wp_conf2 post create --post_type=page --post_title='Branch A' --post_name=branch-a --post_status=publish --porcelain)
A_CHILD=$(wp_conf2 post create --post_type=page --post_title='Child A target' --post_name=shared-child --post_parent="$A" --post_status=publish --porcelain)

# Manufacture an otherwise-impossible duplicate full natural key directly;
# wp_insert_post() would helpfully suffix it. Planning must report the
# ambiguity instead of choosing a row by database order.
DUP=$(wp_conf2 post create --post_type=page --post_title='Ambiguous Child' --post_name=temporary-child --post_parent="$A" --post_status=publish --porcelain)
wp_conf2 db query "UPDATE wp_posts SET post_name='shared-child' WHERE ID=$DUP" >/dev/null
RC=0
OUT=$(wp_conf2 duo plan --repo=/siterepo --adopt-by-slug=posts 2>&1) || RC=$?
[ "$RC" -ne 0 ] && grep -q 'conflicting adoption key.*parent' <<<"$OUT" \
  || fail "duplicate full hierarchical adoption key was not rejected: $OUT"
wp_conf2 post delete "$DUP" --force >/dev/null

echo "target hierarchy seeded in reverse order: branch-b=$B child=$B_CHILD, branch-a=$A child=$A_CHILD; ambiguous-key refusal verified"
