# Native authoring round trip

The capsule conformance profile exercises the exact free 3.8.1 artifact through
experimental agent deployment and Apply. The shared harness still requires the
host production-deployment refusal; this profile does not promote readiness.

The retained native gallery and legacy archive bodies, settings and images are
written on source through the existing fixture's WordPress/REST paths. Four
projects have distinct native dates so archive order does not depend on local ID
allocation. The target starts with plugin files only. After native deployment,
32 trash posts and 32 inserted/deleted categories advance target identity spaces.
The term padding leaves no authored target-only categories.

The shared harness checks complete canonical recapture. Independent fresh native
observations additionally bind eight post roles and two category roles to distinct
local IDs and unchanged durable UUIDs, archive/placeholder settings, four featured
images, two ordered gallery attachments and original image bytes. Host HTTP checks
require both gallery image elements to name their target attachment IDs, popup
links to name the originals, and image URLs to return decodable PNGs. Both legacy
archives must render the two projects selected by descending native date.

Run from a clean exact checkout with a unique disposable pair:

```sh
CONF_PAIR=<unique-pair> CONF1_PORT=<even-port> CONF2_PORT=<successor-port> \
WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
bash adapter-packages/visual-portfolio/tests/live/regress_native_roundtrip.sh
```

The wrapper uses shared artifact, conformance and owned-pair machinery. Offline
oracle mutations must pass before a live run. HTTP and native observations stay
in the disposable repository; private command diagnostics stay in `sandbox/tmp`.
Browser editor Save/reopen, interactive filtering/lightbox, destructive recovery,
additional plugin combinations and other readiness gaps remain separate work.
