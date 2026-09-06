<?php
/**
 * WPrism mu-plugin loader. WordPress only auto-loads top-level files in
 * wp-content/mu-plugins/, so this file sits there and pulls in the agent
 * from the wprism/ directory beside it.
 */
require_once __DIR__ . '/wprism/wprism.php';
