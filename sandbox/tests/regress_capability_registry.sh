#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
php sandbox/tests/regress_capability_registry.php
