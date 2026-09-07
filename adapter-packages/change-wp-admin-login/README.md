# AIO Login (Change wp-admin login)

This adapter covers the free **2.4.1** artifact under the WordPress.org slug
`change-wp-admin-login`. The declared interval is `>=2.4.1 <2.4.2`;
the official 2.4.0 artifact is retained as a refusal fixture.

The authored contract includes the login route, free login/logout redirects,
login-attempt and enumeration settings, email OTP settings, legacy and current
login styling, and five attachment references. The free redirect editor supports
one `all_users` rule. Page targets use the shared conditional-reference engine;
custom URL targets use ordinary URL rebinding. The capsule contains no provider,
regenerator, or plugin-specific apply executable.

CAPTCHA credentials and validation state, proxy configuration, notification
endpoints, and delivery configuration stay local to each environment. Runtime
logs, challenges, counters, user state, and upgrade/UI markers are excluded.
Applying authored state preserves target-local configuration. Native uninstall
removes AIO options, including credentials, so reinstall recovery explicitly
reprovisions local credentials before reconciling a new repository revision.

AIO Login Pro, WPS Hide Login co-activation, multisite, premium features, and
plugin-owned entity deletion are outside this contract. Authored option deletion
uses the ordinary `apply --with-deletes` protocol. The native 2.4.1 uninstall hook
leaves the enumeration-log table because its helper adds the table prefix twice;
this adapter does not claim to clean that runtime residue.

Qualification exercises the real REST, Customizer and Permalinks writers,
74 authored rows, all 32 observed GET routes, different source/target identities,
native HTTP login and styling, password lockout, enumeration controls, and OTP
challenge verification. It also covers conflicting edits, malformed Pro residue,
SQL rollback, guarded option deletion, lifecycle recovery, and repeated complete
recapture. External CAPTCHA, geolocation, email and notification delivery are
not part of this portability claim.

Run the capsule's offline gate with:

```sh
php tools/adapter-package-tests.php --adapter=change-wp-admin-login
```

The owned live suite requires `WPRISM_EXPECTED_SOURCE_SHA` and accepts `AIO_PAIR`,
`AIO_PORT1`, and `AIO_PORT2`. Standard conformance and exact-artifact matrix hooks
also live inside this capsule. Follow the repository's pair ownership and
candidate-source instructions when invoking either shared runner. Cross-router
refusal evidence is owned by `integration-scenarios/aio-login-wps-incompatibility`.
