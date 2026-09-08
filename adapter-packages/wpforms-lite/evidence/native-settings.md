# Native settings ownership and authored-state checkpoint

WPForms Lite remains experimental and unready. This checkpoint covers selected
settings through content-only Capture/Plan/Apply on preinstalled sites, not
managed-code deployment or the complete production-readiness matrix.

## Native authority and local state

The source is the locked Lite **2.0.1.1** artifact (SHA-256
`6245074790df01a6e24a42587e024132b4a28fac499d1a8fa12ebf5580e4852b`).
`includes/admin/class-settings.php:168-292,735-772` owns the nonce-protected
General and Validation forms, registered-field selection, sanitization and
save. The run submits three General controls (`disable-css`, `global-assets`,
`gdpr`) and all sixteen declared `validation-*` controls. Source and target
use different values; text submissions contain trim-able whitespace and HTML
which the native writer removes. Toggles retain native boolean values.

`src/Lite/Admin/Education/Admin/Settings/Gdpr.php:65-99` exposes
`gdpr-disable-uuid` and `gdpr-disable-details` only as disabled Pro upgrade
controls. General Save nevertheless persists their absent inputs as `false`.
Those defaults are not portable authored intent. The two manifest subkeys
are now `env`; their privacy consumers do not justify a Lite authoring grant.
This intentionally changes WPForms identity to
`e1136e1cf369b2c93de77db797d77ea629b0d49f5a37a23ab2d0fa2faa6dd46f`.
Existing repositories must recompile and re-pin; older-identity evidence is
not evidence for this corrected manifest.

`src/Frontend/Frontend.php:181-202` separately initializes `modern-markup` to
string `'1'` here. Its conditional UI (`src/Admin/Settings/ModernMarkup.php`)
is absent and no native UI-authoring claim is made for it. Likewise,
`src/Integrations/LiteConnect/LiteConnect.php:53-58,134-147` excludes Lite
Connect on the exact `.invalid` HTTP host: the cloud control is absent, not
forced to register. No cloud enrollment is performed or claimed.

Both native General/Validation saves finish before target-local witnesses
are installed. This later setup deliberately uses synthetic Pro flags, local
initialization markers, an inactive Lite Connect value, a credential-shaped
string, nested PII-shaped state and an unknown future setting. It is
preservation evidence, not native Pro authoring or a claim that a subsequent
Lite General save preserves Pro residue. Each site generates its own real
crypto key through `WPForms\Helpers\Crypto::get_secret_key()`.

## Recorded native run

Producer `481bceb1980c2a5139b03d84353f27d11c5e6f1e`, owned MariaDB pair
`wpfsettings04`, `WPFORMS_APPLY_SETTINGS=1`, seeded preinstalled target:
`tests/live/regress_location_apply.sh` completed with exit **0** and
`REGRESS_WPFORMS_LOCATION_APPLY PASSED`. The private archive in the retained
settings worktree is `sandbox/tmp/wpforms-apply-native.GbSA5m/`; outer records
are `sandbox/tmp/settings-native-v4.{stdout,stderr,exit}`.

All **65** phase commands exit zero. All four native admin exchanges have
GET/POST HTTP 200, the returned form's nonce and exact legal submitted fields,
native save notices, retired per-call sessions and empty server diagnostic
witnesses. The source's 25 stored settings project to exactly **20** portable
members: 19 UI-authored controls plus the separate automatic markup value.
The complete target's 28 settings retain every unselected value and type.
Physical option IDs, autoload and the distinct target crypto row survive.

Baseline Apply performs 11 authored operations (nine adoptions, two updates)
and one verified location-provider action. Embed-only, widget-only and
routing-only cases each perform one update and one provider action. Every
retry performs **zero writes and zero actions**. All twelve complete retained
source/target/source-repeat trees compile and converge through the real
compiler; ScopeContract association binds the exact source artifact.

Fresh native consumers confirm all sixteen validation strings, global asset
selection, modern rendering, and the portable GDPR toggle combined with the
preserved target-local privacy flags. The full native stylesheet queue changes
from `wpforms-no-styles` to `wpforms-choicesjs`, `wpforms-modern-base`:
`Frontend::assets_css()` fires field hooks before its own style selection,
and `includes/fields/class-select.php:679-688` loads Choices when global assets
are enabled, even without a dropdown in these forms. No stylesheet is filtered
away to manufacture equality. These are PHP frontend consumers, not browser
validation or submission/mail evidence.

The corrected manifest also passed its capture-plan conformance sweep at
`306c88d1146c68e62447e607c0625ce06b7ce8df`, pair `wpfsettingscf01`
(`sandbox/tmp/settings-conformance-v1.{stdout,stderr,exit}`). Both successful
pairs, their databases, exact site/origin roots and leases were cleaned up;
private evidence remains outside those disposable roots.

The capsule's ownership regression has four red controls against the prior
declaration and 14 passing assertions after correction. The 59-assertion
settings evidence regression mutates actual admission code, including a fresh
capsule-only mounted-layout load and missing native field stylesheet. Earlier
failed runs retained their diagnostics; they are not reported as successful
producers. No readiness family is promoted by this bounded checkpoint.
