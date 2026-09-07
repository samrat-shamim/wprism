#!/usr/bin/env bash
set -euo pipefail
# Source fixture IDs are 1/2/3. The target counter makes every new page and
# attachment identity differ before content reconciliation starts.
wp_conf2 db query 'ALTER TABLE wp_posts AUTO_INCREMENT=801'
wp_conf2 option update aio_login_google_recaptcha_v2_secret_key aio-target-secret
