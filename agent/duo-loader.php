<?php
/**
 * Duo mu-plugin loader. WordPress only auto-loads top-level files in
 * wp-content/mu-plugins/, so this file sits there and pulls in the agent
 * from the duo/ directory beside it.
 */
require_once __DIR__ . '/duo/duo.php';
