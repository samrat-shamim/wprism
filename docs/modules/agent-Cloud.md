# agent: Cloud

**Directory** `agent/src/Cloud/` &middot; **layer** `adapter` &middot; **files** 7 &middot; **status** populated

**Purpose.** The outbound Duo Cloud connector boundary: an isolated production observation, private immutable upload spool, pairing, and closed signed cloud protocol.

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `OriginCloudClient`, `OriginExporter`, `OriginPairing`, `OriginPairingStateStore`, `OriginStore`, `OriginUploadCoordinator`, `OriginUploadJournal`, `OriginUploadSource`.

**May depend on:** `Cloud`, `Kernel`, `Policy`, `Review`.

**Must not depend on.** Apply, Promotion, Command, Assess, or any host/cloud service implementation. The origin can publish classified immutable evidence; it cannot receive arbitrary execution authority.

**Boundary notes.** Live observation is available only under the protected WP-CLI control bootstrap. HTTP retries begin after the complete canonical artifact is sealed, so no network retry can extend or reconstruct a database snapshot across processes.
