# Qi Blocks adapter

Experimental authoring capsule for the exact free Qi Blocks 1.5.2 artifact.
Production qualification is incomplete. The package contains declarations only;
block values, PHP containers and media effects use shared engine machinery.

The native fixtures retain saved content and raw options from WordPress 7.1
with Qi Blocks 1.5.2, Contact Form 7 6.1.7 and WooCommerce 11.0.0. The authoring
inventory distinguishes observed defaults from empty media controls still
requiring native Save/reopen coverage.

Known upstream behavior remains visible: the typography configuration request
rejects numeric defaults although its separate CSS request succeeds; widget-only
style storage uses stdClass while Qi's frontend configured-style guard requires
an array. The adapter must preserve native storage types and must not manufacture
successful native writer evidence.

The current draft is not ready to merge. Its native content regression passes
474 assertions. Its native option regression intentionally remains failing:
the shared reference codec changes the owning page map key from 13 to 813 but
leaves `body[class*="-13"]` in saved CSS selectors. Qi emits those selectors
verbatim. The fix belongs in the shared declaration-driven reference codec;
this capsule does not add a plugin executable or suppress the regression.

Run the current portability probes directly:

```bash
php adapter-packages/qi-blocks/tests/offline/regress_native_content.php
php adapter-packages/qi-blocks/tests/offline/regress_native_options.php
```

Manifest grammar validation passes. Complete package validation currently
refuses because the artifact fragment is missing; artifact locking, native
conformance and production-readiness evidence remain unfinished. See
[evidence/authoring-progress.json](evidence/authoring-progress.json) for the
observed results and qualification limits.
