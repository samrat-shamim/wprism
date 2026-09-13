#!/usr/bin/env bash
# This capture-plan entry cannot authorize production promotion.
set -euo pipefail
printf '%s\n' 'Importer deployment, lifecycle and template conformance remain unqualified.' >&2
exit 1
