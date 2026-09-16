# Historical identity inputs

`library.tar.gz` retains the 21-subject library used by the existing greenfield
and disposition-split identity literals. It was produced with `git archive`
from `ff83547cfb808fc73b8fe953ebd517570ae8cc94`, selecting exactly each subject's
`package/` and the platform library. No capsule tests, fixtures or evidence are
included. `files.json` records every retained path, byte count and SHA-256.

The shared test helper pins both files, extracts into fresh private scratch,
verifies the complete file inventory, and loads the ordinary `AdapterLibrary`
and policy/identity readers. Historical digest, registry, pin-order, manifest
and snapshot literals remain unchanged. A later adapter edit therefore does not
rewrite an unrelated historical product image. Current declarations and their
identity changes are exercised by their capsule's own tests and the shared
identity-mechanism regressions.

These are authoring-only historical inputs. They are never assembled into the
installed library or offered as compatible managed-site pins.
