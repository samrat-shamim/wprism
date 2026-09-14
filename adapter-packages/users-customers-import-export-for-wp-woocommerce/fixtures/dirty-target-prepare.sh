#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../tests/conformance/postdeploy.sh"
. "$(dirname "${BASH_SOURCE[0]}")/dirty-target.sh"
importer_dirty_refusal collision
pass 'unapproved same-key originals refuse without changing the dirty target'
