<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/AdapterSources.php';
require_once __DIR__ . '/IdentityNamespaces.php';
require_once __DIR__ . '/../Policy/ManifestDispositions.php';

/**
 * External, signed certification for a data-only site adapter.
 *
 * A site adapter is deliberately not allowed to carry its own public key,
 * disposition, registry entry, or executable adapter package.  This class is
 * the narrow bridge for a reviewed authority to make one such adapter a
 * certified claim without importing that claim into the agent's shipped
 * disposition registry.
 *
 * The live entry point reads the exact raw adapter and certificate files.
 * The frozen entry point accepts only the opaque canonical certificate
 * envelope returned by the live entry point: it may avoid reopening mutable
 * site bytes, but still verifies the Ed25519 signature against CURRENT
 * agent-owned authority roots and re-binds the current authority-record and
 * platform digests.
 */

/**
 * A well-formed companion certificate that binds DIFFERENT bytes than
 * `adapters/<name>.json` carries now — i.e. the adapter was edited after it
 * was certified. This is not an authority anomaly: docs/guides/adapter-
 * authoring.md states an edit "moves the digest and the claim drops back to
 * uncertified". AdapterSources::scan() catches this specific signal and
 * resolves the adapter as uncertified support (the same state a companion-
 * absent site adapter reaches), so `duo assess`/`duo release` see a clean
 * uncertified row instead of an unclassified hard failure, and `duo adapter
 * certify --pin` can re-sign over the new bytes. Every genuine anomaly
 * (malformed/misplaced companion, wrong authority, bad signature) stays a
 * hard \RuntimeException — a superseded adapter never keeps its certified
 * grants, so routing only this case to uncertified changes no trust outcome.
 */
final class SupersededSiteAdapterCertificate extends \RuntimeException {
}

/**
 * A correctly-signed companion, under a currently-trusted authority, binding
 * exactly the bytes `adapters/<name>.json` carries now — whose statement was
 * exercised against a compatibility cell this agent no longer states the same
 * way. THE AGENT MOVED, NOT THE ADAPTER: `manifests/capabilities/platform.json`
 * is agent-owned and changes on an ordinary upgrade, and
 * assertPlatformBinding() re-binds the statement to it.
 *
 * WHAT MOVES IT IS NOW MUCH NARROWER THAN THE FILE (spec/repo-format.md § v3.6,
 * WP-4.7). Until then the comparison was the WHOLE record byte for byte, so
 * every release raised this — a new `compatibility.wordpress.last_verified`, a
 * re-measured PHP patch, the `agent_version` line AGENTS.md rule 8 forces the
 * file to restate — and the fleet lost every certificate on every bump. Now it
 * is raised by four things and nothing else: a moved `spec_version` or
 * `site_mode`, a bound compatibility axis the boundary has dropped, a bound
 * cell it no longer carries, or a bound cell whose acceptance terms it now
 * states differently. Coverage the boundary GAINED raises nothing.
 *
 * Before this type the comparison threw a bare \RuntimeException, which
 * scan_site_source() could not tell from a forgery, so discover() refused the
 * WHOLE site source (refuse() at SCOPE_SOURCE) and Policy::load() propagated
 * it uncaught (Policy.php:400) — an agent upgrade bricked every command on
 * every site holding a certified adapter, including `duo adapter certify
 * --pin`, the one command that repairs it (AdapterCertify.php:283, 349, 570).
 *
 * Routing it to uncertified support is a WITHDRAWAL OF A CLAIM, never a
 * fallback: the adapter loses its certified grants until it is re-signed
 * against the current boundary, which is strictly more conservative for it and
 * strictly less destructive for the unrelated adapters the same site pins. The
 * line holds because assertAdapterBinding(), the authority binding and the
 * Ed25519 signature are all verified BEFORE this comparison (`:1025-1116`) — a
 * forged, wrongly-bound or wrongly-rooted companion can never reach this throw
 * site. That is what distinguishes it from SupersededWireSiteAdapterCertificate,
 * which nothing authenticates; read that type's note before treating the two as
 * one mechanism.
 */
final class StalePlatformSiteAdapterCertificate extends \RuntimeException {
}

/**
 * A canonical companion this agent cannot verify because of its WIRE
 * GENERATION, at any of three tests: a root `format` naming the certification
 * family (`duo-adapter-certification/v<n>`) at another version, a statement
 * written in the v1 generation (the five members R-06 closed, signed under the
 * retired `/v1` certification domain — deliberately not spelled here, because
 * tools/wire-surface.php gate 2 reads every domain literal in this tree as a
 * LIVE signing surface needing a register row), or a statement declaring an
 * in-statement `version` this engine does not implement. Same withdrawal as
 * StalePlatformSiteAdapterCertificate — the wire is agent-owned, so a fleet
 * that upgrades past a certificate's generation must degrade the adapter rather
 * than refuse every command on the site — but NOT the same argument, and the
 * difference is the whole risk note:
 *
 * THIS SIGNAL IS UNAUTHENTICATED BY CONSTRUCTION, and WP-4.7 did not change
 * that. StalePlatform is reached only after the Ed25519 signature, the
 * authority binding and assertAdapterBinding() have all verified; all three
 * tests here are raised before any of them, and it cannot be otherwise: a
 * signature cannot be verified until the DOMAIN is chosen, and the generation is
 * what names the domain. (Root `format` is outside the signed bytes entirely —
 * signatureBytes() signs SIGNATURE_DOMAIN . Canon::encode($statement) alone.)
 * So anyone who can write `adapters/certification/<name>.json` can raise it —
 * by flipping the `format` of a GENUINE, valid certificate, or now equally by
 * DELETING its `version` member so a v2 statement reads as a v1 one. Both strip
 * that adapter's certification without breaking one signature, and both are the
 * same accepted trade below.
 *
 * ACCEPTED, with its damping stated rather than argued away: the destination is
 * UNCERTIFIED support, strictly weaker than what the certificate conferred, so
 * the signal can only take a claim away, never grant one; and the same write
 * access reaches the identical state by deleting the companion outright, which
 * has always resolved the adapter as uncertified. It buys an attacker nothing
 * they did not already have — it is a tamper-evidence loss (a hard refusal an
 * operator would have investigated becomes a quiet degradation), not a trust
 * boundary loss.
 *
 * What is NOT accepted is widening the door, and WP-4.7's two new tests were
 * placed to keep that ordering rather than to reopen it.
 * assertCertificateShape() proves the closed root key set and a canonical
 * base64 Ed25519-length signature BEFORE any of the three, so the cheapest
 * forgery — a one-key file naming a future version — is refused as malformed
 * and never reaches here. assertStatementShape() then proves the member set and
 * the object-ness of all five common members before EITHER generation test, so
 * the equivalent cheap statement forgery (a statement carrying `version` and
 * nothing else, or a five-member statement whose `platform` is a string) is
 * refused as malformed too. Each predicate is exact and nothing else: the exact
 * family at a different integer version; the exact v1 member set; a `version`
 * that is a positive integer this engine does not implement. Any other string,
 * a non-string, a missing key, a non-integer or non-positive `version`, a
 * non-canonical or unparseable file, and every other malformed statement stay
 * hard whole-source refusals. Those predicates are the difference between "this
 * agent does not speak this generation" and "this file is not a certificate",
 * and one term wider would launder a forgery into unsigned support.
 */
final class SupersededWireSiteAdapterCertificate extends \RuntimeException {
}

/**
 * THE THIRD TYPED WITHDRAWAL: the authority that signed this companion is no
 * longer entitled to certify — its validity window has lapsed (or the host
 * reads a clock before its issuance), or the typed revocation channel names its
 * key material. G2-FIXES C3.
 *
 * WHY IT HAD TO BE TYPED, AND WHY NOW. Every refusal WP-4.8/4.9 added to the
 * authority seat threw a bare \RuntimeException, so `scan_site_source()` could
 * not tell it from a forgery: `guarded()` re-threw it (`AdapterSources.php:2377`),
 * `discover()` refused the WHOLE site source, and `Policy::load()` propagated it
 * uncaught (`Policy.php:415`, `:508`) — every command on the site, including the
 * `duo adapter certify --pin` that repairs it. On the frozen path the same
 * refusal was worse: `from_snapshot()` caught exactly the two existing typed
 * withdrawals (`AdapterSources.php:4359-4362`), so a promoted site met it as a
 * whole-policy refusal. A v2 authority record's window is MANDATORY
 * (`AUTHORITY_RECORD_V2_KEYS`), which makes that a DATED fleet-brick: every
 * certificate under a v2 key becomes a site-wide refusal at its `not_after`,
 * with no operator act in between. That is the third door to the exact brick
 * WP-1.1 closed through StalePlatformSiteAdapterCertificate.
 *
 * THE DESTINATION IS UNCERTIFIED SUPPORT, which is what makes it a WITHDRAWAL
 * rather than a fallback: the adapter loses its certified grants until it is
 * re-signed under an entitled key, which is strictly MORE conservative for it
 * — an expired or revoked authority must not confer anything — and strictly
 * less destructive for the unrelated adapters the same site pins. Nothing is
 * laundered: an expired key cannot sign a new certificate either (the same
 * `assertAuthorityScope()` seat refuses at mint time), and the revocation
 * channel still reaches the frozen path, where it now withdraws the one adapter
 * instead of refusing the snapshot.
 *
 * WHAT IS NOT TYPED, deliberately: forgery and tamper. A bad signature, a wrong
 * authority binding, a key that is not installed at all, an unparseable or
 * malformed authority document and every grammar refusal in this file stay hard
 * whole-source \RuntimeExceptions. The line is "this key is no longer entitled"
 * versus "this file is not what it claims to be".
 *
 * ORDERING, stated rather than implied: this signal is raised BEFORE the
 * Ed25519 signature is verified (`verifyCertificate()` resolves and scopes the
 * authority at `:1575-1588`, the signature at `:1602-1610`), because a key that
 * may not certify is not asked to. So — exactly as
 * SupersededWireSiteAdapterCertificate already accepts for the generation test —
 * whoever can write the companion file can reach `uncertified` by naming an
 * expired or revoked key in it. That is the same state deleting the companion
 * has always reached, so it buys an attacker nothing; what it costs is
 * tamper-evidence, and the alternative costs every site the certificate is not
 * about. The authority binding (`assertAuthorityBinding()`) still runs first, so
 * the named key, its fingerprint and its identity record must all match the
 * CURRENT root before this can be raised at all.
 */
final class WithdrawnAuthoritySiteAdapterCertificate extends \RuntimeException {
    /**
     * The withdrawal tag AdapterSources renders into the provenance record's
     * IDENTITY-BEARING `reason` (see `AdapterSources::withdrawal_clause()`).
     * Carried on the exception rather than re-derived from its message, because
     * a sentence match would make the adapter digest depend on prose.
     */
    private string $withdrawal;

    public function __construct(string $message, string $withdrawal) {
        parent::__construct($message);
        $this->withdrawal = $withdrawal;
    }

    public function withdrawal(): string {
        return $this->withdrawal;
    }
}

final class AdapterCertification {
    public const FORMAT = 'duo-adapter-certification/v1';
    // Frozen policy snapshots carry the exact certificate format too; there
    // is no weaker second envelope protocol to accidentally accept.
    public const ENVELOPE_FORMAT = self::FORMAT;
    public const AUTHORITIES_FORMAT = 'duo-adapter-authorities/v1';
    /**
     * The AUTHORITY RECORD v2 envelope (spec/repo-format.md § v3.7, WP-4.8).
     *
     * A second format value rather than a widened v1, because R-10 records the
     * v1 envelope key set as closed in both directions: `{format, keys}` refuses
     * a missing AND an unknown member with one message, so the envelope
     * signature could never have been added to it for anyone holding today's
     * agent. A v1 document therefore keeps today's behaviour byte for byte —
     * six-member records, exact adapter names, no window, no signature — and a
     * document opting into v2 accepts all five v2 rules at once. The two are
     * verified beside each other; neither is a fallback for the other.
     */
    public const AUTHORITIES_FORMAT_V2 = 'duo-adapter-authorities/v2';
    public const BUNDLE_FORMAT = 'duo-site-adapter-certification-bundle/v1';
    public const RATIFICATION_FORMAT = 'duo-manifest-dispositions/v1';

    /**
     * Kept independent from JSON framing so this signature cannot verify
     * elsewhere.
     *
     * `/v2` since WP-4.7 (spec/repo-format.md § v3.6) because the BINDING
     * SEMANTICS changed: the statement no longer covers the whole
     * `capabilities/platform.json` record byte for byte, it covers the
     * compatibility CELLS the certificate was exercised against. Per the
     * irreversibility register's R-01 a new domain is a NEW statement type
     * verified BESIDE the old one and never an edit of it, so no v1 statement
     * is re-read under this domain: a v1-generation statement is refused BY
     * NAME in assertStatementShape() and the adapter degrades to uncertified,
     * which is strictly weaker than what its certificate conferred.
     */
    public const SIGNATURE_DOMAIN = "duo-site-adapter-certification-signature/v2\0";

    /**
     * The wire generation stated INSIDE the signed statement.
     *
     * The member the v1 statement could not grow: R-06 records its five-member
     * set as closed in both directions, so `version` had to arrive in the same
     * change that moved the domain. What it buys is the property § v3.7 states
     * for the authority record and for the same reason — a grammar this engine
     * does not implement is refused BY VERSION rather than read as corruption,
     * and a tamperer cannot downgrade a statement by DELETING bytes, because
     * the five-member shape is itself a named refusal now.
     */
    public const STATEMENT_VERSION = 2;

    /** The v2 signed statement, exactly. `version` is the member v1 lacked. */
    private const STATEMENT_KEYS = ['adapter', 'authority', 'bundle', 'platform', 'ratification', 'version'];
    /** The v1 statement, kept only to RECOGNISE the previous generation by name. */
    private const STATEMENT_V1_KEYS = ['adapter', 'authority', 'bundle', 'platform', 'ratification'];

    /**
     * The two RESERVED statement members, and what a reservation is here
     * (spec/repo-format.md § v3.10, WP-4.11).
     *
     * A reservation on a SIGNED statement cannot be an admitted-but-ignored
     * key. `assertExactKeys()` closes STATEMENT_KEYS in both directions (R-06),
     * and the member set is also the generation discriminator — the one thing
     * decidable before a signature (R-24) — so admitting a seventh member would
     * move the wire, change what every deployed verifier accepts, and change
     * the bytes every certificate is signed over. The reservation is therefore
     * the REFUSAL: the member is named, the gate that would open it is named,
     * and STATEMENT_KEYS is untouched. Neither the six-member set nor any
     * certificate's canonical bytes move, which is the property WP-7.1's later
     * opening rests on.
     *
     * `code_digest` and `delegated_authority` differ in WHY they are shut, so
     * they carry different messages rather than one generic sentence.
     * `code_digest` waits on gate G5 — there is nothing to bind a digest over
     * until executable adapter code exists. `delegated_authority` is shut
     * PERMANENTLY as a statement member: a delegation is verified through its
     * own signed, domain-separated delegation document (§ v3.8, R-25), and a
     * member here would be an unsigned second copy of a fact a signature
     * already carries.
     *
     * @var array<string,string>
     */
    private const STATEMENT_RESERVED_KEYS = [
        // Each pinned phrase is written CONTIGUOUSLY, never split across a
        // concatenation, so `grep` and the document suite find the spec's own
        // sentence in the shipped bytes.
        'code_digest' => 'a signed binding over adapter code is reserved and shut; it opens with the executable lane at gate G5',
        'delegated_authority' => 'delegated authority is verified through its own signed delegation document, not through a member of this statement (spec/repo-format.md § v3.8)',
    ];

    /**
     * The RESERVED bundle-evidence member: the reviewer tier's attachment point
     * (spec/repo-format.md § v3.10, WP-4.11; gate G4).
     *
     * Same shape as the statement reservation and for the same reason —
     * `bundleEvidence()` closes `{exercised, grammar, reason}` with
     * `assertExactKeys()`, and the evidence object is inside the bundle digest
     * a certificate binds — so the slot is a named refusal rather than an
     * admitted key. WP-5.2 is the rider that flips it: `bundleEvidence()` then
     * ADMITS the member and the claim projects the reviewer-tier word instead
     * of collapsing into `site_signed`.
     */
    public const RESERVED_EVIDENCE_REVIEWER = 'reviewer';

    /**
     * The v2 `statement.platform` member.
     *
     * `axes` is the binding; `agent_version` is RECORDED and deliberately not
     * bound. That inversion is the whole of WP-4.7: under v1 the statement
     * covered the whole boundary document, so an agent release that moved
     * `agent_version` and one prose `note` — which every release does, since
     * AGENTS.md rule 8 makes platform.json restate the two defines — withdrew
     * every certificate on the fleet. `agent_version` stays inside the
     * signature because an operator still has to be told WHICH agent state a
     * certificate was minted beside (`MigrationPreflight::certificates()` reads
     * it, :518), and a fact inside the signature cannot be forged; it is simply
     * no longer the thing validity turns on.
     */
    private const STATEMENT_PLATFORM_KEYS = ['agent_version', 'axes', 'site_mode', 'spec_version'];
    /** One bound compatibility axis: which cells, and their digest. */
    private const STATEMENT_AXIS_KEYS = ['cells', 'sha256'];

    /**
     * The two cell-series members a compatibility axis may carry, and the
     * single rule that decides what a certificate binds on each shape.
     *
     * THE RULE: a cell's bound value is everything the boundary uses to ACCEPT
     * a runtime on that cell, and nothing it uses only to WITNESS one.
     *
     *   - `verified` (the `php` and `wordpress` axes) maps an accepted SERIES
     *     to the exact patch a live proof ran on. Acceptance is series
     *     membership — "a runtime is accepted only when it is inside [min, max)
     *     AND its MAJOR.MINOR is one of those exercised series"
     *     (manifests/capabilities/platform.json, php axis note) — so the key is
     *     bound and the patch is not. Re-measuring 8.3 on a newer patch is new
     *     evidence for the same cell, which is exactly what § v3.6 means by
     *     "recording a newly exercised PHP patch adds coverage and invalidates
     *     nothing".
     *   - `engines` (the `database` axis) maps an accepted ENGINE to its own
     *     min/max line, because "the range is now a function of the engine"
     *     (same file, database axis note). The value IS the acceptance term
     *     here, so it is bound: widening `MySQL` to admit a 9.x nobody ran is
     *     not new evidence for the same cell, it is a different cell wearing
     *     the same name.
     *   - neither member (`filesystem`, `process`) means the axis is one
     *     reviewed PROFILE — a versioned identity plus the functions, families
     *     and separators the gate requires. That whole object minus its prose
     *     `note` is one cell, named by the profile string.
     *
     * `min`/`max` are deliberately outside every binding: the range admits
     * nothing on its own (acceptance needs the series too), and a release that
     * exercises a new series moves `max` in the same edit that adds the cell —
     * binding it would make every additive release invalidate every
     * certificate, which is the pathology this rider exists to end.
     *
     * THE RESIDUAL THAT LEAVES, stated rather than argued away: a boundary that
     * NARROWED [min, max) around a series a certificate already binds moves no
     * cell, so this binding raises nothing. It is not a hole in the honesty
     * property, because the certificate is not what admits a runtime:
     * `PlatformCompatibility::assert_supported()` gates every load on the range
     * AND the series against the boundary installed now, so the site refuses at
     * load time on the axis itself. The certificate's job is to say which cells
     * were exercised; the boundary's job is to say which are admissible, and
     * only the second one is a gate.
     */
    private const PLATFORM_AXIS_SERIES = 'verified';
    private const PLATFORM_AXIS_ENGINES = 'engines';
    /** Prose. An axis note is edited on every live matrix run and moves no runtime. */
    private const PLATFORM_AXIS_NOTE = 'note';

    /**
     * The v2 authorities-document envelope signature domain.
     *
     * Its own domain, not an arm inside SIGNATURE_DOMAIN's verifier: R-01/R-04
     * state the rule this obeys — a statement of one kind must not be able to
     * verify as a statement of another, and the trailing NUL is what stops one
     * domain being a prefix of a longer one. The bytes signed are
     * `domain || Canon::encode({format, keys})` — the document with its own
     * `signature` member removed, because a signature is never inside its own
     * input (the same exclusion ContractAttestation's `attested_digest` makes).
     */
    public const SIGNATURE_DOMAIN_AUTHORITIES = "duo-adapter-authorities-signature/v1\0";

    /**
     * THE DELEGATION STATEMENT DOMAIN (spec/repo-format.md § v3.8, WP-4.9).
     *
     * Its own domain for the reason SIGNATURE_DOMAIN_AUTHORITIES states one
     * constant above and register rows R-01/R-04 record: a statement of one
     * kind must not be able to verify as a statement of another, and the
     * trailing NUL is what stops one domain being a prefix of a longer one.
     * The concrete replay this closes: a delegation's signed statement and a
     * certificate's both carry an `adapter_names`-shaped grant under a
     * platform key, so under one domain a delegation could be presented as the
     * authority half of a certificate for an adapter nobody certified.
     *
     * `ContractAttestation` (`cli/src/Contract/ContractAttestation.php:105`) is
     * the proof this pattern replicates rather than a new idea: a second
     * Ed25519 root with its own format, scope and domain, verified beside the
     * certification one and never inside it.
     */
    public const SIGNATURE_DOMAIN_DELEGATION = "duo-adapter-authority-delegation-signature/v1\0";

    /**
     * THE REVOCATION STATEMENT DOMAIN (§ v3.8).
     *
     * A THIRD domain rather than an arm inside the delegation verifier, and the
     * reason is a subject-set argument rather than a stylistic one: a
     * revocation must be able to name key material that holds NO delegation —
     * a platform key, or a vendor key an operator copied into a site root by
     * hand — so its subject set is strictly larger than the delegation
     * statement's. Under one domain a delegation statement whose members
     * happened to overlap could be replayed as a revocation of its own
     * delegator, which is the one direction that must never be forgeable.
     */
    public const SIGNATURE_DOMAIN_REVOCATION = "duo-adapter-authority-revocation-signature/v1\0";

    /**
     * The delegation wire, plural on the document and singular on the statement.
     *
     * Two format strings rather than one because they name two different
     * things: the installed FILE holds a map of delegations, while the signed
     * STATEMENT is one delegation and carries its own format inside the
     * signature. The statement's copy is the one that matters — the envelope's
     * `format` is outside every signature, exactly as
     * `SupersededWireSiteAdapterCertificate`'s note records for the
     * certification envelope, so a reader that trusted only the envelope's word
     * would be trusting an unauthenticated byte.
     */
    public const DELEGATIONS_FORMAT = 'duo-adapter-authority-delegations/v1';
    public const DELEGATION_FORMAT = 'duo-adapter-authority-delegation/v1';
    /** The revocation wire, plural/singular for the identical reason. */
    public const REVOCATIONS_FORMAT = 'duo-adapter-authority-revocations/v1';
    public const REVOCATION_FORMAT = 'duo-adapter-authority-revocation/v1';

    /**
     * Verification chains EXACTLY one level, and the constant exists so the
     * number is quoted rather than implied.
     *
     * A delegate may not delegate: `delegatedKeys()` refuses a delegation whose
     * delegator is itself a delegate BY NAME, before it tries and fails to
     * resolve that delegator in the platform root — a chain must read as a
     * chain, not as a missing key. There is no depth-2 mode to enable; this is
     * a bound on the verifier, not a configurable maximum.
     */
    private const DELEGATION_DEPTH = 1;

    private const AUTHORITIES_RELATIVE = 'capabilities/adapter-authorities.json';
    /**
     * THE OUT-OF-BAND REVOCATION CHANNEL (§ v3.8).
     *
     * Beside the shipped trust root in the agent's manifest library, and NOT
     * inside it, because the two have opposite cadences. A `status: revoked`
     * word in `capabilities/adapter-authorities.json` is a byte of a file
     * `make release-gate` byte-compares against exactly two legal states
     * (`tools/wire-surface.php` gate 6), so moving it is an agent release —
     * which is the wrong latency for the one direction that matters under
     * compromise.
     *
     * This document is a different thing in three checkable ways: it is absent
     * from the shipped tree and stays absent, its absence is a legitimate
     * answer meaning "nothing is revoked", and it is SELF-AUTHENTICATING — a
     * platform-key signature over its own statement — so it needs no integrity
     * from whatever carried it. An operator's cron may fetch it over plain
     * HTTP, a configuration run may drop it, an incident responder may paste
     * it; the agent's verdict is identical, because the trust comes from the
     * signature and not from the channel.
     *
     * It lives under the MANIFEST DIRECTORY rather than in the site repository
     * because that is the only path the frozen verifier holds
     * (`AdapterSources.php:4156` calls verifyFrozen() with
     * `Policy::manifests_dir()` and nothing else), and reaching the frozen path
     * is the entire point of the channel. RESIDUAL, stated rather than
     * softened: `Adopt.php:149` tars `agent manifests recovery`, so re-adopting
     * an agent over a site replaces this file along with the library. The
     * remedy is to re-install it after an adopt, or to point
     * `DUO_MANIFESTS_DIR` at a library the adoption tar does not overwrite.
     */
    private const REVOCATIONS_RELATIVE = 'capabilities/adapter-revocations.json';
    /**
     * The SITE trust root (round-3 T6 §3.1): the operator's own authority
     * file, in the operator's own repository, travelling with it.
     *
     * It exists because the shipped authorities file is empty and only
     * Anthropic-side review could ever fill it — so before T6 every operator's
     * own adapter was permanently `uncertified` and `Site-certified` was
     * NEVER_EMITTED, despite the product spec defining it as "customer-
     * organization approval ... explicitly not a Duo endorsement". A key here
     * is trusted ONLY for adapters in this repository, which is the whole of
     * its authority: the certificate binds `adapter.path:
     * adapters/<name>.json` inside the signed statement, so a site key cannot
     * reach a shipped manifest or another repository's adapter.
     */
    private const SITE_AUTHORITIES_RELATIVE = AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE;
    /**
     * Where a site installs the delegations it was handed (§ v3.8).
     *
     * Beside the site trust root, in the repository, travelling with it — the
     * same custody argument SITE_AUTHORITIES_RELATIVE makes, for the same
     * reason: a delegation grants a VENDOR key standing inside THIS repository
     * and nowhere else. It is a flat file rather than a directory because
     * `AdapterSources::assert_flat_json_source()` refuses a nested `.json`
     * under `adapters/` by name (REFUSAL_NESTED_JSON), and the one directory
     * that source admits is `certifications/`; a second admitted directory
     * would be a change to the site-source grammar, which is § v3.9's subject
     * and not this rider's.
     */
    private const SITE_DELEGATIONS_RELATIVE = AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_DELEGATIONS_FILE;
    private const PLATFORM_RELATIVE = 'capabilities/platform.json';
    private const CERTIFICATE_DIR = 'adapters/certifications';

    /** Reviewed by this project and shipped with the agent. */
    public const TRUST_ROOT_PLATFORM = 'platform';
    /** Held by the customer organization, in its own site repository. */
    public const TRUST_ROOT_SITE = 'site';

    /**
     * The two authority record key sets, and the member that tells them apart.
     *
     * `record_version` is REQUIRED at v2 and absent at v1, and it is what makes
     * a record self-describing away from its envelope: the frozen path re-binds
     * a site certificate to the record its own SIGNATURE covers
     * (verifyCertificate()), and that embedded record arrives with no `format`
     * line above it. Without an in-record version the verifier would have to
     * infer the grammar from which optional members happened to be present,
     * which is a downgrade a tamperer performs by DELETING bytes. With it, a
     * grammar this engine does not implement is refused BY VERSION rather than
     * read as corruption — the same member § v3.6 adds inside the certification
     * statement, for the same reason.
     */
    private const AUTHORITY_RECORD_KEYS = [
        'adapter_names', 'algorithm', 'public_key', 'scope', 'status', 'trust_tiers',
    ];
    /**
     * v2 = v1 + `record_version` + the mandatory validity window.
     *
     * The window is mandatory rather than optional because `assertExactKeys()`
     * is closed in both directions and an optional member has no honest home in
     * it — and because a v2 record's whole purpose is to be enrollable, which
     * is the case where an unbounded grant is the wrong default. A holder that
     * wants no expiry keeps its record at v1, where there is none.
     */
    private const AUTHORITY_RECORD_V2_KEYS = [
        'adapter_names', 'algorithm', 'not_after', 'not_before', 'public_key', 'record_version',
        'scope', 'status', 'trust_tiers',
    ];
    private const AUTHORITIES_ENVELOPE_KEYS = ['format', 'keys'];
    private const AUTHORITIES_ENVELOPE_V2_KEYS = ['format', 'keys', 'signature'];
    private const AUTHORITIES_SIGNATURE_KEYS = ['key_id', 'value'];

    /**
     * The delegation grammar's five closed key sets (§ v3.8).
     *
     * Closed in both directions like every other set in this file, and the
     * consequence is the one R-05 records: no member can ever be added for
     * anyone holding today's agent, so growth is a new `format` value verified
     * beside this one. `version` inside the statement is what makes that
     * growth refuse BY VERSION rather than read as corruption — the identical
     * member `record_version` is at v2 and § v3.6 adds to the certification
     * statement, for the identical reason.
     *
     * `delegate` carries the key MATERIAL and `delegator` carries only an
     * identity (`key_id` + `fingerprint`), and that asymmetry is deliberate:
     * the delegate's public key must be inside the signature or the grant would
     * name a key the delegator never saw, while the delegator's key is read
     * from the shipped platform root at verify time so that revoking it there
     * revokes every delegation it made.
     */
    private const DELEGATIONS_ENVELOPE_KEYS = ['delegations', 'format'];
    private const DELEGATION_KEYS = ['signature', 'statement'];
    private const DELEGATION_SIGNATURE_KEYS = ['key_id', 'value'];
    private const DELEGATION_STATEMENT_KEYS = [
        'adapter_names', 'delegate', 'delegator', 'format', 'not_after', 'not_before', 'trust_tiers', 'version',
    ];
    private const DELEGATION_DELEGATE_KEYS = ['algorithm', 'key_id', 'public_key'];
    private const DELEGATION_DELEGATOR_KEYS = ['fingerprint', 'key_id', 'trust_root'];

    /**
     * The typed revocation grammar's three closed key sets (§ v3.8).
     *
     * An entry binds `fingerprint` — `sha256(public_key)` — and not the key id,
     * because an id can be re-minted over new material and material cannot be
     * re-minted under an old id. `key_id` rides along so the refusal can NAME
     * the key an operator knows, and `reason` because a revocation an operator
     * cannot explain is one nobody will act on; both are inside the signature.
     * `effective_at` is per ENTRY rather than per document so one signed
     * statement can carry an incident's several keys with their own instants.
     */
    private const REVOCATIONS_ENVELOPE_KEYS = ['format', 'signature', 'statement'];
    private const REVOCATION_STATEMENT_KEYS = ['format', 'issued_at', 'revocations', 'version'];
    private const REVOCATION_ENTRY_KEYS = ['effective_at', 'fingerprint', 'key_id', 'reason'];

    /**
     * One timestamp grammar, checked at both ends, and it is deliberately the
     * same string ContractAttestation::EXPIRES_FORMAT is
     * (`cli/src/Contract/ContractAttestation.php:149`): a hand-written
     * `2027-13-01T00:00:00Z` that parsed loosely would make "expired" a
     * property of the parser rather than of the clock, and two spellings of one
     * instant across two roots would be a second grammar to get wrong.
     */
    private const AUTHORITY_WINDOW_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * The fingerprint prefix length a v2 key id must carry, in hex characters.
     *
     * 12 because that is already the id `duo adapter keygen` hands an operator
     * by default — `'site-' . substr(hash('sha256', $public), 0, 12)`
     * (`cli/src/Adapter/AdapterCertify.php:1241`) — so v2 makes the shipped
     * default THE RULE rather than minting a second convention nobody's tooling
     * emits. 48 bits of second-preimage room is not a signature and is not
     * asked to be one: the id is a map key and a selector, and the signature
     * over `key_id` inside the statement is what actually binds it.
     */
    private const AUTHORITY_KEY_FINGERPRINT_LENGTH = 12;

    /**
     * The verifying host's clock, injectable for regression only.
     *
     * No production setter, exactly like $testVerifiedBundleAssetReadHook
     * below: the offline suite reaches it through Reflection so an expiry
     * boundary can be asserted at the second rather than waited for.
     */
    private static ?\Closure $testAuthorityClock = null;

    /**
     * The manifest's own top-level vocabulary, partitioned the way a
     * disposition names it, so sign_site() can DERIVE a ratification instead of
     * asking an operator to hand-write one.
     *
     * Three lists rather than two, and the third is the point: a manifest key
     * in none of them makes sign_site() refuse by name. Silently dropping an
     * unrecognised section would mint a certificate that covers less than the
     * adapter declares — the capability claim's `surfaces` list is built from
     * exactly these sections (ManifestDispositions::claim_from_disposition()),
     * so the uncovered surface would simply be blocked later with nothing
     * saying why. A new section kind must stop the signer, not narrow the
     * certificate.
     *
     * ManifestDispositions::validate_entry() independently refuses a named
     * section the manifest does not declare, so these lists can only ever be
     * too narrow, never too wide.
     */
    private const ENTITY_SECTIONS = ['post_types', 'tables', 'taxonomies', 'taxonomy_patterns', 'widgets'];
    private const FIELD_SECTIONS = [
        'block_attrs', 'dynamic_options', 'interpreter', 'menu_fields', 'meta_patterns', 'option_name_refs',
        'option_namespaces', 'option_patterns', 'options', 'post_meta', 'post_meta_patterns', 'shortcode_attrs',
        'term_meta', 'user_meta',
    ];
    /**
     * Manifest keys that declare no branchable state surface of their own.
     *
     * `environment` joined this arm with WP-4.6: it narrows the adapter's own
     * capability CLAIM to the boundary cells it was exercised on
     * (ManifestDispositions::narrowed_environment(), spec § v3.5) and covers no
     * state, so classifying it as an entity or field section would put a
     * runtime assertion into a certificate's surface list. It rides here rather
     * than in a later rider because `regress_spec_v3_dry_run.php` measured the
     * consequence of the alternative: a key in no arm makes the whole adapter
     * unsignable (rule V3-KEYS, the `theme_version_range` case), so a narrowing
     * adapter could not be certified at all.
     *
     * `theme_version_range` closed that very case with WP-4.3 (spec § v3.3,
     * reviewed resolution 1). It is not a state surface and not a new one: it
     * is the bound on the SUBJECT that `validate_adapter_contract()` already
     * makes mandatory beside every `theme`
     * (AdapterContractGrammar.php:265-301) and that
     * ArtifactPolicyIdentity::resolved_adapters() already folds into the
     * adapter identity row (ArtifactPolicyIdentity.php:167-168) — precisely the
     * standing `version_range` has in this arm. Until it joined, a theme
     * adapter validated `[ok]` and was then unsignable by name, which is the
     * partition being incomplete against the shipped grammar rather than an
     * adapter being wrong. No shipped adapter declares `theme`, so admitting
     * its companion moved no digest and no certificate.
     */
    private const NON_SURFACE_KEYS = [
        'actions', 'deletions', 'environment', 'lifecycle_effects', 'name', 'note', 'notes', 'option_autoload',
        'plugin', 'providers', 'spec_version', 'theme', 'theme_version_range', 'version_range',
    ];

    /**
     * What a grammar-only certificate may claim. `delete` is absent because
     * deletion semantics are exactly what a validator run cannot review, and
     * `render-api`/`test-only` because they are reviewed runtime behaviours.
     */
    private const SITE_OPERATIONS = ['apply', 'capture', 'compile', 'deploy', 'plan', 'recapture'];

    // This has no production setter.  The offline regression reaches it only
    // through Reflection to deterministically simulate an evidence-directory
    // replacement after an asset's descriptor check.
    private static ?\Closure $testVerifiedBundleAssetReadHook = null;

    /**
     * The three-arm top-level key partition above, published for readers that
     * must NAME the set without re-typing it (WP-4.1).
     *
     * It is the only closed top-level manifest vocabulary this project has,
     * and until now the only way to read it was Reflection over three private
     * consts — which
     * `sandbox/tests/offline/policy/regress_spec_v3_dry_run.php:165-181` does
     * precisely because a copy would be the second definition the wire-surface
     * register exists to forbid. `duo manifest-validate --emit-schema` now
     * publishes it in `duo-manifest-grammar/v2`, so this accessor exists
     * rather than a fourth spelling of 33 strings.
     *
     * WP-4.3 made it the LOAD-TIME set as well, and this accessor is how:
     * `AdapterContractGrammar::admitted_top_level_keys()` reads the partition
     * from here and refuses a key in no arm of it for a `spec_version: 3`
     * manifest (spec/repo-format.md § v3.3). There is still exactly one
     * definition — `php tools/wire-surface.php --check`, a `make release-gate`
     * step, asserts that the validator's admitted set and this partition are
     * the SAME SET, in both directions, so a second list cannot be introduced
     * on either side without the release refusing (register row R-21).
     *
     * The two readers ask different questions of it and that asymmetry is
     * deliberate. The validator asks only whether a key is IN the set; the
     * signer needs the ARM, because a key's arm decides what a derived
     * ratification says about it. So § v3.2's growth rule — a key claimed by a
     * declared engine feature this engine implements — admits at LOAD and does
     * not classify: a feature record carries `{since, keys}` and no arm, so a
     * feature whose key must also be SIGNABLE gives it an arm here in the same
     * change, exactly as WP-4.6 did for `environment`.
     *
     * @return array{entity_sections: list<string>, field_sections: list<string>, non_surface_keys: list<string>}
     */
    public static function topLevelKeyPartition(): array {
        return [
            'entity_sections' => self::ENTITY_SECTIONS,
            'field_sections' => self::FIELD_SECTIONS,
            'non_surface_keys' => self::NON_SURFACE_KEYS,
        ];
    }

    /**
     * A site adapter's certification path is derived, never declared by the
     * adapter or a site pin.  This prevents one certificate from being reused
     * for a different adapter by merely changing a path claim.
     */
    public static function certificatePath(string $repo, string $name): string {
        $name = self::adapterName($name);
        return rtrim($repo, '/') . '/' . self::CERTIFICATE_DIR . '/' . $name . '.json';
    }

    /** Agent-owned roots are optional; an absent file means no external trust. */
    public static function hasAuthorities(string $manifestDir): bool {
        return is_file(rtrim($manifestDir, '/') . '/' . self::AUTHORITIES_RELATIVE);
    }

    /**
     * Validate the SITE trust root whole, selecting no key.
     *
     * The adapter scan calls this the moment the file EXISTS, before any
     * certificate is paired, because a broken trust root is not one adapter's
     * problem: every certificate in the repository is judged against it, and
     * an operator who wrote an authorities file believes their adapters are
     * certifiable. Reporting that belief as an ordinary `uncertified` row
     * would be the silence this source refuses everywhere else. Absent is
     * fine and means exactly "this repository certifies nothing".
     */
    public static function assert_site_authorities(string $repo): void {
        $root = self::repoRoot($repo, 'site repository');
        self::authorityKeys(
            $root . '/' . self::SITE_AUTHORITIES_RELATIVE,
            'site adapter certification authorities'
        );
    }

    /**
     * Verify one site certificate against the live raw adapter bytes.
     *
     * @return array{disposition:array,claim:array,provenance:array,envelope:array}
     */
    public static function verifyFile(
        string $manifestDir,
        string $repo,
        string $name,
        array $manifest,
        string $certPath
    ): array {
        self::assertSodium();
        $name = self::adapterName($name);
        $root = self::repoRoot($repo, 'site repository');
        $adapterRelative = 'adapters/' . $name . '.json';
        $adapterPath = self::ownedFile($root, $adapterRelative, 'site adapter');
        [$adapterRaw, $adapterTyped, $adapterDecoded] = self::readCanonicalObjectFile($adapterPath, 'site adapter');
        self::assertSiteManifest($name, $adapterDecoded, $manifestDir);
        self::assertSameManifest($name, $manifest, $adapterDecoded);

        $certRelative = self::CERTIFICATE_DIR . '/' . $name . '.json';
        $expectedCert = self::ownedFile($root, $certRelative, 'site adapter certification');
        $provided = realpath($certPath);
        if ($provided === false || !hash_equals($expectedCert, $provided)) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification path must be exactly $certRelative"
            );
        }
        [$certificateRaw, $certificateTyped, $certificate] = self::readCanonicalObjectFile(
            $expectedCert,
            'site adapter certification'
        );

        $result = self::verifyCertificate(
            $manifestDir,
            $name,
            $manifest,
            $certificateRaw,
            $certificateTyped,
            $certificate,
            ['raw' => $adapterRaw, 'size' => strlen($adapterRaw)],
            $root
        );
        $result['envelope'] = self::envelope($certificateRaw);
        return $result;
    }

    /**
     * Compatibility spelling for the source/policy integration boundary.
     * Keep verifyFile() as the documented public API while callers following
     * the surrounding source classes' snake-case convention need no shim.
     *
     * @return array{disposition:array,claim:array,provenance:array,envelope:array}
     */
    public static function verify_file(
        string $manifestDir,
        string $repo,
        string $name,
        array $manifest,
        string $certPath
    ): array {
        return self::verifyFile($manifestDir, $repo, $name, $manifest, $certPath);
    }

    /**
     * Re-verify a frozen certificate without reopening mutable site files.
     *
     * The envelope intentionally keeps canonical certificate bytes rather
     * than a decoded PHP array.  JSON's {} versus [] distinction is therefore
     * not erased between live verification, snapshotting, and signature
     * verification.
     *
     * @return array{disposition:array,claim:array,provenance:array,envelope:array}
     */
    public static function verifyFrozen(
        string $manifestDir,
        string $name,
        array $manifest,
        array $envelope
    ): array {
        self::assertSodium();
        $name = self::adapterName($name);
        self::assertSiteManifest($name, $manifest, $manifestDir);
        self::assertExactKeys($envelope, ['certificate_json', 'certificate_sha256', 'format'], 'frozen certification envelope');
        $certificateDigest = $envelope['certificate_sha256'] ?? null;
        if (($envelope['format'] ?? null) !== self::ENVELOPE_FORMAT
            || !is_string($certificateDigest) || !self::sha($certificateDigest)) {
            throw new \RuntimeException('duo: frozen site adapter certification envelope is malformed');
        }
        $encoded = $envelope['certificate_json'] ?? null;
        $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($raw !== false && !self::isCanonicalBase64($encoded, $raw)) {
            $raw = false;
        }
        if ($raw === false || !hash_equals($certificateDigest, hash('sha256', $raw))) {
            throw new \RuntimeException('duo: frozen site adapter certification envelope has corrupt certificate bytes');
        }
        [, $certificateTyped, $certificate] = self::parseCanonicalObject($raw, 'frozen site adapter certification');
        $result = self::verifyCertificate(
            $manifestDir,
            $name,
            $manifest,
            $raw,
            $certificateTyped,
            $certificate,
            null,
            null
        );
        $result['envelope'] = $envelope;
        return $result;
    }

    /** @return array{disposition:array,claim:array,provenance:array,envelope:array} */
    public static function verify_frozen(
        string $manifestDir,
        string $name,
        array $manifest,
        array $envelope
    ): array {
        return self::verifyFrozen($manifestDir, $name, $manifest, $envelope);
    }

    /**
     * Scan every certificate supplied by a site.  A missing certification
     * directory is intentionally fine (the adapter remains uncertified); a
     * present malformed/orphan/misplaced certificate is always fatal.
     *
     * @return array<string, array{disposition:array,claim:array,provenance:array,envelope:array}>
     */
    public static function verifyDirectory(string $manifestDir, string $repo): array {
        $root = self::repoRoot($repo, 'site repository');
        $directory = $root . '/' . self::CERTIFICATE_DIR;
        if (!file_exists($directory)) {
            return [];
        }
        if (!is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
            throw new \RuntimeException(
                'duo: site adapter certifications must be a real directory at ' . self::CERTIFICATE_DIR
            );
        }
        if (!is_readable($directory)) {
            throw new \RuntimeException(
                'duo: site adapter certifications are not readable; authority-bearing bytes cannot be treated as absent'
            );
        }
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new \RuntimeException(
                'duo: site adapter certifications could not be enumerated; authority-bearing bytes cannot be treated as absent'
            );
        }
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_link($path) || !is_file($path) || !str_ends_with($entry, '.json')) {
                throw new \RuntimeException(
                    "duo: site adapter certification source contains '$entry' — only direct <name>.json certificate files are allowed"
                );
            }
            $name = self::adapterName(substr($entry, 0, -5));
            $adapterPath = self::ownedFile($root, 'adapters/' . $name . '.json', 'certified site adapter');
            [, , $manifest] = self::readCanonicalObjectFile($adapterPath, 'certified site adapter');
            $out[$name] = self::verifyFile($manifestDir, $root, $name, $manifest, $path);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Build, but do not write, a canonical certificate.  The command-line
     * tool is the mutation boundary.  It verifies the entire imported bundle
     * (assets AND evidence-repository bound inputs) before calling Ed25519.
     */
    public static function sign(
        string $manifestDir,
        string $repo,
        string $name,
        string $bundleInput,
        string $evidenceRepo,
        string $authorityId,
        string $secretKey
    ): string {
        self::assertSodium();
        $name = self::adapterName($name);
        $root = self::repoRoot($repo, 'site repository');
        $adapterPath = self::ownedFile($root, 'adapters/' . $name . '.json', 'site adapter');
        [$adapterRaw, , $manifest] = self::readCanonicalObjectFile($adapterPath, 'site adapter');
        self::assertSiteManifest($name, $manifest, $manifestDir);

        [$authority, $keyId, $authorityDigest, $trustRoot] = self::authority($manifestDir, $authorityId, $root);
        $tier = AdapterSources::trust_tier($manifest);
        self::assertAuthorityScope($authority, $keyId, $name, $tier);

        $secret = self::secretKey($secretKey);
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $configured = self::publicKey($authority);
        if (!hash_equals($configured, $public)) {
            throw new \RuntimeException(
                "duo: private key does not match trusted authority key '$keyId'"
            );
        }

        $bundle = self::verifyBundleForImport(
            $bundleInput,
            $evidenceRepo,
            $name,
            $manifest,
            $adapterRaw,
            $trustRoot
        );
        return self::signStatement(
            $manifestDir,
            $name,
            $manifest,
            $adapterRaw,
            $tier,
            [
                'fingerprint' => hash('sha256', $configured),
                'key_id' => $keyId,
                // The exact authority record, inside the signature. It is what
                // makes a SITE-rooted certificate re-verifiable on the frozen
                // path, which reopens no mutable site file and therefore has no
                // adapters/authorities.json to consult (verifyCertificate()
                // states the trust consequence at its own site).
                'record' => $authority,
                'record_sha256' => $authorityDigest,
                'trust_root' => $trustRoot,
            ],
            $bundle['typed'],
            $bundle['ratification_typed'],
            $secret
        );
    }

    /**
     * Certify a site adapter under the operator's OWN trust root, from the one
     * piece of evidence an operator can actually produce: the loader's own
     * grammar verdict, plus their stated reason.
     *
     * WHY THE BUNDLE IS BUILT HERE rather than by the caller. The bundle
     * grammar is this file's (verifyBundleManifest() refuses any deviation by
     * exact key set), and a producer living in the host would be a second copy
     * of that grammar in a different language of the same repository — drifting
     * the moment either moved, and drifting SILENTLY on the host side, where
     * nothing re-verifies. The reviewed-exercise path keeps its external
     * producer because there the bundle is the OUTPUT of a real conformance
     * run that this file has no business performing.
     *
     * NOTHING IS WRITTEN TO DISK, and nothing is lost by that: an unexercised
     * bundle's only assets are environment.json and ratification.json, and
     * both are already inside the signed statement, content-addressed by the
     * descriptors the signature covers. There is no directory to keep, so
     * there is no directory to tamper with.
     *
     * The grammar verdict is TAKEN, not asserted: the real loader is run
     * against this repository and this name, and a manifest that does not load
     * refuses to be signed with the loader's own message. A certificate for
     * bytes no command can use would be the emptiest possible claim.
     *
     * Repeating the same certification preserves the timestamp of an existing
     * certificate only after the live verifier accepts its exact bytes and a
     * deterministic re-sign of every current input is byte-identical. Thus
     * `created_at` records when this claim changed, not how often an idempotent
     * command was invoked; any changed signed input mints a fresh statement.
     *
     * @param string $reason the operator's stated basis, signed and reported
     * @return string canonical duo-adapter-certification/v1 bytes
     */
    public static function sign_site(
        string $manifestDir,
        string $repo,
        string $name,
        string $authorityId,
        string $secretKey,
        string $reason
    ): string {
        self::assertSodium();
        $name = self::adapterName($name);
        if (trim($reason) === '') {
            throw new \RuntimeException(
                'duo: a site adapter certification must state its basis; supply a non-empty reason'
            );
        }
        $root = self::repoRoot($repo, 'site repository');
        $adapterPath = self::ownedFile($root, 'adapters/' . $name . '.json', 'site adapter');
        [$adapterRaw, , $manifest] = self::readCanonicalObjectFile($adapterPath, 'site adapter');
        self::assertSiteManifest($name, $manifest, $manifestDir);

        [$authority, $keyId, $authorityDigest, $trustRoot] = self::authority($manifestDir, $authorityId, $root);
        if ($trustRoot !== self::TRUST_ROOT_SITE) {
            // The relaxation follows the ROOT, not the caller. An agent-owned
            // key certifies a reviewed exercise or nothing; routing it through
            // this entry point would be exactly the downgrade the two words
            // exist to keep separable.
            throw new \RuntimeException(
                "duo: authority key '$keyId' is agent-owned, and an agent-owned key certifies a reviewed "
                . 'exercise — sign a ' . self::BUNDLE_FORMAT . ' bundle through sign() instead'
            );
        }
        $tier = AdapterSources::trust_tier($manifest);
        self::assertAuthorityScope($authority, $keyId, $name, $tier);

        $secret = self::secretKey($secretKey);
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $configured = self::publicKey($authority);
        if (!hash_equals($configured, $public)) {
            throw new \RuntimeException(
                "duo: private key does not match trusted authority key '$keyId'"
            );
        }

        $grammar = self::siteGrammarVerdict($manifestDir, $repo, $name);
        $ratification = self::siteRatification($name, $manifest, $reason);
        $ratificationRaw = Canon::encode($ratification);
        $authorityBinding = [
            'fingerprint' => hash('sha256', $configured),
            'key_id' => $keyId,
            'record' => $authority,
            'record_sha256' => $authorityDigest,
            'trust_root' => $trustRoot,
        ];
        $freshCreatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $existing = self::verifiedExistingSiteCertificate(
            $manifestDir,
            $root,
            $name,
            $manifest,
            $root . '/' . self::CERTIFICATE_DIR . '/' . $name . '.json'
        );
        if ($existing !== null) {
            $candidate = self::siteCertificateCandidate(
                $manifestDir,
                $name,
                $manifest,
                $adapterRaw,
                $tier,
                $authorityBinding,
                $trustRoot,
                $grammar,
                $ratificationRaw,
                $reason,
                $secret,
                $existing['created_at']
            );
            // Ed25519 signatures are deterministic. Equality here therefore
            // binds every signed input (including authority record, adapter
            // bytes, platform, PHP environment, grammar, reason and derived
            // ratification), rather than maintaining a second semantic
            // comparison beside the verifier. Only a certificate that passed
            // the live verifier above may lend its timestamp.
            if (hash_equals($existing['raw'], $candidate)) {
                return $existing['raw'];
            }
        }

        return self::siteCertificateCandidate(
            $manifestDir,
            $name,
            $manifest,
            $adapterRaw,
            $tier,
            $authorityBinding,
            $trustRoot,
            $grammar,
            $ratificationRaw,
            $reason,
            $secret,
            $freshCreatedAt
        );
    }

    /**
     * @return null|array{raw:string,created_at:string}
     */
    private static function verifiedExistingSiteCertificate(
        string $manifestDir,
        string $root,
        string $name,
        array $manifest,
        string $path
    ): ?array {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        try {
            $verified = self::verifyFile($manifestDir, $root, $name, $manifest, $path);
            $envelope = $verified['envelope'] ?? null;
            $encoded = is_array($envelope) ? ($envelope['certificate_json'] ?? null) : null;
            $digest = is_array($envelope) ? ($envelope['certificate_sha256'] ?? null) : null;
            $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($raw === false || !is_string($digest)
                || !hash_equals($digest, hash('sha256', $raw))) {
                return null;
            }
            [, , $certificate] = self::parseCanonicalObject($raw, 'verified site adapter certification');
            $createdAt = $certificate['statement']['bundle']['created_at'] ?? null;
            if (!is_string($createdAt)) {
                return null;
            }
            return ['raw' => $raw, 'created_at' => $createdAt];
        } catch (\Throwable) {
            // A superseded, malformed, revoked or otherwise unverifiable
            // certificate has no authority over the next signature's time.
            // The ordinary signing path below still validates every current
            // input and replaces it, preserving certify's existing repair
            // behavior without reusing untrusted bytes.
            return null;
        }
    }

    /**
     * Build and verify the exact site-profile candidate before Ed25519.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $authorityBinding
     */
    private static function siteCertificateCandidate(
        string $manifestDir,
        string $name,
        array $manifest,
        string $adapterRaw,
        string $tier,
        array $authorityBinding,
        string $trustRoot,
        string $grammar,
        string $ratificationRaw,
        string $reason,
        string $secret,
        string $createdAt
    ): string {
        $bundle = self::siteBundle(
            $name,
            $adapterRaw,
            $ratificationRaw,
            $grammar,
            $reason,
            $createdAt
        );

        // Verify the freshly built bundle through the SAME validator that will
        // re-verify it at every load. A producer that trusted its own output
        // would be the one place in this file where a certificate's grammar
        // was never checked.
        $bundleRaw = Canon::encode($bundle);
        [, $bundleTyped, $bundleArray] = self::parseCanonicalObject($bundleRaw, 'site certification bundle manifest');
        $info = self::verifyBundleManifest(
            $bundleTyped,
            $bundleArray,
            'site certification bundle manifest',
            $name,
            $trustRoot
        );
        self::assertBundleSubjectInput($info['bound_inputs'], $name, [
            'raw_sha256' => hash('sha256', $adapterRaw),
            'raw_size' => strlen($adapterRaw),
        ]);
        [, $ratificationTyped, $ratificationArray] = self::parseCanonicalObject(
            $ratificationRaw,
            'site certification ratification'
        );
        self::verifyRatification(
            $ratificationTyped,
            $ratificationArray,
            $name,
            $manifest,
            $info,
            $ratificationRaw
        );

        return self::signStatement(
            $manifestDir,
            $name,
            $manifest,
            $adapterRaw,
            $tier,
            $authorityBinding,
            $bundleTyped,
            $ratificationTyped,
            $secret
        );
    }

    /**
     * The real loader's verdict for this adapter, in this repository.
     *
     * Policy is required HERE rather than at file scope for the reason
     * AdapterSources gives for its own lazy require of this class: the two
     * form a cycle through the source/certification integration boundary, and
     * a file-scope edge would make otherwise independent offline entry points
     * order-sensitive.
     *
     * The manifest directory must be the one this process would load anyway.
     * A verdict judged against a different library than the verifier will use
     * is not a verdict about anything.
     */
    private static function siteGrammarVerdict(string $manifestDir, string $repo, string $name): string {
        require_once __DIR__ . '/../Policy/Policy.php';
        $resolvedDeclared = realpath($manifestDir);
        $resolvedLoaded = realpath(Policy::manifests_dir());
        if ($resolvedDeclared === false || $resolvedLoaded === false
            || !hash_equals($resolvedLoaded, $resolvedDeclared)) {
            throw new \RuntimeException(
                'duo: site adapter certification must be signed against the manifest library this process loads ('
                . ($resolvedLoaded === false ? '(unresolvable)' : $resolvedLoaded) . '), not '
                . ($resolvedDeclared === false ? '(unresolvable)' : $resolvedDeclared)
            );
        }
        try {
            Policy::load($repo, [$name]);
        } catch (\Throwable $t) {
            throw new \RuntimeException(
                "duo: site adapter '$name' does not load, so there is no grammar verdict to certify: "
                . $t->getMessage()
            );
        }
        return AdapterSources::GRAMMAR_OK;
    }

    /**
     * A ratification DERIVED from the manifest, never authored beside it.
     *
     * Every field is a restatement of something the manifest already declares
     * or of something a grammar check provably did not review:
     *
     *   - the sections are exactly the state surfaces the manifest declares,
     *     partitioned by the shipped entity/field vocabulary;
     *   - `deletion_semantics` is unsupported outright, and `operations` omits
     *     `delete`, because deletion semantics are what a validator run cannot
     *     review;
     *   - `lifecycle_phases` is empty for the same reason;
     *   - every intent-only table is marked unsupported (ManifestDispositions
     *     requires it, and the requirement is right: an
     *     authored_typed_snapshot_post_v1 table is a declaration of intent);
     *   - every open-ended `default_class: authored` keyspace is recorded
     *     `unsupported` rather than `justified` — justification is a review
     *     judgement about a plugin-upgrade tripwire, and nobody made one here.
     *
     * @return array<string,mixed>
     */
    private static function siteRatification(string $name, array $manifest, string $reason): array {
        $entity = [];
        $field = [];
        foreach (array_keys($manifest) as $key) {
            $key = (string) $key;
            if (in_array($key, self::ENTITY_SECTIONS, true)) {
                $entity[] = $key;
            } elseif (in_array($key, self::FIELD_SECTIONS, true)) {
                $field[] = $key;
            } elseif (!in_array($key, self::NON_SURFACE_KEYS, true)) {
                throw new \RuntimeException(
                    "duo: site adapter '$name' declares '$key', which this signer cannot classify as an entity "
                    . 'or field surface — a certificate that silently omitted it would cover less than the '
                    . 'adapter does. Certify it through a reviewed bundle, or teach the signer this section'
                );
            }
        }
        sort($entity, SORT_STRING);
        sort($field, SORT_STRING);

        $unsupported = [[
            'operation' => 'delete',
            'reason' => 'A manifest grammar verdict reviews no deletion semantics.',
            'surface' => 'deletions.*',
        ]];
        $keyspaces = [];
        foreach ((array) ($manifest['tables'] ?? []) as $table => $rule) {
            $table = (string) $table;
            if (!is_array($rule)) {
                continue;
            }
            if (($rule['class'] ?? null) === 'authored_typed_snapshot_post_v1') {
                $unsupported[] = [
                    'operation' => 'apply',
                    'reason' => 'An intent-only table is a declaration, not a reviewed convergence surface.',
                    'surface' => "tables.$table",
                ];
            }
            if (($rule['default_class'] ?? null) === 'authored') {
                $keyspaces[] = [
                    'reason' => 'The open-ended authored default was declared by the site operator and reviewed '
                        . 'by no exercise; a key introduced by a later plugin version is not covered.',
                    'status' => 'unsupported',
                    'table' => $table,
                ];
            }
        }
        usort($unsupported, static fn(array $a, array $b): int => [$a['surface'], $a['operation']]
            <=> [$b['surface'], $b['operation']]);
        usort($keyspaces, static fn(array $a, array $b): int => strcmp($a['table'], $b['table']));

        $plugin = $manifest['plugin'] ?? null;
        $versions = is_string($plugin) && $plugin !== ''
            // validate_entry() compares both against the manifest itself, so
            // this is a restatement rather than a claim.
            ? ['plugin' => $plugin, 'range' => $manifest['version_range'] ?? null]
            : ['source' => 'site-operator'];

        return [
            'format' => self::RATIFICATION_FORMAT,
            'manifests' => [
                $name => [
                    'capabilities' => [
                        'deletion_semantics' => [
                            'supported' => [],
                            'unsupported' => ['every declared deletion selector'],
                        ],
                        'entity_sections' => $entity,
                        'field_sections' => $field,
                        'lifecycle_phases' => [],
                        'operations' => self::SITE_OPERATIONS,
                    ],
                    'default_authored_keyspaces' => $keyspaces,
                    'evidence' => [
                        'bundle_schema' => self::BUNDLE_FORMAT,
                        'tests' => [],
                    ],
                    'reason' => $reason,
                    'status' => 'certified',
                    'supported_versions' => $versions,
                    'unsupported' => $unsupported,
                ],
            ],
            'profiles' => [],
        ];
    }

    /**
     * The unexercised bundle, in memory.
     *
     * `git_revision` is the nil SHA because this profile binds no evidence
     * repository at all. Borrowing the site repository's HEAD would read as
     * provenance for a review that did not happen, and the field is 40-hex by
     * grammar — so the honest value is the one that means "no commit".
     *
     * @return array<string,mixed>
     */
    private static function siteBundle(
        string $name,
        string $adapterRaw,
        string $ratificationRaw,
        string $grammar,
        string $reason,
        string $createdAt
    ): array {
        $environment = [
            'exercised' => false,
            'note' => 'no environment was exercised; this certificate binds the manifest grammar only',
            'php' => PHP_VERSION,
        ];
        $environmentRaw = Canon::encode($environment);
        $bundle = [
            'artifacts' => [],
            'bound_inputs' => [[
                'path' => 'adapters/' . $name . '.json',
                'sha256' => hash('sha256', $adapterRaw),
                'size' => strlen($adapterRaw),
            ]],
            'created_at' => $createdAt,
            'environment' => [
                'path' => 'environment.json',
                'sha256' => hash('sha256', $environmentRaw),
                'size' => strlen($environmentRaw),
            ],
            'environment_summary' => $environment,
            'evidence' => [
                'exercised' => false,
                'grammar' => $grammar,
                'reason' => $reason,
            ],
            'force_hatches' => [],
            'git_revision' => str_repeat('0', 40),
            'harness' => ['name' => 'duo-adapter-certify', 'version' => 1],
            'ratification' => [
                'path' => 'ratification.json',
                'sha256' => hash('sha256', $ratificationRaw),
                'size' => strlen($ratificationRaw),
            ],
            'ratification_summary' => [
                'certified_claims' => ['manifests.' . $name],
                'manifest_count' => 1,
                'profile_count' => 0,
            ],
            'schema_version' => self::BUNDLE_FORMAT,
            'subject' => ['kind' => 'site_adapter', 'name' => $name],
            'tests' => [],
            'verdict' => 'pass',
        ];
        $bundle['bundle_digest'] = self::bundleDigest($bundle);
        return $bundle;
    }

    /**
     * The one place a certificate is minted, shared by the reviewed-exercise
     * path and the site path. Both bind the identical statement shape; a
     * second builder would be a second thing to keep in step with the
     * verifier.
     *
     * @param array<string,mixed> $authorityBinding
     */
    private static function signStatement(
        string $manifestDir,
        string $name,
        array $manifest,
        string $adapterRaw,
        string $tier,
        array $authorityBinding,
        object $bundleTyped,
        object $ratificationTyped,
        string $secret
    ): string {
        [, , $platformRecord] = self::currentPlatform($manifestDir);
        $statement = [
            'adapter' => [
                'canonical_sha256' => self::canonicalHash($manifest),
                'name' => $name,
                'path' => 'adapters/' . $name . '.json',
                'raw_sha256' => hash('sha256', $adapterRaw),
                'raw_size' => strlen($adapterRaw),
                'source' => AdapterSources::SITE,
                'trust_tier' => $tier,
            ],
            'authority' => $authorityBinding,
            // Keep the exact, verified bundle manifest and ratification as
            // objects.  The signature binds their content-addressed identity,
            // all declared asset descriptors, and all named test claims.
            'bundle' => $bundleTyped,
            // The exercised compatibility cells, not the boundary document.
            // Derived through ManifestDispositions::narrowed_environment(), so
            // an adapter that declared § v3.5 narrowing binds its NARROWED
            // cells and an adapter that declared a WIDER environment than the
            // reviewed boundary refuses HERE, at mint time, instead of minting
            // a certificate that only refuses when a site first loads it.
            'platform' => self::platformStatement($platformRecord, $manifest),
            'ratification' => $ratificationTyped,
            'version' => self::STATEMENT_VERSION,
        ];
        $signature = sodium_crypto_sign_detached(self::signatureBytes($statement), $secret);
        return Canon::encode([
            'format' => self::FORMAT,
            'signature' => base64_encode($signature),
            'statement' => $statement,
        ]);
    }

    /** Return a non-secret summary suitable for the command-line tool. */
    public static function certificateSummary(array $verified): array {
        $provenance = is_array($verified['provenance'] ?? null) ? $verified['provenance'] : [];
        $proof = is_array($provenance['proof'] ?? null) ? $provenance['proof'] : [];
        $certification = is_array($verified['claim']['certification'] ?? null)
            ? $verified['claim']['certification']
            : [];
        return [
            'authority' => $proof['authority'] ?? null,
            'bundle_digest' => $proof['bundle']['digest'] ?? null,
            'exercised' => $proof['bundle']['exercised'] ?? null,
            'name' => $verified['claim']['name'] ?? null,
            // The two words a host prints beside `Site-certified`: WHO
            // certified, and under WHICH root. Projected from the verified
            // claim rather than re-derived, so the printed line cannot drift
            // from the claim `duo promote` gates on.
            'principal' => $certification['principal'] ?? null,
            'status' => $verified['claim']['status'] ?? null,
            'trust_root' => $certification['trust_root'] ?? null,
            'trust_tier' => $verified['disposition']['trust_tier'] ?? null,
        ];
    }

    /** @return array{format:string,certificate_sha256:string,certificate_json:string} */
    private static function envelope(string $certificateRaw): array {
        return [
            'format' => self::ENVELOPE_FORMAT,
            'certificate_sha256' => hash('sha256', $certificateRaw),
            'certificate_json' => base64_encode($certificateRaw),
        ];
    }

    /**
     * Verify the signed statement after either live or frozen input handling.
     * $rawAdapter is deliberately nullable only for frozen verification.
     */
    private static function verifyCertificate(
        string $manifestDir,
        string $name,
        array $manifest,
        string $certificateRaw,
        object $certificateTyped,
        array $certificate,
        ?array $rawAdapter,
        ?string $repoRoot
    ): array {
        self::assertCertificateShape($certificateTyped, $certificate);
        $statementTyped = $certificateTyped->statement;
        $statement = $certificate['statement'];
        self::assertStatementShape($name, $statementTyped, $statement);

        $adapter = $statement['adapter'];
        $tier = AdapterSources::trust_tier($manifest);
        self::assertAdapterBinding($name, $manifest, $adapter, $tier, $rawAdapter);

        $statementAuthority = $statement['authority'];
        self::assertExactKeys(
            $statementAuthority,
            ['fingerprint', 'key_id', 'record', 'record_sha256', 'trust_root'],
            'certification authority binding'
        );
        $selectedAuthority = $statementAuthority['key_id'] ?? null;
        if (!is_string($selectedAuthority)) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification authority key_id must be a canonical string selector"
            );
        }
        $claimedRoot = $statementAuthority['trust_root'] ?? null;
        if (!in_array($claimedRoot, [self::TRUST_ROOT_PLATFORM, self::TRUST_ROOT_SITE], true)) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification must name trust root "
                . self::TRUST_ROOT_PLATFORM . ' or ' . self::TRUST_ROOT_SITE
            );
        }
        $embeddedRecord = $statementAuthority['record'] ?? null;
        if (!is_array($embeddedRecord) || array_is_list($embeddedRecord)
            || !isset($statementTyped->authority->record) || !is_object($statementTyped->authority->record)) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification authority binding must carry its exact authority record"
            );
        }
        if ($claimedRoot === self::TRUST_ROOT_SITE) {
            // SHIPPED WINS THE KEY-ID NAMESPACE ON BOTH PATHS (G2-FIXES m3).
            // The frozen branch below has always asked this; the LIVE path did
            // not, and got its refusal by accident instead: `authority()` finds
            // the shipped record first, resolves trust root `platform`, and the
            // binding check then answers "does not match the current platform
            // authority record" — true, and about the wrong thing. An operator
            // who re-pointed a key id this project reviews needs to be told
            // THAT, which is the sentence R-13 records. Hoisted rather than
            // duplicated so one rule has one call site.
            self::assertKeyIdNotPlatformOwned($manifestDir, self::keyId($selectedAuthority));
        }
        if ($claimedRoot === self::TRUST_ROOT_SITE && $repoRoot === null) {
            // THE ONE ASYMMETRY BETWEEN THE TWO ROOTS, and it is a property of
            // where each root LIVES rather than a weaker rule.
            //
            // Frozen verification deliberately reopens no mutable site file
            // (see verifyFrozen()), so a site-rooted certificate has no
            // adapters/authorities.json to consult here. It is re-bound to the
            // authority record the SIGNATURE covers instead. Concretely: a
            // platform key revoked in the shipped file stops verifying frozen
            // snapshots immediately, while a site key revoked in the
            // operator's repository stops verifying on every live scan
            // (discover() reopens the file each run) but not inside an already
            // frozen snapshot. That is the operator's own root, revoked by the
            // operator, in a document the same operator produced; treating it
            // as a platform revocation would be claiming a custody property
            // this profile explicitly defers (T6 §2).
            //
            // What is NOT relaxed: the shipped library still wins the key-id
            // namespace, and that check needs no repository at all.
            //
            // WP-4.9 CLOSED THE OTHER HALF OF THIS GAP without moving the
            // asymmetry above one inch. The paragraph's premise — "that is the
            // operator's own root, revoked by the operator" — fails the moment
            // federation-by-copy puts a delegated VENDOR key in a site root, and
            // that population is exactly what the typed revocation document
            // reaches: it is agent-owned (`capabilities/adapter-revocations.json`,
            // beside the shipped trust root this branch already reads through
            // assertKeyIdNotPlatformOwned()), platform-signed, and therefore
            // readable here where no mutable SITE file is. So a revoked vendor
            // key stops verifying frozen, a `status` flip in the operator's own
            // document still does not, and assertNotRevoked()'s sentence names
            // the distinction so an operator can tell which one answered.
            self::validateAuthorityRecord(
                $embeddedRecord,
                "site adapter certification key '$selectedAuthority'",
                $selectedAuthority
            );
            self::assertNotRevoked($manifestDir, self::keyId($selectedAuthority), $embeddedRecord);
            $authority = $embeddedRecord;
            $keyId = self::keyId($selectedAuthority);
            $trustRoot = self::TRUST_ROOT_SITE;
        } else {
            [$authority, $keyId, , $trustRoot] = self::authority(
                $manifestDir,
                $selectedAuthority,
                $repoRoot
            );
        }
        self::assertAuthorityBinding(
            $authority,
            $keyId,
            $trustRoot,
            $statementAuthority,
            $name,
            $tier
        );
        // The proof (and through it the adapter digest every repository pin
        // binds) records the authority record the certificate was SIGNED over —
        // under BOTH roots now, not the current file's digest under either. A
        // trust root grows with every certified adapter, and a proof that
        // followed the current file would move the pinned digest of every
        // earlier adapter each time; the frozen path re-derives exactly this
        // digest from the embedded record, so live and frozen say one thing.
        // Reached only after the binding above proved this digest is the
        // embedded record's own, so it is never an unverified input. For every
        // certificate that verified before WP-4.8 this is the identical value:
        // the platform branch used to REQUIRE record_sha256 to equal the
        // installed record's digest, so the two were equal by construction.
        $authorityDigest = (string) $statementAuthority['record_sha256'];
        $signature = base64_decode((string) $certificate['signature'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached(
                $signature,
                self::signatureBytes($statementTyped),
                self::publicKey($authority)
            )) {
            throw new \RuntimeException("duo: site adapter '$name' certification has an invalid Ed25519 signature");
        }

        // Reached only after the signature and the authority binding above have
        // both verified, which is exactly why the typed signal below is safe:
        // this line can only be about an AGENT-owned document that moved, never
        // about the companion's provenance.
        [, , $platformRecord] = self::currentPlatform($manifestDir);
        $platformDigest = self::assertPlatformBinding($name, $platformRecord, $statement['platform']);

        $bundle = self::verifyEmbeddedBundle($statementTyped->bundle, $statement['bundle'], $name, $trustRoot);
        self::assertBundleSubjectInput($bundle['bound_inputs'], $name, $adapter);
        [$ratification, $disposition, $ratificationRaw] = self::verifyEmbeddedRatification(
            $statementTyped->ratification,
            $statement['ratification'],
            $name,
            $manifest,
            $bundle
        );
        unset($ratification); // The signed exact document is represented in the envelope/proof below.

        $certificateDigest = hash('sha256', $certificateRaw);
        $statementDigest = hash('sha256', Canon::encode($statementTyped));
        $derived = self::derivedDisposition(
            $name,
            $adapter,
            $tier,
            $disposition,
            $statementAuthority,
            $authorityDigest,
            $bundle,
            $platformDigest,
            $ratificationRaw,
            $certificateDigest,
            $statementDigest
        );

        return [
            'disposition' => $derived,
            // The CURRENT boundary, not the signed one — and it is the same
            // answer, narrowed by the same declaration: the binding above just
            // proved every cell this certificate covers is still carried and
            // still stated the same way, and § v3.5's narrowing is applied to
            // whichever record it is handed. At v1 there was no choice to make
            // (the two records were byte-equal or the certificate was gone);
            // at v2 the signed member carries digests rather than a boundary,
            // so the live document is the only record a claim can be projected
            // from at all.
            'claim' => self::projectClaim($name, $manifest, $disposition, $derived, $platformRecord),
            'provenance' => $derived['provenance'],
        ];
    }

    private static function assertSodium(): void {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('duo: signed site adapter certification requires the PHP sodium extension');
        }
    }

    private static function agentVersion(): string {
        return defined('DUO_AGENT_VERSION') ? (string) DUO_AGENT_VERSION : '0.5.0';
    }

    private static function specVersion(): int {
        return defined('DUO_SPEC_VERSION') ? (int) DUO_SPEC_VERSION : 2;
    }

    private static function adapterName(string $name): string {
        AdapterSources::assert_name($name, 'site adapter certification name');
        return $name;
    }

    private static function keyId(string $id): string {
        AdapterSources::assert_name($id, 'authority key id');
        return $id;
    }

    private static function repoRoot(string $repo, string $label): string {
        $root = realpath($repo);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException("duo: $label is absent or not a directory: $repo");
        }
        return rtrim($root, '/');
    }

    /** A deliberately non-normalising relative path grammar. */
    private static function relativePath(string $path, string $label): string {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new \RuntimeException("duo: $label must be a non-empty slash-separated relative path");
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \RuntimeException("duo: $label is not a canonical relative path: $path");
            }
        }
        return $path;
    }

    /**
     * Resolve a regular file only when every component remains inside an exact
     * physical root.  This closes final-file and ancestor symlinks alike.
     */
    private static function ownedFile(string $root, string $relative, string $label): string {
        $relative = self::relativePath($relative, $label . ' path');
        $expected = $root . '/' . $relative;
        if (!is_file($expected) || is_link($expected)) {
            throw new \RuntimeException("duo: $label is absent, not a regular file, or a symbolic link: $relative");
        }
        $resolved = realpath($expected);
        if ($resolved === false || !hash_equals($expected, $resolved)) {
            throw new \RuntimeException("duo: $label must resolve exactly inside its declared root: $relative");
        }
        return $resolved;
    }

    /** @return array{0:string,1:object,2:array} */
    private static function readCanonicalObjectFile(string $path, string $label): array {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("duo: cannot read $label: $path");
        }
        return self::parseCanonicalObject($raw, $label);
    }

    /** @return array{0:string,1:object,2:array} */
    private static function parseCanonicalObject(string $raw, string $label): array {
        try {
            $typed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("duo: $label is not valid JSON: " . $e->getMessage());
        }
        if (!is_object($typed) || !is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException("duo: $label JSON root must be an object");
        }
        if (!hash_equals(Canon::encode($typed), $raw)) {
            throw new \RuntimeException("duo: $label must use Duo canonical JSON bytes");
        }
        return [$raw, $typed, $decoded];
    }

    /**
     * The existing certification-bundle producer deliberately uses compact
     * canonical JSON for its digest and four-space pretty canonical JSON for
     * bundle.json/result assets.  It is not the repository-file Canon format;
     * accepting that producer's exact bytes avoids inventing a second bundle
     * identity while still refusing whitespace/key-order ambiguity.
     *
     * @return array{0:string,1:object,2:array}
     */
    private static function readBundleObjectFile(string $path, string $label): array {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("duo: cannot read $label: $path");
        }
        return self::parseBundleObject($raw, $label);
    }

    /** @return array{0:string,1:object,2:array} */
    private static function parseBundleObject(string $raw, string $label): array {
        try {
            $typed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("duo: $label is not valid JSON: " . $e->getMessage());
        }
        if (!is_object($typed) || !is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException("duo: $label JSON root must be an object");
        }
        if (!hash_equals(self::bundlePretty($typed), $raw)) {
            throw new \RuntimeException("duo: $label must use certification-bundle canonical JSON bytes");
        }
        return [$raw, $typed, $decoded];
    }

    private static function bundlePretty($value): string {
        try {
            $json = json_encode(
                Canon::normalize($value),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('duo: certification bundle is not canonicalizable: ' . $e->getMessage());
        }
        return $json . "\n";
    }

    /** @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException(
                "duo: $label must contain exactly " . implode(', ', $expected)
            );
        }
    }

    /** @param mixed $value */
    private static function stringList($value, string $label, bool $allowEmpty = true): array {
        if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
            throw new \RuntimeException("duo: $label must be a " . ($allowEmpty ? 'list' : 'non-empty list'));
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || isset($seen[$item])) {
                throw new \RuntimeException("duo: $label must contain unique non-empty strings");
            }
            $seen[$item] = true;
        }
        return $value;
    }

    /** @param mixed $value */
    private static function sha($value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    /** Base64 is part of a signed/agent-owned grammar, never a loose codec. */
    private static function isCanonicalBase64(string $encoded, string $decoded): bool {
        return $encoded !== '' && hash_equals(base64_encode($decoded), $encoded);
    }

    private static function canonicalHash($value): string {
        return hash('sha256', Canon::encode($value));
    }

    private static function signatureBytes($statement): string {
        return self::SIGNATURE_DOMAIN . Canon::encode($statement);
    }

    private static function assertSiteManifest(string $name, array $manifest, string $manifestDir): void {
        if (($manifest['name'] ?? null) !== $name) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification requires adapters/$name.json to declare the same name"
            );
        }
        // These are trust/certification facts, not adapter facts.  A manifest
        // that carries one is refused instead of leaving a future reader to
        // decide whether it is inert, partially honoured, or self-certifying.
        foreach ([
            'adapter_certificate', 'authority', 'authority_id', 'certificate',
            'certification', 'certification_authority', 'key_id', 'public_key',
            'signature', 'trust_tier',
        ] as $reserved) {
            if (array_key_exists($reserved, $manifest)) {
                throw new \RuntimeException(
                    "duo: site adapter '$name' declares reserved self-certification field '$reserved' — "
                    . 'trust roots and signatures belong only in agent-owned roots and adapters/certifications/'
                );
            }
        }
        // A site copy of a SHIPPED name is an override (T6 §3.3): its
        // interpreter / regenerator / provider declarations are admitted
        // exactly when they repeat the shipped grant, and refused when they
        // add one — the same rule the loader applies, so a certificate is
        // never signed over a manifest the engine would then refuse (the
        // walk's S4 hit exactly that split: the pre-flight load passed, the
        // signature step refused `out_of_tree_privilege`).
        AdapterSources::assert_out_of_tree_contract(
            $manifest,
            $name,
            'adapters/' . $name . '.json',
            'site adapter',
            false,
            AdapterSources::shipped_executable_grants($manifestDir, $name)
        );
    }

    private static function assertSameManifest(string $name, array $provided, array $raw): void {
        if (!hash_equals(self::canonicalHash($raw), self::canonicalHash($provided))) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certificate was asked to verify manifest bytes different from adapters/$name.json"
            );
        }
    }

    /**
     * Resolve one authority key across the two trust roots.
     *
     * THE PRECEDENCE IS SHIPPED-WINS, and it is the reason a site trust root
     * can exist at all: the operator writes their own authorities file, so
     * without this rule an operator could re-point a key id the agent library
     * reviews and have a certificate signed under it read as platform-rooted.
     * The shipped record is therefore consulted first, and a site record for
     * the same key id is never reached — not merged, not preferred, and not
     * silently ignored either, because a certificate that CLAIMED
     * `trust_root: site` for such a key then fails its binding check by name
     * (assertAuthorityBinding()) instead of quietly downgrading.
     *
     * Both files are validated WHOLE whenever they are opened, exactly as the
     * agent-owned file always was: an authority record nobody selected is
     * still authority-bearing bytes an operator believes in, and a malformed
     * sibling is a broken trust root rather than an unrelated file.
     *
     * @param ?string $repoRoot resolved site repository, or null where the
     *        caller reopens no mutable site file (frozen verification)
     * @return array{0:array,1:string,2:string,3:string} [record, key id, canonical record digest, trust root]
     */
    private static function authority(string $manifestDir, string $id, ?string $repoRoot): array {
        $id = self::keyId($id);
        $platform = self::authorityKeys(
            rtrim($manifestDir, '/') . '/' . self::AUTHORITIES_RELATIVE,
            'adapter certification authorities',
            true
        );
        $site = $repoRoot === null
            ? []
            : self::authorityKeys(
                rtrim($repoRoot, '/') . '/' . self::SITE_AUTHORITIES_RELATIVE,
                'site adapter certification authorities'
            );
        // THE THIRD SOURCE, and it is read LAST on purpose (§ v3.8, WP-4.9).
        //
        // An installed key outranks a delegated one, in both roots, because a
        // record an operator or a reviewer wrote down is a decision and a
        // delegation is a grant someone else made: letting a delegation shadow
        // an id already carried by either root would let a vendor answer for an
        // identity its holder had already scoped. delegatedKeys() refuses that
        // collision by name as well, so the ordering is belt and braces rather
        // than the only line holding it.
        //
        // Read lazily — only when neither root answered — so a repository that
        // installs no delegation pays nothing, and a platform-rooted
        // certificate is never refused by a delegation file it has no business
        // reading.
        $delegated = [];
        $selected = null;
        if (isset($platform[$id])) {
            $selected = [$platform[$id], $id, self::canonicalHash($platform[$id]), self::TRUST_ROOT_PLATFORM];
        } elseif (isset($site[$id])) {
            $selected = [$site[$id], $id, self::canonicalHash($site[$id]), self::TRUST_ROOT_SITE];
        } else {
            $delegated = self::delegatedKeys($manifestDir, $repoRoot, $platform, $site);
            if (isset($delegated[$id])) {
                // TRUST ROOT `site`, not a third word. A delegated key lives in
                // the site repository and certifies that repository's adapters,
                // which is exactly what `site` means in the signed statement
                // (R-13); minting a third value would spend the one extension
                // channel R-13 reserves for a genuinely new custody model, and
                // would make every deployed verifier refuse these certificates
                // by name. What the delegation adds is not a new root but a
                // documented provenance for a key inside the existing one —
                // and that is precisely the population the typed revocation
                // channel below exists to reach on the frozen path.
                $selected = [$delegated[$id], $id, self::canonicalHash($delegated[$id]), self::TRUST_ROOT_SITE];
            }
        }
        if ($selected === null) {
            $sources = self::AUTHORITIES_RELATIVE
                . ($repoRoot === null
                    ? ''
                    : ' or ' . self::SITE_AUTHORITIES_RELATIVE . ' or ' . self::SITE_DELEGATIONS_RELATIVE);
            if ($platform === [] && $site === [] && $delegated === []) {
                throw new \RuntimeException(
                    'duo: no adapter certification authorities are installed at ' . $sources
                );
            }
            throw new \RuntimeException(
                "duo: authority key '$id' is not installed in " . $sources
            );
        }
        self::assertNotRevoked($manifestDir, $selected[1], $selected[0]);

        return $selected;
    }

    /**
     * The delegated authority records a site repository installs, verified.
     *
     * WHAT A DELEGATION IS. A PLATFORM key's signed grant of a namespace
     * pattern, a tier set and a validity window to a VENDOR key, installed
     * site-side. It is the answer to the question the shipped trust root cannot
     * answer at scale: `manifests/capabilities/adapter-authorities.json` is
     * reviewed by this project and moves on an agent release, so enrolling every
     * vendor there makes review the bottleneck for the whole ecosystem. A
     * delegation moves that decision to a key the project already reviewed,
     * once, and bounds what that key can hand on.
     *
     * WHAT IT IS NOT, and the refusals are the specification:
     *
     *   1. It is not a CHAIN. Verification chains exactly DELEGATION_DEPTH
     *      levels and refuses a delegate that delegates BY NAME, before it tries
     *      to resolve that delegator in the platform root — because "your
     *      delegator is not a platform key" and "delegates may not delegate" are
     *      different findings and an operator must be told which one happened.
     *   2. It is not a WIDENING. Every name and tier in the grant must be
     *      covered by the delegator's own, judged by the same
     *      `<vendor>-*` grammar § v3.7 gave `adapter_names`, and the window must
     *      lie inside the delegator's when the delegator has one.
     *   3. It is not a SITE power. A delegation signed under a site key refuses:
     *      the delegator is resolved in the shipped platform root and nowhere
     *      else, so an operator cannot bootstrap standing they were never given.
     *   4. It is not INDEPENDENT of its delegator. The delegator's status,
     *      window and typed revocation are all judged at verify time against the
     *      CURRENT shipped root, so revoking the delegator invalidates every
     *      delegate it made, at once and without touching a site.
     *
     * The record this returns is a synthesized `record_version: 2` authority
     * record put through validateAuthorityRecord() before it is handed back, so
     * a delegation can never produce a record the installed grammar would have
     * refused — reuse rather than a second grammar for the same object.
     *
     * @param array<string,array<string,mixed>> $platform the shipped root, already read
     * @param array<string,array<string,mixed>> $site the site root, already read
     * @return array<string,array<string,mixed>>
     */
    private static function delegatedKeys(
        string $manifestDir,
        ?string $repoRoot,
        array $platform,
        array $site
    ): array {
        if ($repoRoot === null) {
            // The frozen path holds no repository, so it reaches no delegation
            // document — the same absence verifyCertificate() states for the
            // site trust root. That is exactly why a delegated key's revocation
            // travels through the agent-owned channel below instead.
            return [];
        }
        $file = rtrim($repoRoot, '/') . '/' . self::SITE_DELEGATIONS_RELATIVE;
        $label = 'site adapter certification delegations';
        if (!file_exists($file) && !is_link($file)) {
            return [];
        }
        if (!is_file($file) || is_link($file)) {
            throw new \RuntimeException("duo: $label must be an ordinary regular file: $file");
        }
        self::assertSodium();
        [, $typed, $data] = self::readCanonicalObjectFile($file, $label);
        self::assertExactKeys($data, self::DELEGATIONS_ENVELOPE_KEYS, $label);
        if (($data['format'] ?? null) !== self::DELEGATIONS_FORMAT
            || !is_array($data['delegations'] ?? null)
            || !isset($typed->delegations) || !is_object($typed->delegations)) {
            throw new \RuntimeException("duo: $label have an unsupported or malformed root");
        }
        if ($data['delegations'] === []) {
            // Inert authority bytes an operator believes in are the failure mode
            // this whole source refuses: a file that grants nothing reads as a
            // vendor who has been enrolled and has not.
            throw new \RuntimeException(
                "duo: $label carry no delegations — remove the file rather than installing one that grants nothing"
            );
        }
        // The chain refusal needs the whole delegate set before any single
        // delegation is judged, so it is collected first: otherwise a two-level
        // chain would be refused as "delegator not installed" or accepted
        // depending on nothing but map order.
        $delegateIds = [];
        foreach ($data['delegations'] as $mapKey => $ignored) {
            if (is_string($mapKey)) {
                $delegateIds[$mapKey] = true;
            }
        }
        $out = [];
        foreach ($data['delegations'] as $mapKey => $delegation) {
            if (!is_string($mapKey)) {
                throw new \RuntimeException(
                    "duo: $label contain non-string delegate key " . var_export($mapKey, true)
                    . ' — numeric-only identities are forbidden because PHP coerces JSON object-map keys to integers'
                );
            }
            $delegateId = self::keyId($mapKey);
            if (!is_array($delegation) || array_is_list($delegation)
                || !isset($typed->delegations->{$mapKey}) || !is_object($typed->delegations->{$mapKey})) {
                throw new \RuntimeException("duo: $label delegation '$delegateId' must be a JSON object");
            }
            $out[$delegateId] = self::verifyDelegation(
                $manifestDir,
                $delegateId,
                $delegation,
                $typed->delegations->{$mapKey},
                $delegateIds,
                $platform,
                $site
            );
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * One delegation, verified into the authority record it grants.
     *
     * @param array<string,bool> $delegateIds every id this document delegates
     * @param array<string,array<string,mixed>> $platform
     * @param array<string,array<string,mixed>> $site
     * @return array<string,mixed>
     */
    private static function verifyDelegation(
        string $manifestDir,
        string $delegateId,
        array $delegation,
        object $typed,
        array $delegateIds,
        array $platform,
        array $site
    ): array {
        $label = "site adapter certification delegation '$delegateId'";
        self::assertExactKeys($delegation, self::DELEGATION_KEYS, $label);
        $statement = $delegation['statement'] ?? null;
        if (!is_array($statement) || array_is_list($statement)
            || !isset($typed->statement) || !is_object($typed->statement)) {
            throw new \RuntimeException("duo: $label statement must be a JSON object");
        }
        self::assertExactKeys($statement, self::DELEGATION_STATEMENT_KEYS, "$label statement");
        // BY VERSION, never as corruption — the property `record_version` gives
        // an authority record and § v3.6 gives the certification statement.
        // Tested before the format word so a v2 statement carrying a v1 format
        // is still answered with the version it declares.
        if (($statement['version'] ?? null) !== 1) {
            throw new \RuntimeException(
                "duo: $label declares delegation version " . var_export($statement['version'] ?? null, true)
                . ', which this agent does not implement — a delegation version is refused by version, never'
                . ' read as a v1 delegation with unexpected members'
            );
        }
        if (($statement['format'] ?? null) !== self::DELEGATION_FORMAT) {
            throw new \RuntimeException(
                "duo: $label statement must declare format " . self::DELEGATION_FORMAT
                . ' inside its own signature; the envelope\'s format is outside every signature and proves nothing'
            );
        }

        $delegate = $statement['delegate'] ?? null;
        if (!is_array($delegate) || array_is_list($delegate)) {
            throw new \RuntimeException("duo: $label statement delegate must be a JSON object");
        }
        self::assertExactKeys($delegate, self::DELEGATION_DELEGATE_KEYS, "$label delegate");
        if (($delegate['algorithm'] ?? null) !== 'ed25519') {
            throw new \RuntimeException("duo: $label delegate must declare algorithm ed25519");
        }
        if (($delegate['key_id'] ?? null) !== $delegateId) {
            throw new \RuntimeException(
                "duo: $label is installed under key id '$delegateId' but its SIGNED statement names "
                . var_export($delegate['key_id'] ?? null, true)
                . ' — the map key and the delegated identity are one value, not two'
            );
        }
        $delegatePublic = self::publicKey($delegate);
        // The v2 fingerprint rule, reused rather than restated: a delegated id
        // is a v2 identity and must derive from its own key material, so a
        // vendor cannot be handed a grant under a name nobody holding that key
        // could honestly claim.
        self::assertKeyIdBindsKeyMaterial($delegateId, $delegatePublic, "$label delegate");
        if (isset($platform[$delegateId])) {
            throw new \RuntimeException(
                "duo: authority key '$delegateId' is reviewed and shipped by this agent, so a delegation cannot"
                . ' claim it'
            );
        }
        if (isset($site[$delegateId])) {
            throw new \RuntimeException(
                "duo: $label delegates an id the site trust root already carries at "
                . self::SITE_AUTHORITIES_RELATIVE . ' — one identity has one record, never a written one and a'
                . ' granted one that could disagree'
            );
        }

        $delegator = $statement['delegator'] ?? null;
        if (!is_array($delegator) || array_is_list($delegator)) {
            throw new \RuntimeException("duo: $label statement delegator must be a JSON object");
        }
        self::assertExactKeys($delegator, self::DELEGATION_DELEGATOR_KEYS, "$label delegator");
        $delegatorId = is_string($delegator['key_id'] ?? null) ? self::keyId($delegator['key_id']) : '';
        if ($delegatorId === '') {
            throw new \RuntimeException("duo: $label delegator key_id must be a canonical string selector");
        }
        // (1) DEPTH. Refused here, before the platform lookup below, so a chain
        // reads as a chain: resolving first would report the honest but useless
        // "not installed in capabilities/adapter-authorities.json".
        if (isset($delegateIds[$delegatorId])) {
            throw new \RuntimeException(
                "duo: $label is delegated by '$delegatorId', which is itself a delegate — verification chains"
                . ' exactly ' . self::DELEGATION_DEPTH . ' level and a delegate may not delegate'
            );
        }
        // (3) A SITE KEY CANNOT DELEGATE, said twice: once by the word inside
        // the signature and once by where the key is looked up. The word is
        // checked first so the refusal names the claim rather than the miss.
        if (($delegator['trust_root'] ?? null) !== self::TRUST_ROOT_PLATFORM) {
            throw new \RuntimeException(
                "duo: $label names trust root " . var_export($delegator['trust_root'] ?? null, true)
                . ' — a delegation is made by a ' . self::TRUST_ROOT_PLATFORM . ' key; a '
                . self::TRUST_ROOT_SITE . ' key certifies its own repository and delegates nothing'
            );
        }
        if (!isset($platform[$delegatorId])) {
            throw new \RuntimeException(
                "duo: $label is delegated by '$delegatorId', which is not installed in "
                . self::AUTHORITIES_RELATIVE . ' — a delegation is rooted in the shipped, reviewed trust root and'
                . ' never in the site\'s own'
            );
        }
        $delegatorRecord = $platform[$delegatorId];
        $delegatorPublic = self::publicKey($delegatorRecord);
        if (($delegator['fingerprint'] ?? null) !== hash('sha256', $delegatorPublic)) {
            throw new \RuntimeException(
                "duo: $label binds a delegator fingerprint that is not the current "
                . self::AUTHORITIES_RELATIVE . " record's for '$delegatorId' — the key under that id moved"
            );
        }
        // CRYPTO FIRST, and the ordering is the rule (G2-FIXES M7). Everything
        // above resolves the DELEGATOR — an identity looked up in the shipped
        // root, whose fingerprint the statement had to match. Everything below
        // reads what the STATEMENT CLAIMS: the names it grants, the tiers, the
        // window. Verifying the signature between the two is what makes every
        // refusal below a statement about a document its delegator actually
        // signed. Before it, a tampered delegation reported the narrowing rule
        // it happened to break — an attacker-chosen sentence, and one that
        // reads as a policy problem rather than as forgery.
        //
        // No refusal moved a byte; only their order did. The narrowing rules
        // are still enforced by the verifier and not merely by the producer,
        // and `regress_authority_delegation.php` pins that a widened grant
        // signed by the WRONG key now answers with the signature.
        $signature = $delegation['signature'] ?? null;
        if (!is_array($signature) || array_is_list($signature)
            || !isset($typed->signature) || !is_object($typed->signature)) {
            throw new \RuntimeException("duo: $label signature must be a JSON object");
        }
        self::assertExactKeys($signature, self::DELEGATION_SIGNATURE_KEYS, "$label signature");
        if (($signature['key_id'] ?? null) !== $delegatorId) {
            throw new \RuntimeException(
                "duo: $label is signed by " . var_export($signature['key_id'] ?? null, true)
                . " but its statement names delegator '$delegatorId' — the signer and the delegator are one key"
            );
        }
        $encoded = $signature['value'] ?? null;
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($bytes === false || !is_string($encoded) || !self::isCanonicalBase64($encoded, $bytes)
            || strlen($bytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached(
                $bytes,
                self::delegationSignatureBytes($typed->statement),
                $delegatorPublic
            )) {
            throw new \RuntimeException(
                "duo: $label does not verify under delegator '$delegatorId'; an unsigned or tampered delegation is"
                . ' refused, never read as an absent grant'
            );
        }

        // (4) THE DELEGATOR IS JUDGED LIVE, against the CURRENT shipped root, so
        // a revoked delegator invalidates every delegate it made at once and
        // without touching a site. Both revocation mechanisms are asked: the
        // `status` word in the shipped file, and the typed out-of-band record
        // that does not wait for an agent release.
        //
        // ITS WINDOW IS DELIBERATELY NOT ASKED HERE, and the omission is load
        // bearing rather than an oversight. § v3.7 already refused to apply the
        // authorities envelope signer's window for the blast-radius reason —
        // bricking every other vendor's key because one signer's window lapsed
        // — and the same reasoning applies with more force to a document a whole
        // site source is judged against. Nothing is lost by it: the containment
        // rule below forces every grant inside its delegator's window, so a
        // closed delegator window means every delegate's own window has closed
        // too, and each one is then refused where every window in this file is
        // refused — assertAuthorityScope(), naming the delegate's own instant.
        if (($delegatorRecord['status'] ?? null) !== 'trusted') {
            throw new \RuntimeException(
                "duo: authority key '$delegatorId' is revoked and cannot certify adapters, so the delegation it"
                . " made to '$delegateId' grants nothing"
            );
        }
        self::assertNotRevoked($manifestDir, $delegatorId, $delegatorRecord);

        // (2) NARROWING, on all three axes. The grant is checked against the
        // delegator's CURRENT record rather than against anything inside the
        // signature, so narrowing the delegator later narrows its delegates too.
        $names = self::stringList($statement['adapter_names'] ?? null, "$label.adapter_names", false);
        foreach ($names as $entry) {
            self::assertScopeEntry($entry, "$label.adapter_names");
            if (!self::scopeEntryCovered((array) ($delegatorRecord['adapter_names'] ?? []), $entry)) {
                throw new \RuntimeException(
                    "duo: $label grants '$entry', which its delegator '$delegatorId' does not hold — a"
                    . ' delegation may only narrow the namespace it was given, never widen it'
                );
            }
        }
        $tiers = self::stringList($statement['trust_tiers'] ?? null, "$label.trust_tiers", false);
        foreach ($tiers as $tier) {
            if (!in_array($tier, (array) ($delegatorRecord['trust_tiers'] ?? []), true)) {
                throw new \RuntimeException(
                    "duo: $label grants trust tier '$tier', which its delegator '$delegatorId' does not hold — a"
                    . ' delegation may only narrow the tier set it was given, never widen it'
                );
            }
        }
        self::assertWindowShape($statement, $label);
        $grantBefore = self::instant($statement['not_before'], "$label.not_before");
        $grantAfter = self::instant($statement['not_after'], "$label.not_after");
        if (($delegatorRecord['record_version'] ?? null) !== null) {
            $rootBefore = self::instant($delegatorRecord['not_before'] ?? null, "authority key '$delegatorId'.not_before");
            $rootAfter = self::instant($delegatorRecord['not_after'] ?? null, "authority key '$delegatorId'.not_after");
            if ($grantBefore < $rootBefore || $grantAfter > $rootAfter) {
                throw new \RuntimeException(
                    "duo: $label is valid " . self::stamp($grantBefore) . '/' . self::stamp($grantAfter)
                    . ", outside its delegator '$delegatorId' window " . self::stamp($rootBefore) . '/'
                    . self::stamp($rootAfter) . ' — time is a scope like any other and narrows the same way'
                );
            }
        }

        $record = [
            'adapter_names' => $names,
            'algorithm' => 'ed25519',
            'not_after' => (string) $statement['not_after'],
            'not_before' => (string) $statement['not_before'],
            'public_key' => (string) $delegate['public_key'],
            'record_version' => 2,
            'scope' => 'site_adapter_certification',
            'status' => 'trusted',
            'trust_tiers' => $tiers,
        ];
        // The synthesized record goes through the SHIPPED record grammar before
        // anyone uses it: a delegation must not be able to produce an authority
        // record that an installed one could not have been.
        self::validateAuthorityRecord($record, "delegated adapter certification key '$delegateId'", $delegateId);
        // The delegate's own typed revocation, judged beside its delegator's.
        // Both, because the two are independent facts: an incident may burn one
        // vendor key without touching the platform key that enrolled it.
        self::assertNotRevoked($manifestDir, $delegateId, $record);

        return $record;
    }

    /** The exact bytes one delegation statement's signature covers. */
    private static function delegationSignatureBytes($statement): string {
        return self::SIGNATURE_DOMAIN_DELEGATION . Canon::encode($statement);
    }

    /**
     * Does the delegator's scope list COVER this delegated entry?
     *
     * Two rules, and the second is the one that stops a laundered widening. An
     * EXACT entry is covered when the delegator's list covers that exact name,
     * which is `scopeCoversName()` — the same function the live scope check
     * uses, so a delegation can never be judged by a second reading of the
     * `<vendor>-*` grammar. A PATTERN entry is covered only by a pattern whose
     * own prefix is a prefix of it: `acme-*` narrows to `acme-forms-*`, and an
     * exact grant of `acme-forms` covers no pattern at all, because a pattern
     * reaches names that do not exist yet and an exact name never does.
     *
     * @param list<mixed>|array<mixed> $delegatorNames
     */
    private static function scopeEntryCovered(array $delegatorNames, string $entry): bool {
        if (!str_ends_with($entry, '-*')) {
            return self::scopeCoversName($delegatorNames, $entry);
        }
        $prefix = substr($entry, 0, -1);
        foreach ($delegatorNames as $granted) {
            if (!is_string($granted) || !str_ends_with($granted, '-*')) {
                continue;
            }
            if (str_starts_with($prefix, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * THE OUT-OF-BAND REVOCATION CHANNEL, read (§ v3.8, WP-4.9).
     *
     * WHAT THIS CLOSES. Before it, revocation was one `status` word per key in
     * a file that ships inside the agent archive, so revocation latency was
     * agent-release latency — and on the FROZEN path it was worse than that:
     * verifyCertificate() re-binds a site-rooted certificate to the authority
     * record its own SIGNATURE covers, because frozen verification reopens no
     * mutable site file, so nothing an operator could write to
     * `adapters/authorities.json` ever reached an already-frozen snapshot. That
     * was reasoned as correct while a site root held only the operator's OWN
     * key, revoked by the operator, in a document the same operator produced —
     * a premise federation-by-copy falsifies the moment a delegated VENDOR key
     * sits in that root.
     *
     * WHAT IS PRESERVED, deliberately and asserted: the operator-own-key
     * asymmetry itself. Flipping `status` in `adapters/authorities.json` still
     * stops every LIVE scan and still does not reach a frozen snapshot. This
     * channel does not change that rule; it adds a second, platform-signed one
     * that does reach it, and every refusal it raises SAYS SO — an operator who
     * sees one must be able to tell which of the two mechanisms answered.
     *
     * @param array<string,mixed> $record the authority record being judged
     */
    private static function assertNotRevoked(string $manifestDir, string $keyId, array $record): void {
        $channel = self::revocations($manifestDir);
        $entry = $channel['entries'][hash('sha256', self::publicKey($record))] ?? null;
        if ($entry === null) {
            return;
        }
        $now = self::now();
        // IMPLAUSIBLE CLOCK FIRST, exactly as assertAuthorityWindow() orders it
        // and for the identical reason (G2-FIXES C1). The scheduled-revocation
        // arm below GRANTS its subject while `now < effective_at`, so a host
        // reading before the document was even issued would find every already-
        // effective revocation "scheduled" and hand the key back — the channel
        // failing OPEN, which is the one direction a revocation channel may
        // never fail. `issued_at` was parsed and discarded before this; it is
        // kept now precisely to be the anchor this test needs.
        //
        // Judged only once an entry NAMES this key, so a backwards clock
        // withdraws exactly the adapters the document is about instead of every
        // certified adapter on a host whose clock is wrong.
        //
        // RESIDUAL, recorded in R-14/R-26 rather than argued away: a clock set
        // INSIDE a lapsed window still resurrects what that window retired.
        // Both tests read `self::now()`, and no wall clock can witness its own
        // wrongness; closing it needs a monotonic anchor this product does not
        // have (a signed time beacon, or state the agent refuses to move
        // backwards).
        if ($channel['issued'] !== null && $now < $channel['issued']) {
            throw new WithdrawnAuthoritySiteAdapterCertificate(
                "duo: this host's own wall clock reads " . self::stamp($now)
                . ', before the platform-signed revocation record at ' . self::REVOCATIONS_RELATIVE
                . ' was issued at ' . self::stamp($channel['issued'])
                . " — an implausible clock refuses rather than resurrecting the revoked key '$keyId'",
                AdapterSources::WITHDRAWN_AUTHORITY_REVOKED
            );
        }
        $effective = self::instant($entry['effective_at'], "authority revocation '{$entry['key_id']}'.effective_at");
        if ($now < $effective) {
            // A revocation with a future instant is a SCHEDULED one and grants
            // nothing until it arrives — judged through now(), the one named
            // clock every window in this file reads, so there is no second time
            // source to disagree with. Deliberately NOT restating that
            // function's expression here: `tools/wire-surface.php` gate 3 greps
            // the raw file for it, so a second literal copy in a comment would
            // silently satisfy the gate a mutation is supposed to break
            // (`tests/Tooling/WireSurfaceTest.php:213-225` is the mutation).
            return;
        }
        // TYPED since G2-FIXES C3, with the sentence byte-identical. This
        // channel is the one that reaches a FROZEN snapshot on purpose, so
        // leaving it untyped meant the act of revoking a key bricked every
        // promoted site holding a certificate under it — the remedy taking the
        // patient with the disease. The adapter loses its certified grants,
        // which is what the revocation was for; the site keeps working.
        throw new WithdrawnAuthoritySiteAdapterCertificate(
            "duo: authority key '$keyId' is revoked by the platform-signed revocation record at "
            . self::REVOCATIONS_RELATIVE . ' — effective ' . self::stamp($effective) . ', reason: '
            . (string) $entry['reason']
            . '. This channel reaches the frozen path, which a status flip in the operator\'s own '
            . self::SITE_AUTHORITIES_RELATIVE . ' deliberately does not',
            AdapterSources::WITHDRAWN_AUTHORITY_REVOKED
        );
    }

    /**
     * The installed revocation document's entries, indexed by key fingerprint.
     *
     * ABSENCE IS AN ANSWER and means exactly "nothing is revoked" — the shipped
     * state of every site, and the reason the flag day moves no byte. A file
     * that EXISTS and is not a readable, signed, well-formed document is a
     * REFUSAL, never an absence: laundering unreadable revocation bytes into
     * "no revocations" would make deleting a signature the cheapest way to
     * un-revoke a compromised key, which is the one failure this channel exists
     * to prevent. Same sentence authorityKeys() already states about the trust
     * root it sits beside.
     *
     * THE THIRD ANSWER IS `inert`, AND IT IS WHY THIS CHANNEL IS INSTALLABLE AT
     * ALL (G2-FIXES C2). The shipped platform root is `{"keys":{}}` and stays
     * that way through the flag day (§ v3.12), so on a STOCK agent there is no
     * key that could have signed a revocation — and the signer test below used
     * to be a hard \RuntimeException, which meant the first correctly-signed
     * revocation document an operator installed took the site down instead of
     * revoking anything. Nobody is enrolled yet, so that was the only outcome
     * the channel had.
     *
     * A signer this root does not carry is now a NAMED NON-FATAL STATE: the
     * document is REPORTED (`AdapterSources::survey()` raises a library-scoped
     * row for it, so `duo adapter doctor` and `wp duo adapter-survey` exit 1 and
     * print the sentence) and its entries DO NOT APPLY. That is the honest
     * posture — inert until enrollment — and it takes nothing away: this agent
     * cannot authenticate a document signed by a key it does not hold, so it
     * could never have honoured those entries either way. What it must never do
     * is read them, and it does not.
     *
     * TAMPERING IS STILL FATAL, and the two are distinguishable by construction:
     * an unreadable file, a malformed envelope or statement, a version this
     * agent does not implement, a bad entry, a REVOKED signer, and a signature
     * that does not verify UNDER A KEY THIS ROOT CARRIES all stay hard
     * refusals. Only "this agent holds no key by that id" is inert, because that
     * is the one condition under which no verdict about the bytes is available.
     *
     * @return array{entries:array<string,array<string,mixed>>, issued:?int, inert:?array{message:string, signer:string}}
     */
    private static function revocations(string $manifestDir): array {
        $file = rtrim($manifestDir, '/') . '/' . self::REVOCATIONS_RELATIVE;
        $label = 'adapter certification authority revocations';
        $absent = ['entries' => [], 'inert' => null, 'issued' => null];
        if (!file_exists($file) && !is_link($file)) {
            return $absent;
        }
        if (!is_file($file) || is_link($file)) {
            throw new \RuntimeException("duo: $label must be an ordinary regular file: $file");
        }
        self::assertSodium();
        [, $typed, $data] = self::readCanonicalObjectFile($file, $label);
        self::assertExactKeys($data, self::REVOCATIONS_ENVELOPE_KEYS, $label);
        if (($data['format'] ?? null) !== self::REVOCATIONS_FORMAT) {
            throw new \RuntimeException("duo: $label have an unsupported or malformed root");
        }
        $statement = $data['statement'] ?? null;
        if (!is_array($statement) || array_is_list($statement)
            || !isset($typed->statement) || !is_object($typed->statement)) {
            throw new \RuntimeException("duo: $label statement must be a JSON object");
        }
        self::assertExactKeys($statement, self::REVOCATION_STATEMENT_KEYS, "$label statement");
        if (($statement['version'] ?? null) !== 1) {
            throw new \RuntimeException(
                "duo: $label declare revocation version " . var_export($statement['version'] ?? null, true)
                . ', which this agent does not implement — a revocation version is refused by version, never'
                . ' read as a v1 revocation with unexpected members'
            );
        }
        if (($statement['format'] ?? null) !== self::REVOCATION_FORMAT) {
            throw new \RuntimeException(
                "duo: $label statement must declare format " . self::REVOCATION_FORMAT
                . ' inside its own signature; the envelope\'s format is outside every signature and proves nothing'
            );
        }
        // KEPT, not merely parsed (G2-FIXES C1): assertNotRevoked() needs a
        // stated issuance instant to recognise a backwards clock, exactly as
        // assertWindowShape()'s `not_before` is what makes the same test
        // expressible for an authority record.
        $issued = self::instant($statement['issued_at'] ?? null, "$label.issued_at");

        // THE SIGNER IS A PLATFORM KEY, AND ITS WINDOW IS NOT APPLIED. The
        // status check is here for the same reason assertAuthoritiesEnvelope()
        // makes it — a revoked key attests nothing — but expiry is deliberately
        // not, and the argument is stronger here than there: letting a signer's
        // lapsed window silently un-revoke a compromised key would make expiry a
        // way to RESURRECT the exact identities this document exists to burn.
        $platform = self::authorityKeys(
            rtrim($manifestDir, '/') . '/' . self::AUTHORITIES_RELATIVE,
            'adapter certification authorities',
            true
        );
        $signature = $data['signature'] ?? null;
        if (!is_array($signature) || array_is_list($signature)
            || !isset($typed->signature) || !is_object($typed->signature)) {
            throw new \RuntimeException("duo: $label signature must be a JSON object");
        }
        self::assertExactKeys($signature, self::AUTHORITIES_SIGNATURE_KEYS, "$label signature");
        $signer = $signature['key_id'] ?? null;
        if (!is_string($signer)) {
            throw new \RuntimeException(
                "duo: $label signature key_id must be a canonical string selector"
            );
        }
        if (!isset($platform[$signer])) {
            // INERT, not fatal (G2-FIXES C2): this agent holds no key by that
            // id, so it has no verdict about these bytes at all — neither
            // "authentic" nor "forged". Reading the entries would honour a
            // statement nobody this root reviews made; refusing the site takes
            // every command down over a document that grants nothing. The third
            // answer is the true one, and the sentence is preserved verbatim so
            // an operator reads the same words the hard refusal used to print.
            return [
                'entries' => [],
                'inert' => [
                    'message' => "duo: $label are signed by key " . var_export($signer, true)
                        . ', which is not installed in ' . self::AUTHORITIES_RELATIVE
                        . ' — revocation is a platform-rooted statement and a site key cannot make one.'
                        . ' The document is installed and its entries do NOT apply: this channel is inert until'
                        . ' a key that signs it is enrolled in the shipped trust root',
                    'signer' => $signer,
                ],
                'issued' => null,
            ];
        }
        if (($platform[$signer]['status'] ?? null) !== 'trusted') {
            throw new \RuntimeException("duo: $label were signed by revoked key '$signer'");
        }
        $encoded = $signature['value'] ?? null;
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($bytes === false || !is_string($encoded) || !self::isCanonicalBase64($encoded, $bytes)
            || strlen($bytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached(
                $bytes,
                self::revocationSignatureBytes($typed->statement),
                self::publicKey($platform[$signer])
            )) {
            throw new \RuntimeException(
                "duo: $label do not verify under key '$signer'; an unsigned or tampered revocation document is"
                . ' refused, never read as an absent one'
            );
        }

        $rows = $statement['revocations'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || $rows === []) {
            throw new \RuntimeException("duo: $label statement must carry a non-empty revocations list");
        }
        $out = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException("duo: $label entry $index must be a JSON object");
            }
            self::assertExactKeys($row, self::REVOCATION_ENTRY_KEYS, "$label entry $index");
            self::keyId(is_string($row['key_id'] ?? null) ? $row['key_id'] : '');
            if (!self::sha($row['fingerprint'] ?? null)) {
                throw new \RuntimeException(
                    "duo: $label entry $index must bind a sha256 key fingerprint — an id can be re-minted over"
                    . ' new key material and material cannot be re-minted under an old id'
                );
            }
            if (!is_string($row['reason'] ?? null) || trim((string) $row['reason']) === '') {
                throw new \RuntimeException(
                    "duo: $label entry $index must state a non-empty reason; a revocation nobody can explain is"
                    . ' one nobody will act on'
                );
            }
            self::instant($row['effective_at'] ?? null, "$label entry $index.effective_at");
            if (isset($out[$row['fingerprint']])) {
                throw new \RuntimeException(
                    "duo: $label revoke the same key fingerprint twice — two instants for one identity is a"
                    . ' disagreement, not a list'
                );
            }
            $out[(string) $row['fingerprint']] = $row;
        }

        return ['entries' => $out, 'inert' => null, 'issued' => $issued];
    }

    /**
     * The typed revocation channel's state, for the surfaces that REPORT it.
     *
     * `null` is the shipped answer and means the channel is doing its job:
     * either no document is installed (absence means "nothing is revoked") or
     * the installed one verifies under an enrolled key and its entries apply.
     * A row means the document is installed and INERT — see revocations() — and
     * the caller is expected to print it, because an operator who installed a
     * revocation document believes those keys are burnt and they are not.
     *
     * Throws exactly what a certificate verification would throw for the same
     * bytes: a malformed or tampered document is a refusal on both surfaces, and
     * a reporter that swallowed it would be the one place this channel could go
     * quiet. `AdapterSources::survey()` catches it into its own row so an
     * inventory still renders.
     *
     * @return ?array{message:string, signer:string}
     */
    public static function revocation_channel(string $manifestDir): ?array {
        return self::revocations($manifestDir)['inert'];
    }

    /** The exact bytes one revocation statement's signature covers. */
    private static function revocationSignatureBytes($statement): string {
        return self::SIGNATURE_DOMAIN_REVOCATION . Canon::encode($statement);
    }

    /**
     * Sign a delegation statement into an installable delegation object.
     *
     * The producer lives beside the grammar for the reason signAuthorities()
     * states: a signer in the host would be a second copy of these rules in a
     * different language of the same repository, drifting silently on the side
     * where nothing re-verifies. It signs the statement it is HANDED rather than
     * assembling one, because every member of that statement is checked by
     * verifyDelegation() against the live platform root anyway — a producer that
     * pre-approved its own input would be the check that looks like a check.
     *
     * @param string $statementRaw canonical delegation-statement bytes
     * @return string canonical `{signature, statement}` delegation object bytes
     */
    public static function signDelegation(string $statementRaw, string $keyId, string $secretKey): string {
        self::assertSodium();
        [, $typed, $statement] = self::parseCanonicalObject($statementRaw, 'adapter certification delegation statement');
        self::assertExactKeys($statement, self::DELEGATION_STATEMENT_KEYS, 'adapter certification delegation statement');
        if (($statement['format'] ?? null) !== self::DELEGATION_FORMAT || ($statement['version'] ?? null) !== 1) {
            throw new \RuntimeException(
                'duo: a delegation statement must declare format ' . self::DELEGATION_FORMAT . ' and version 1'
            );
        }
        $id = self::keyId($keyId);
        $delegator = $statement['delegator'] ?? null;
        if (!is_array($delegator) || ($delegator['key_id'] ?? null) !== $id) {
            throw new \RuntimeException(
                "duo: authority key '$id' is not the delegator the statement names"
            );
        }
        $secret = self::secretKey($secretKey);
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        if (($delegator['fingerprint'] ?? null) !== hash('sha256', $public)) {
            throw new \RuntimeException("duo: private key does not match the delegator fingerprint for '$id'");
        }

        return Canon::encode((object) [
            'signature' => (object) [
                'key_id' => $id,
                'value' => base64_encode(sodium_crypto_sign_detached(self::delegationSignatureBytes($typed), $secret)),
            ],
            'statement' => $typed,
        ]);
    }

    /**
     * Sign a revocation statement into the installable out-of-band document.
     *
     * Same producer posture as signDelegation()/signAuthorities(). The signer's
     * own record is NOT read here — this producer holds no manifest directory
     * by design, so a revocation can be minted on an air-gapped machine that
     * has the key and none of the fleet's files, which is the custody a
     * compromise response actually has.
     *
     * @param string $statementRaw canonical revocation-statement bytes
     * @return string canonical duo-adapter-authority-revocations/v1 bytes
     */
    public static function signRevocations(string $statementRaw, string $keyId, string $secretKey): string {
        self::assertSodium();
        [, $typed, $statement] = self::parseCanonicalObject($statementRaw, 'adapter certification revocation statement');
        self::assertExactKeys($statement, self::REVOCATION_STATEMENT_KEYS, 'adapter certification revocation statement');
        if (($statement['format'] ?? null) !== self::REVOCATION_FORMAT || ($statement['version'] ?? null) !== 1) {
            throw new \RuntimeException(
                'duo: a revocation statement must declare format ' . self::REVOCATION_FORMAT . ' and version 1'
            );
        }
        $id = self::keyId($keyId);
        $secret = self::secretKey($secretKey);

        return Canon::encode((object) [
            'format' => self::REVOCATIONS_FORMAT,
            'signature' => (object) [
                'key_id' => $id,
                'value' => base64_encode(sodium_crypto_sign_detached(self::revocationSignatureBytes($typed), $secret)),
            ],
            'statement' => $typed,
        ]);
    }

    /**
     * Validate the site's delegation document whole, selecting no key.
     *
     * The adapter scan calls this the moment the file EXISTS, for the reason
     * assert_site_authorities() states about the trust root beside it: a broken
     * delegation document is not one adapter's problem, and an operator who
     * installed one believes a vendor's adapters are certifiable here.
     */
    public static function assert_site_delegations(string $manifestDir, string $repo): void {
        $root = self::repoRoot($repo, 'site repository');
        $platform = self::authorityKeys(
            rtrim($manifestDir, '/') . '/' . self::AUTHORITIES_RELATIVE,
            'adapter certification authorities',
            true
        );
        $site = self::authorityKeys(
            $root . '/' . self::SITE_AUTHORITIES_RELATIVE,
            'site adapter certification authorities'
        );
        self::delegatedKeys($manifestDir, $root, $platform, $site);
    }

    /**
     * One authorities file's validated key map, or [] when the file is absent.
     *
     * Absence is a legitimate answer for BOTH roots — the shipped file may not
     * exist in a custom manifest directory, and a repository that certifies
     * nothing has no adapters/authorities.json — but a file that EXISTS and is
     * not an ordinary readable canonical document is a refusal, never an
     * absence. Laundering unreadable authority bytes into "no trust root" is
     * the exact failure mode the certificate-source rules already refuse.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function authorityKeys(string $file, string $label, bool $platformRoot = false): array {
        if (!file_exists($file) && !is_link($file)) {
            return [];
        }
        if (!is_file($file) || is_link($file)) {
            throw new \RuntimeException("duo: $label must be an ordinary regular file: $file");
        }
        [, $typed, $data] = self::readCanonicalObjectFile($file, $label);
        // The envelope key set is decided BEFORE the format is judged, and by
        // the format the document claims: `{format, keys}` at v1 (unchanged
        // bytes for every document in the field) and `{format, keys, signature}`
        // at v2, where the signature is not optional. A document claiming a
        // format this engine does not implement still lands on the v1 key set
        // and is then refused by the root check below with today's sentence.
        if (($data['format'] ?? null) !== self::AUTHORITIES_FORMAT_V2) {
            self::assertExactKeys($data, self::AUTHORITIES_ENVELOPE_KEYS, $label);
        } else {
            self::assertExactKeys($data, self::AUTHORITIES_ENVELOPE_V2_KEYS, $label);
        }
        if (!in_array($data['format'] ?? null, [self::AUTHORITIES_FORMAT, self::AUTHORITIES_FORMAT_V2], true)
            || !is_array($data['keys'] ?? null)
            || !isset($typed->keys) || !is_object($typed->keys)) {
            throw new \RuntimeException("duo: $label have an unsupported or malformed root");
        }
        // An EMPTY v2 registry is unrepresentable, and that is what lets the
        // shipped `manifests/capabilities/adapter-authorities.json` stay the
        // byte-identical `{"format": "duo-adapter-authorities/v1", "keys": {}}`
        // through the flag day: the envelope signature names a key INSIDE the
        // document, so a registry with no keys has nothing that could sign it,
        // and a signature over an empty key set proves nothing about any key.
        // An empty registry stays v1; v2 is the enrolled format.
        if (($data['format'] ?? null) === self::AUTHORITIES_FORMAT_V2 && $data['keys'] === []) {
            throw new \RuntimeException(
                "duo: $label carry no keys, so the " . self::AUTHORITIES_FORMAT_V2
                . ' envelope signature has nothing to sign with; an empty registry stays '
                . self::AUTHORITIES_FORMAT
            );
        }
        $keys = [];
        foreach ($data['keys'] as $keyId => $record) {
            if (!is_string($keyId)) {
                throw new \RuntimeException(
                    'duo: adapter certification authority key map contains non-string key ' . var_export($keyId, true)
                    . ' — numeric-only identities are forbidden because PHP coerces JSON object-map keys to integers'
                );
            }
            self::keyId($keyId);
            if (!is_array($record) || array_is_list($record)) {
                throw new \RuntimeException("duo: adapter certification key '$keyId' must be an object");
            }
            if (!isset($typed->keys->{$keyId}) || !is_object($typed->keys->{$keyId})) {
                throw new \RuntimeException("duo: adapter certification key '$keyId' must be a JSON object");
            }
            self::validateAuthorityRecord($record, "adapter certification key '$keyId'", $keyId, $platformRoot);
            // The document's format and each record's own `record_version` are
            // two statements of one fact, and a disagreement is refused rather
            // than resolved: a v1 envelope holding a windowed record would put
            // an unenforced expiry in front of a reader who thinks it is
            // enforced, and a v2 envelope holding a v1 record would sign over a
            // grant nothing narrowed.
            $declaredV2 = ($record['record_version'] ?? null) !== null;
            if ($declaredV2 !== (($data['format'] ?? null) === self::AUTHORITIES_FORMAT_V2)) {
                throw new \RuntimeException(
                    "duo: adapter certification key '$keyId' declares record_version "
                    . ($declaredV2 ? '2' : 'nothing') . " inside a $label document declaring format "
                    . (string) ($data['format'] ?? '') . ' — the envelope and the record must state one grammar'
                );
            }
            $keys[$keyId] = $record;
        }
        if (($data['format'] ?? null) === self::AUTHORITIES_FORMAT_V2) {
            self::assertAuthoritiesEnvelope($data, $typed, $keys, $label);
        }
        return $keys;
    }

    /**
     * The shipped-wins rule, asked without a repository.
     *
     * The frozen path re-binds a site-rooted certificate to its own signed
     * authority record, so this is what stops that record from claiming a key
     * id the agent library reviews: the shipped file is agent-owned and
     * readable there, and a certificate naming one of its ids is not a site
     * certificate at all.
     */
    private static function assertKeyIdNotPlatformOwned(string $manifestDir, string $id): void {
        $platform = self::authorityKeys(
            rtrim($manifestDir, '/') . '/' . self::AUTHORITIES_RELATIVE,
            'adapter certification authorities',
            true
        );
        if (isset($platform[$id])) {
            throw new \RuntimeException(
                "duo: authority key '$id' is reviewed and shipped by this agent, so a site trust root cannot claim it"
            );
        }
    }

    /**
     * One record grammar with two closed key sets, selected by the record.
     *
     * $keyId is the map key (or, on the frozen path, the `key_id` inside the
     * signed statement) the record answers for. v1 does not read it — its
     * grammar is the shared identity slug and nothing more — and v2 binds it to
     * the record's own key material (§ v3.7 change (a)).
     *
     * $platformRoot is true ONLY for the agent's own reviewed
     * `capabilities/adapter-authorities.json`. It gates one rule — the shipped
     * library's name namespace, below — and nothing else, so every other member
     * is judged identically wherever the record came from.
     */
    private static function validateAuthorityRecord(
        array $record,
        string $label,
        string $keyId,
        bool $platformRoot = false
    ): void {
        $version = $record['record_version'] ?? null;
        if ($version === null) {
            self::assertExactKeys($record, self::AUTHORITY_RECORD_KEYS, $label);
        } else {
            self::assertExactKeys($record, self::AUTHORITY_RECORD_V2_KEYS, $label);
            if ($version !== 2) {
                throw new \RuntimeException(
                    "duo: $label declares authority record_version " . var_export($version, true)
                    . ', which this agent does not implement — a record version is refused by version, never'
                    . ' read as a v2 record with unexpected members'
                );
            }
        }
        if (($record['algorithm'] ?? null) !== 'ed25519'
            || ($record['scope'] ?? null) !== 'site_adapter_certification') {
            throw new \RuntimeException("duo: $label must declare algorithm ed25519 and scope site_adapter_certification");
        }
        if (!in_array($record['status'] ?? null, ['trusted', 'revoked'], true)) {
            throw new \RuntimeException("duo: $label status must be trusted or revoked");
        }
        $names = self::stringList($record['adapter_names'] ?? null, "$label.adapter_names", false);
        foreach ($names as $name) {
            if ($version === null) {
                self::adapterName($name);
            } else {
                self::assertScopeEntry($name, "$label.adapter_names");
            }
            if (!$platformRoot) {
                self::assertScopeEntryLeavesShippedNames($name, $label);
            }
        }
        $tiers = self::stringList($record['trust_tiers'] ?? null, "$label.trust_tiers", false);
        foreach ($tiers as $tier) {
            // `compatibility_shim` is admitted because an OVERRIDE of a shipped
            // adapter inherits the shipped interpreter/provider grants and so
            // carries the shipped tier (T6 §3.3); the site key certifies the
            // override's declarations, never new code — the loader refuses a
            // site manifest that adds an executable declaration.
            if (!in_array($tier, [
                AdapterSources::TIER_DECLARATIVE,
                AdapterSources::TIER_NATIVE_ACTION,
                AdapterSources::TIER_PLUGIN_PROVIDER,
                AdapterSources::TIER_COMPATIBILITY_SHIM,
            ], true)) {
                throw new \RuntimeException("duo: $label names unsupported site adapter trust tier '$tier'");
            }
        }
        $public = self::publicKey($record);
        if ($version !== null) {
            self::assertKeyIdBindsKeyMaterial($keyId, $public, $label);
            self::assertWindowShape($record, $label);
        }
    }

    /**
     * v2 change (a): the key id must be derived from its own key material.
     *
     * `keyId()` is nothing but the shared identity slug grammar (:1214), so at
     * v1 an operator may name a key anything legal — `wordpress-security-team`
     * over a key nobody at WordPress holds. The host side already DERIVES
     * `site-<first 12 hex of sha256(public key)>` as the default an operator
     * may override (`cli/src/Adapter/AdapterCertify.php:1241`); v2 makes that
     * derivation the RULE, so a squatted or misleading id is unrepresentable
     * rather than merely discouraged. The label half stays free — the id is
     * `<anything the slug grammar allows>-<fingerprint>` — because the
     * fingerprint is what has to be honest, not the noun in front of it.
     *
     * Existing certificates are untouched by construction: `key_id` is INSIDE
     * the signed statement, so a v1-rooted certificate keeps verifying under
     * whatever id it was signed with, and this rule only ever reads a record
     * that declared `record_version: 2`.
     */
    private static function assertKeyIdBindsKeyMaterial(string $keyId, string $public, string $label): void {
        $id = self::keyId($keyId);
        $fingerprint = substr(hash('sha256', $public), 0, self::AUTHORITY_KEY_FINGERPRINT_LENGTH);
        if (!str_ends_with($id, '-' . $fingerprint)) {
            throw new \RuntimeException(
                "duo: $label is named '$id', which does not derive from its own key material — a "
                . self::AUTHORITIES_FORMAT_V2 . " key id must end in '-$fingerprint', the first "
                . self::AUTHORITY_KEY_FINGERPRINT_LENGTH . ' hex characters of sha256(public_key)'
            );
        }
    }

    /**
     * v2 change (c): an `adapter_names` entry is an exact name or a namespace.
     *
     * `<vendor>-*` and nothing else. A bare `*`, an interior wildcard and a
     * bare-suffix `*-forms` are all refused, and the reason is the one § v3.7
     * states: scope can never WIDEN through a pattern. A wildcard that binds no
     * vendor prefix is exactly the widening — it would grant an authority every
     * adapter name that exists and every one that ever will — so the pattern
     * grammar cannot express it at all rather than relying on review to catch
     * it. `acme-*` covers `acme-forms`; it does not cover `acme` itself (the
     * hyphen is inside the pattern) and it does not cover `acmex-forms`.
     */
    private static function assertScopeEntry(string $entry, string $label): void {
        if (!str_contains($entry, '*')) {
            self::adapterName($entry);
            return;
        }
        if (!str_ends_with($entry, '-*') || substr_count($entry, '*') !== 1) {
            throw new \RuntimeException(
                "duo: $label entry '$entry' is neither an exact adapter name nor a '<vendor>-*' namespace"
            );
        }
        // The vendor half is held to the one shared identity grammar every
        // adapter name is held to, so a namespace can only ever name a prefix
        // an adapter name could actually have.
        self::adapterName(substr($entry, 0, -2));
    }

    /**
     * THE SHIPPED LIBRARY WINS THE ADAPTER-NAME NAMESPACE — against a PATTERN
     * (G2-FIXES M4). R-13's key-id half of this rule has always been enforced
     * (assertKeyIdNotPlatformOwned()); this is the name half, and it was
     * missing.
     *
     * THE ESCALATION IT CLOSES, exactly. 10 of the 16 grandfathered shipped
     * names sit inside a legal `<vendor>-*` namespace: `ninja-forms` is inside
     * `ninja-*`, `yoast-duplicate-post` inside `yoast-*`, `duo-agency-cpt`
     * inside `duo-*`, and so on. So enrolling one vendor with the namespace its
     * own products live in — the whole point of § v3.8's federation — silently
     * handed that vendor the SHIPPED adapter of the same name. It could then
     * certify `adapters/ninja-forms.json`, which as a reviewed override
     * INHERITS the shipped adapter's interpreter, regenerator and provider
     * grants byte for byte (`AdapterSources::assert_out_of_tree_contract()`,
     * `:3694-3697`) — executable privilege reached through a name nobody
     * intended to grant. The window to fix it closes the moment the first vendor
     * key is issued, because narrowing a namespace afterwards orphans whatever
     * was certified under it.
     *
     * EXACT NAMES ARE STILL LEGAL, and that is a decision rather than a gap.
     * Out of tree, a shipped name is reachable only as the reviewed
     * `{name, source: "site"}` override of that same adapter (T6 §3.3, restated
     * in `IdentityNamespaces`' own header as "the case the closed list must not
     * break"), and `duo adapter certify` writes the operator's own key record by
     * EXACT name — `regress_adapter_certify.php:855-875` walks exactly that:
     * an operator overriding `woocommerce` under their own site key. Refusing
     * the exact form would delete a shipped capability to close a hole the
     * pattern form is the whole of. Naming a reserved adapter exactly is a
     * deliberate act by whoever writes the record; sweeping one up in a vendor
     * namespace is an accident of shape, and only the accident is refused.
     *
     * The platform root is exempt because it IS the reviewed library: a record
     * this project ships may say `acf-*` and mean it.
     */
    private static function assertScopeEntryLeavesShippedNames(string $entry, string $label): void {
        if (!str_ends_with($entry, '-*')) {
            return;
        }
        foreach (IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES as $shipped) {
            if (!self::scopeCoversName([$entry], $shipped)) {
                continue;
            }
            throw new \RuntimeException(
                "duo: $label entry '$entry' covers '$shipped', which is one of the "
                . count(IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES)
                . ' adapter names the shipped library reserves — a namespace grant may not reach a shipped'
                . ' adapter, whose out-of-tree override inherits that adapter\'s interpreter, regenerator and'
                . " provider declarations. Grant the names outside it, or name '$shipped' exactly and review"
                . ' what that override may do'
            );
        }
    }

    /** True when an authority's scope list covers this exact adapter name. */
    private static function scopeCoversName(array $names, string $name): bool {
        foreach ($names as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            if ($entry === $name) {
                return true;
            }
            if (str_ends_with($entry, '-*') && str_starts_with($name, substr($entry, 0, -1))) {
                return true;
            }
        }
        return false;
    }

    /**
     * v2 change (b), grammar half: the record states one validity window.
     *
     * Both ends are mandatory at v2 and both parse strictly, because the
     * implausible-clock refusal below is only expressible against a stated
     * issuance instant: without `not_before` a host whose clock reads 1999
     * would find every expired record "not yet expired", which is the
     * resurrection this rule exists to refuse.
     */
    private static function assertWindowShape(array $record, string $label): void {
        $before = self::instant($record['not_before'] ?? null, "$label.not_before");
        $after = self::instant($record['not_after'] ?? null, "$label.not_after");
        if ($before >= $after) {
            throw new \RuntimeException(
                "duo: $label declares not_before " . (string) $record['not_before'] . ' at or after not_after '
                . (string) $record['not_after'] . ' — a window that has never been open certifies nothing'
            );
        }
    }

    /** @param mixed $value @return int the UTC epoch second the instant names */
    private static function instant($value, string $label): int {
        $parsed = is_string($value)
            ? \DateTimeImmutable::createFromFormat(self::AUTHORITY_WINDOW_FORMAT, $value, new \DateTimeZone('UTC'))
            : false;
        if ($parsed === false || $parsed->format(self::AUTHORITY_WINDOW_FORMAT) !== $value) {
            throw new \RuntimeException(
                "duo: $label must be one unambiguous ISO-8601 UTC instant (YYYY-MM-DDTHH:MM:SSZ)"
            );
        }
        return $parsed->getTimestamp();
    }

    /**
     * THE NAMED CLOCK: the verifying host's own wall clock, and nothing else.
     *
     * The same expression `ContractAttestation::verify()` is judged against
     * (`cli/src/Contract/ContractAttestation.php:436`, register row R-14), so
     * the two roots that carry an expiry read one clock and there is no second
     * time source to disagree with. There is no skew allowance in either
     * direction: a wrong operator clock refuses rather than accepts, which is
     * the safe failure and is the posture holders already depend on.
     */
    private static function now(): int {
        $now = self::$testAuthorityClock === null ? null : (int) (self::$testAuthorityClock)();

        return $now ?? time();
    }

    private static function stamp(int $epoch): string {
        return gmdate(self::AUTHORITY_WINDOW_FORMAT, $epoch);
    }

    /**
     * v2 change (b), enforcement half: the window, judged at scope time.
     *
     * Here rather than in the record grammar because expiry is a fact about
     * what a key may DO, not about whether its bytes are well formed: a record
     * whose window has closed must still parse, still report, and still be
     * distinguishable from a malformed one. This is the same seat revocation
     * already occupies (assertAuthorityScope()), so an expired key refuses
     * everywhere a revoked key refuses — signing, live verification, and the
     * frozen path where the record inside the signature is the authority.
     *
     * v1 records return immediately: they carry no window, and none is
     * invented for them.
     */
    private static function assertAuthorityWindow(array $authority, string $keyId): void {
        if (($authority['record_version'] ?? null) === null) {
            return;
        }
        $now = self::now();
        $issued = self::instant($authority['not_before'] ?? null, "authority key '$keyId'.not_before");
        $expires = self::instant($authority['not_after'] ?? null, "authority key '$keyId'.not_after");
        // IMPLAUSIBLE CLOCK FIRST, and that order is the rule. A host reading
        // before the record's own issuance instant would find every expired
        // record inside its window, so testing expiry first would let a wrong
        // clock RESURRECT a record its issuer has already retired.
        if ($now < $issued) {
            // TYPED since G2-FIXES C3, with the sentence byte-identical. The
            // window is mandatory at record v2, so an untyped refusal here was
            // a DATED whole-source brick: every certificate under the key took
            // the site down at its own `not_after`, with no operator act in
            // between. The type routes it to the same uncertified destination
            // StalePlatform reaches; nothing about WHAT is refused moved.
            throw new WithdrawnAuthoritySiteAdapterCertificate(
                "duo: this host's own wall clock reads " . self::stamp($now) . ", before authority key '$keyId'"
                . ' was issued at ' . self::stamp($issued)
                . ' — an implausible clock refuses rather than resurrecting an expired record',
                AdapterSources::WITHDRAWN_AUTHORITY_WINDOW
            );
        }
        if ($now >= $expires) {
            throw new WithdrawnAuthoritySiteAdapterCertificate(
                "duo: authority key '$keyId' expired at " . self::stamp($expires) . ', judged against this'
                . " host's own wall clock, which reads " . self::stamp($now)
                . ' — there is no skew allowance in either direction',
                AdapterSources::WITHDRAWN_AUTHORITY_WINDOW
            );
        }
    }

    /**
     * v2 change (d): the document attests to itself, or it does not verify.
     *
     * WHAT THIS PROVES, EXACTLY. The signature names a key inside the document
     * and covers `{format, keys}` whole, so a signed registry cannot be
     * PARTIALLY edited: nobody without the signing key can append a key, widen
     * a scope list, move a window or flip a status in it. That is precisely the
     * property enrollment needs, because enrollment is the moment this file
     * starts growing under a hand other than a reviewer's.
     *
     * WHAT IT DOES NOT PROVE, stated so nobody reads more into it: it is not a
     * chain to an external root. A party who can rewrite the file wholesale can
     * self-sign their own registry — and every certificate that verified under
     * the old one then stops verifying, because the certificate binds the
     * signing key's IDENTITY inside its own signature (assertAuthorityBinding()).
     * Delegation to an off-document root is § v3.8's own signed statement type,
     * deliberately not an arm inside this one.
     *
     * The signer must be `trusted`; its WINDOW is deliberately not applied. A
     * lapsed window says a key may no longer certify adapters, and bricking
     * every other vendor's key in the file because one signer's window closed
     * is a blast radius § v3.7 exists to remove, not to add.
     *
     * @param array<string,array<string,mixed>> $authorityRecords the validated record map
     */
    private static function assertAuthoritiesEnvelope(
        array $data,
        object $typed,
        array $authorityRecords,
        string $label
    ): void {
        self::assertSodium();
        $signature = $data['signature'] ?? null;
        if (!is_array($signature) || array_is_list($signature)
            || !isset($typed->signature) || !is_object($typed->signature)) {
            throw new \RuntimeException("duo: $label envelope signature must be a JSON object");
        }
        self::assertExactKeys($signature, self::AUTHORITIES_SIGNATURE_KEYS, "$label envelope signature");
        $signer = $signature['key_id'] ?? null;
        if (!is_string($signer) || !isset($authorityRecords[$signer])) {
            throw new \RuntimeException(
                "duo: $label envelope signature names key " . var_export($signer, true)
                . ' — a v2 registry is signed by a key it carries itself'
            );
        }
        if (($authorityRecords[$signer]['status'] ?? null) !== 'trusted') {
            throw new \RuntimeException(
                "duo: $label envelope signature was made by revoked key '$signer'"
            );
        }
        $encoded = $signature['value'] ?? null;
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($bytes === false || !is_string($encoded) || !self::isCanonicalBase64($encoded, $bytes)
            || strlen($bytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached(
                $bytes,
                self::authoritiesSignatureBytes((string) $data['format'], $typed->keys),
                self::publicKey($authorityRecords[$signer])
            )) {
            throw new \RuntimeException(
                "duo: $label envelope signature does not verify under key '$signer'; an unsigned or tampered"
                . ' authorities document is refused, never read as an absent trust root'
            );
        }
    }

    /** The exact bytes a v2 authorities envelope signature covers. */
    private static function authoritiesSignatureBytes(string $format, object $keys): string {
        // Typed rather than the decoded array, for the reason currentPlatform()
        // states at its own site: an array projection re-encodes an empty
        // object as `[]` and would hash different bytes than the document has.
        return self::SIGNATURE_DOMAIN_AUTHORITIES . Canon::encode((object) ['format' => $format, 'keys' => $keys]);
    }

    /**
     * Sign a v2 authorities document, returning its canonical signed bytes.
     *
     * The producer lives beside the grammar for the reason sign_site() states
     * about the bundle: a signer in the host would be a second copy of this
     * file's rules in a different language of the same repository, drifting
     * silently on the side where nothing re-verifies. Every record is validated
     * before the private key is touched, so a registry that could not be READ
     * cannot be signed either.
     *
     * @param string $documentRaw canonical `{format, keys}` bytes, signed or not
     * @return string canonical duo-adapter-authorities/v2 bytes
     */
    public static function signAuthorities(string $documentRaw, string $keyId, string $secretKey): string {
        self::assertSodium();
        [, $typed, $data] = self::parseCanonicalObject($documentRaw, 'adapter certification authorities');
        if (($data['format'] ?? null) !== self::AUTHORITIES_FORMAT_V2) {
            throw new \RuntimeException(
                'duo: only a ' . self::AUTHORITIES_FORMAT_V2 . ' authorities document carries an envelope'
                . ' signature; ' . self::AUTHORITIES_FORMAT . ' documents have no signed envelope at all'
            );
        }
        if (!is_array($data['keys'] ?? null) || $data['keys'] === []
            || !isset($typed->keys) || !is_object($typed->keys)) {
            throw new \RuntimeException(
                'duo: a ' . self::AUTHORITIES_FORMAT_V2 . ' authorities document must carry at least the key'
                . ' that signs it'
            );
        }
        $id = self::keyId($keyId);
        foreach ($data['keys'] as $mapKey => $record) {
            if (!is_string($mapKey) || !is_array($record) || array_is_list($record)) {
                throw new \RuntimeException('duo: adapter certification authority key map is malformed');
            }
            self::validateAuthorityRecord($record, "adapter certification key '$mapKey'", $mapKey);
        }
        if (!isset($data['keys'][$id])) {
            throw new \RuntimeException(
                "duo: authority key '$id' is not carried by the document it would sign"
            );
        }
        $secret = self::secretKey($secretKey);
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        if (!hash_equals(self::publicKey($data['keys'][$id]), $public)) {
            throw new \RuntimeException("duo: private key does not match authority key '$id'");
        }
        $signature = sodium_crypto_sign_detached(
            self::authoritiesSignatureBytes(self::AUTHORITIES_FORMAT_V2, $typed->keys),
            $secret
        );

        return Canon::encode((object) [
            'format' => self::AUTHORITIES_FORMAT_V2,
            'keys' => $typed->keys,
            'signature' => (object) ['key_id' => $id, 'value' => base64_encode($signature)],
        ]);
    }

    private static function publicKey(array $authority): string {
        $encoded = $authority['public_key'] ?? null;
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($key === false || !is_string($encoded) || !self::isCanonicalBase64($encoded, $key)
            || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException('duo: authority public_key must be a base64 Ed25519 public key');
        }
        return $key;
    }

    private static function secretKey(string $secret): string {
        $trimmed = trim($secret);
        if (preg_match('/^[0-9a-f]{128}$/Di', $trimmed) === 1) {
            $decoded = hex2bin($trimmed);
        } else {
            $decoded = base64_decode($trimmed, true);
        }
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('duo: private key must be a base64 or hexadecimal Ed25519 secret key');
        }
        return $decoded;
    }

    private static function assertAuthorityScope(array $authority, string $keyId, string $name, string $tier): void {
        if (($authority['status'] ?? null) !== 'trusted') {
            throw new \RuntimeException("duo: authority key '$keyId' is revoked and cannot certify adapters");
        }
        // Directly after revocation, because expiry is revocation with a date
        // on it and the two must refuse in the same seat: signing, live
        // verification and the frozen path all pass through here. A v1 record
        // returns from this immediately (§ v3.7 change (b)).
        self::assertAuthorityWindow($authority, $keyId);
        if (!self::scopeCoversName($authority['adapter_names'], $name)) {
            throw new \RuntimeException("duo: authority key '$keyId' is not scoped to site adapter '$name'");
        }
        if (!in_array($tier, $authority['trust_tiers'], true)) {
            throw new \RuntimeException(
                "duo: authority key '$keyId' is not scoped to derived trust tier '$tier'"
            );
        }
    }

    private static function assertAuthorityBinding(
        array $authority,
        string $keyId,
        string $trustRoot,
        array $statementAuthority,
        string $name,
        string $tier
    ): void {
        $embeddedAuthorityRecord = $statementAuthority['record'] ?? null;
        $recordDigest = $statementAuthority['record_sha256'] ?? null;
        // BOTH TRUST ROOTS BIND THE KEY IDENTITY — everything but the scope
        // lists (§ v3.7 change (e), WP-4.8). The site root has always done so,
        // because it is a LIVING registry: `duo adapter certify` appends every
        // newly certified name and tier to the key's record, so binding the
        // whole record invalidated every earlier certificate under that key the
        // moment a second adapter was certified (seen: certifying an override
        // made the certified wpforms adapter `certificate_invalid`).
        //
        // The platform branch used to bind the whole record, justified by a
        // premise ENROLLMENT FALSIFIES: that the shipped file never grows under
        // an operator's hand. It grows once per enrolled vendor, and each
        // growth would silently re-sign nothing while invalidating every
        // certificate already issued under that key — a cost that would only
        // surface after external authors existed, which is why this rides the
        // flag day rather than the first enrollment. Per register row R-08 a
        // root chooses one binding at the moment its first certificate is
        // signed and never after; this change is possible only because the
        // platform root has never signed one (`manifests/capabilities/
        // adapter-authorities.json` is `{"keys":{}}`).
        //
        // Nothing is laundered by the narrowing. The certificate still binds
        // the record it was signed over, self-consistently by digest; the scope
        // lists (adapter_names, trust_tiers) are enforced LIVE against the
        // CURRENT record by assertAuthorityScope() below, and revocation and
        // expiry with them. An identity edit — algorithm, public key, scope,
        // status, record_version, window — still refuses, because
        // authorityIdentity() drops only the two scope lists.
        $bound = is_array($embeddedAuthorityRecord)
            && self::sha($recordDigest)
            && hash_equals((string) $recordDigest, self::canonicalHash($embeddedAuthorityRecord))
            && hash_equals(
                Canon::encode(self::authorityIdentity($authority)),
                Canon::encode(self::authorityIdentity($embeddedAuthorityRecord))
            );
        if (($statementAuthority['key_id'] ?? null) !== $keyId
            || ($statementAuthority['trust_root'] ?? null) !== $trustRoot
            || ($statementAuthority['fingerprint'] ?? null) !== hash('sha256', self::publicKey($authority))
            || !$bound) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification authority/key/fingerprint/trust root does not match the "
                . "current $trustRoot authority record"
            );
        }
        self::assertAuthorityScope($authority, $keyId, $name, $tier);
    }

    /**
     * The key-identity half of an authority record: everything but the scope
     * lists AND the validity window.
     *
     * THE WINDOW LEFT THIS SET IN G2-FIXES M1, and the argument is R-08's own,
     * applied to the member R-08 did not reach. A window is a SCOPE — the same
     * sentence assertAuthorityScope() already makes by judging expiry in the
     * seat revocation occupies — and every scope this binding drops is enforced
     * LIVE against the CURRENT record instead: assertAuthorityBinding() calls
     * assertAuthorityScope(), which calls assertAuthorityWindow(), on every
     * verification, live and frozen. So nothing stops being checked; what stops
     * is the record's window being part of the identity a certificate froze.
     *
     * WHY IT HAD TO CHANGE. `not_after` is mandatory at record v2, so with the
     * window inside the identity there was NO RENEWAL PATH: extending it
     * invalidated every certificate ever signed under that key (the binding
     * compares the embedded record with the current one), and not extending it
     * expired them. Every v2 key therefore had exactly one lifetime, ending in a
     * fleet-wide re-signing event nobody could stage.
     *
     * WHY IT IS DECIDABLE NOW AND NEVER AGAIN. Per R-08 a root chooses one
     * binding at the moment its first certificate is signed and never after: a
     * NARROWING (this change) admits certificates a wider binding refused, so it
     * can only be made while nothing in the field depends on the refusal. The
     * platform root has still signed nothing —
     * `manifests/capabilities/adapter-authorities.json` is `{"keys": {}}`, which
     * `regress_authority_record_v2.php` asserts on every run beside this change
     * — and a SITE root's certificates only ever gain by it, because dropping a
     * member from an equality can turn a refusal into an acceptance and never
     * the reverse. Going back — putting the window into the identity — would
     * invalidate every certificate whose key was ever renewed, silently.
     */
    private static function authorityIdentity(array $record): array {
        unset($record['adapter_names'], $record['trust_tiers'], $record['not_after'], $record['not_before']);
        ksort($record, SORT_STRING);

        return $record;
    }

    /** @return array{0:object,1:string,2:array<string,mixed>} */
    private static function currentPlatform(string $manifestDir): array {
        $file = rtrim($manifestDir, '/') . '/' . self::PLATFORM_RELATIVE;
        if (!is_file($file)) {
            throw new \RuntimeException(
                'duo: current agent platform boundary is absent at capabilities/platform.json'
            );
        }
        // Read here rather than through ManifestDispositions::platform_boundary()
        // because this path needs BOTH shapes: the typed object is what
        // `ContractAttestation::currentPlatformDigest()` hashes for the
        // attestation's own whole-boundary `platform_sha256`, and the decoded
        // array is what the axis binding (§ v3.6) and the projected claim read
        // cell by cell. Both readers bind the same file and the same format
        // constant; only the shape they hand back differs.
        [, $typed, $data] = self::readCanonicalObjectFile($file, 'agent platform boundary');
        if (($data['format'] ?? null) !== ManifestDispositions::PLATFORM_FORMAT
            || !isset($typed->platform) || !is_object($typed->platform)
            || !is_array($data['platform'] ?? null) || array_is_list($data['platform'])) {
            throw new \RuntimeException('duo: agent platform boundary document has no valid platform object');
        }
        $platform = $data['platform'];
        self::assertExactKeys($platform, [
            'agent_version', 'branchable_state', 'compatibility', 'plugin_execution', 'site_mode', 'spec_version',
        ], 'agent capability platform boundary');
        if (($platform['agent_version'] ?? null) !== self::agentVersion()
            || ($platform['spec_version'] ?? null) !== self::specVersion()
            || ($platform['site_mode'] ?? null) !== 'single-site'
            || !is_array($platform['compatibility'] ?? null) || array_is_list($platform['compatibility'])) {
            throw new \RuntimeException('duo: agent capability platform boundary disagrees with the loaded agent');
        }
        return [$typed->platform, self::canonicalHash($typed->platform), $platform];
    }

    /**
     * The cells one compatibility axis contributes to a certificate, and the
     * value each of them binds.
     *
     * Which member decides the shape is documented on PLATFORM_AXIS_SERIES
     * above; this is that rule executed. An axis carrying BOTH series members
     * stops the signer by name rather than picking one, for the reason
     * topLevelKeyPartition() stops it on an unrecognised manifest section: a
     * silently chosen binding would cover less than the boundary declares, and
     * the uncovered half would simply stop being checked with nothing saying
     * so.
     *
     * @param array<string,mixed> $axis
     * @return array<string,mixed> cell name => the value that cell binds
     */
    private static function platformAxisCells(string $name, array $axis): array {
        $series = $axis[self::PLATFORM_AXIS_SERIES] ?? null;
        $engines = $axis[self::PLATFORM_AXIS_ENGINES] ?? null;
        $hasSeries = is_array($series) && !array_is_list($series);
        $hasEngines = is_array($engines) && !array_is_list($engines);
        if ($hasSeries && $hasEngines) {
            throw new \RuntimeException(
                "duo: agent platform boundary axis '$name' declares both `" . self::PLATFORM_AXIS_SERIES
                . '` and `' . self::PLATFORM_AXIS_ENGINES . '`, so what a certificate binds on it is ambiguous'
            );
        }
        $cells = [];
        if ($hasSeries) {
            // Bound by NAME: the series is the acceptance unit, the patch is
            // the witness. `null` states that in the signed bytes rather than
            // leaving a reader to infer it from an absent value.
            foreach (array_keys($series) as $cell) {
                $cells[(string) $cell] = null;
            }
        } elseif ($hasEngines) {
            foreach ($engines as $cell => $terms) {
                $cells[(string) $cell] = $terms;
            }
        } else {
            $profile = $axis['profile'] ?? null;
            unset($axis[self::PLATFORM_AXIS_NOTE]);
            ksort($axis, SORT_STRING);
            // `*` for an axis that names no profile: the fixture boundaries in
            // the offline corpus carry such an axis, and refusing them here
            // would make this rule a statement about the shipped file rather
            // than about the shape.
            $cells[is_string($profile) && $profile !== '' ? $profile : '*'] = $axis;
        }
        ksort($cells, SORT_STRING);

        return $cells;
    }

    /**
     * The v2 `statement.platform` member: what this certificate covers.
     *
     * The three narrowable compatibility axes are read out of
     * ManifestDispositions::narrowed_environment() rather than out of the
     * boundary directly, so a § v3.5 declaration narrows the BINDING and the
     * CLAIM through one function — and a declaration wider than the reviewed
     * boundary refuses here, before a signature byte exists. `filesystem` and
     * `process` are absent from that projection by design (no claim states
     * them, § v3.5), so they fall through to the boundary's own axis and a
     * certificate binds them whole.
     *
     * WHAT THE BOUNDARY CARRIES THAT THIS MEMBER DOES NOT, and why each one is
     * a decision rather than an omission:
     *
     *   - `agent_version` is recorded and not bound (see
     *     STATEMENT_PLATFORM_KEYS) — the whole point of the rider;
     *   - `site_mode` is bound, because it is one of the four cells § v3.5 lets
     *     an adapter narrow and one of the four a claim states. Read from the
     *     boundary rather than from the narrowing, which can only ever RESTATE
     *     a one-value axis: `narrowed_environment()` refuses any other value as
     *     a widening, so the two are the same string or the mint already failed;
     *   - `branchable_state` and `plugin_execution` are outside the binding
     *     entirely. They are prose about the AGENT's posture, not runtime cells
     *     anything was exercised against, and a claim restates them from the
     *     boundary installed now (`claim_from_disposition()` copies the live
     *     record into `platform`), so no certificate can make a claim assert a
     *     posture the agent no longer holds.
     *
     * @param array<string,mixed> $platformBoundary the current boundary record
     * @param array<string,mixed> $manifest the adapter being certified
     * @return array<string,mixed>
     */
    private static function platformStatement(array $platformBoundary, array $manifest): array {
        $narrowed = ManifestDispositions::narrowed_environment($manifest, $platformBoundary);
        $compatibility = is_array($platformBoundary['compatibility'] ?? null)
            ? $platformBoundary['compatibility']
            : [];
        $axes = [];
        foreach ($compatibility as $name => $axis) {
            $name = (string) $name;
            if (!is_array($axis) || array_is_list($axis)) {
                throw new \RuntimeException(
                    "duo: agent platform boundary compatibility axis '$name' must be an object"
                );
            }
            $declared = is_array($narrowed[$name] ?? null) ? $narrowed[$name] : $axis;
            $cells = self::platformAxisCells($name, $declared);
            $axes[$name] = [
                'cells' => array_map('strval', array_keys($cells)),
                'sha256' => self::canonicalHash((object) $cells),
            ];
        }
        ksort($axes, SORT_STRING);
        if ($axes === []) {
            // Refused at MINT rather than left to assertPlatformShape(), which
            // would meet the same document as an empty `axes` object and have
            // to call it malformed. A boundary declaring no compatibility axis
            // binds nothing at all, so the certificate it produced would say
            // only "some agent published spec version N" — a certificate that
            // covers nothing is worse than no certificate, because it reads as
            // one.
            throw new \RuntimeException(
                'duo: agent platform boundary declares no compatibility axis, so a certificate signed against '
                . 'it would bind no exercised runtime cell at all'
            );
        }

        return [
            'agent_version' => (string) ($platformBoundary['agent_version'] ?? ''),
            'axes' => (object) $axes,
            'site_mode' => (string) ($platformBoundary['site_mode'] ?? ''),
            'spec_version' => $platformBoundary['spec_version'] ?? null,
        ];
    }

    /**
     * Re-bind a v2 statement's platform member to the boundary installed now.
     *
     * Reached only after the signature and the authority binding have both
     * verified, which is what makes every refusal below a
     * StalePlatformSiteAdapterCertificate rather than a forgery signal: this
     * line can only ever be about an AGENT-owned document that moved.
     *
     * Three questions, and no fourth:
     *
     *   1. `spec_version` equality — the grammar gate, unchanged from v1. A
     *      certificate never verifies across a grammar bump.
     *   2. `site_mode` equality. It is not a compatibility axis (it is a
     *      top-level member of the boundary) but it IS one of the four cells a
     *      claim states, so a certificate that did not bind it would project a
     *      claim naming a site mode nobody exercised.
     *   3. Every BOUND cell of every BOUND axis is still carried, and still
     *      binds the same value. A cell the current boundary has DROPPED
     *      refuses by name; a cell whose acceptance terms MOVED refuses by
     *      axis. An axis or a cell the boundary has GAINED refuses nothing —
     *      it is coverage this certificate never claimed, exactly as § v3.6
     *      states, and it is what stops an additive release from withdrawing
     *      claims across the fleet.
     *
     * @param array<string,mixed> $platformBoundary the current boundary record
     * @param array<string,mixed> $bound `statement.platform`, already shape-checked
     * @return string the digest of the axes map this certificate binds
     */
    private static function assertPlatformBinding(string $name, array $platformBoundary, array $bound): string {
        if (($bound['spec_version'] ?? null) !== ($platformBoundary['spec_version'] ?? null)) {
            throw new StalePlatformSiteAdapterCertificate(
                "duo: site adapter '$name' certification was signed under spec version "
                . var_export($bound['spec_version'] ?? null, true) . ', which is not the spec version '
                . var_export($platformBoundary['spec_version'] ?? null, true) . ' this agent publishes'
            );
        }
        if (($bound['site_mode'] ?? null) !== ($platformBoundary['site_mode'] ?? null)) {
            throw new StalePlatformSiteAdapterCertificate(
                "duo: site adapter '$name' certification binds site mode '"
                . (string) ($bound['site_mode'] ?? '') . "', which is not the site mode this agent publishes"
            );
        }
        $compatibility = is_array($platformBoundary['compatibility'] ?? null)
            ? $platformBoundary['compatibility']
            : [];
        foreach ((array) $bound['axes'] as $axis => $binding) {
            $axis = (string) $axis;
            $current = $compatibility[$axis] ?? null;
            if (!is_array($current) || array_is_list($current)) {
                throw new StalePlatformSiteAdapterCertificate(
                    "duo: site adapter '$name' certification binds compatibility axis '$axis', which the "
                    . 'agent-owned platform boundary no longer declares'
                );
            }
            $cells = self::platformAxisCells($axis, $current);
            $restricted = [];
            foreach ($binding['cells'] as $cell) {
                $cell = (string) $cell;
                if (!array_key_exists($cell, $cells)) {
                    throw new StalePlatformSiteAdapterCertificate(
                        "duo: site adapter '$name' certification was exercised against '$axis' cell '$cell', "
                        . 'which the agent-owned platform boundary no longer carries'
                    );
                }
                $restricted[$cell] = $cells[$cell];
            }
            ksort($restricted, SORT_STRING);
            if (!hash_equals((string) $binding['sha256'], self::canonicalHash((object) $restricted))) {
                throw new StalePlatformSiteAdapterCertificate(
                    "duo: site adapter '$name' certification binds compatibility axis '$axis', whose exercised "
                    . 'cells the agent-owned platform boundary now states differently'
                );
            }
        }

        return self::canonicalHash((object) $bound['axes']);
    }

    /**
     * The shape half of the v2 platform member, run before anything is bound.
     *
     * Separate from assertPlatformBinding() because the two answer different
     * questions to different audiences: a malformed member is a corrupt or
     * forged certificate (a hard \RuntimeException, the whole source refuses),
     * while a well-formed member that no longer matches is an agent-owned
     * document that moved (the typed withdrawal, one adapter degrades). Folding
     * them would make a hand-edited statement look like an ordinary upgrade.
     *
     * @param array<string,mixed> $bound
     */
    private static function assertPlatformShape(string $name, array $bound): void {
        self::assertExactKeys($bound, self::STATEMENT_PLATFORM_KEYS, "site adapter '$name' certification platform");
        if (!is_string($bound['agent_version']) || !is_string($bound['site_mode'])
            || !is_int($bound['spec_version'])
            || !is_array($bound['axes']) || array_is_list($bound['axes']) || $bound['axes'] === []) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification platform member is malformed"
            );
        }
        foreach ($bound['axes'] as $axis => $binding) {
            $label = "site adapter '$name' certification platform axis '" . (string) $axis . "'";
            if (!is_array($binding) || array_is_list($binding)) {
                throw new \RuntimeException("duo: $label must be an object");
            }
            self::assertExactKeys($binding, self::STATEMENT_AXIS_KEYS, $label);
            self::stringList($binding['cells'], $label . ' cells', false);
            if (!self::sha($binding['sha256'] ?? null)) {
                throw new \RuntimeException("duo: $label must carry a sha256 digest of its exercised cells");
            }
        }
    }

    private static function assertCertificateShape(object $typed, array $certificate): void {
        // Every UNCONDITIONAL shape proof runs first, and the wire-version test
        // last, because that test is the one signal here that no signature
        // authenticates (SupersededWireSiteAdapterCertificate states why it
        // cannot be: root `format` is outside signatureBytes(), `:1340-1342`).
        // Ordering is therefore the only thing that narrows it, and it buys
        // exactly this: a one-key `{"format":"duo-adapter-certification/v2"}`
        // file — the cheapest thing an attacker with write access to
        // adapters/certification/ can author — is refused as malformed here
        // instead of reaching the typed signal and silently degrading the
        // adapter. What reaches the signal now must at minimum carry the exact
        // three root keys and a canonical base64 Ed25519-length signature.
        //
        // The cost is forward compatibility this agent cannot specify anyway: a
        // real /v2 wire that adds a root key or a different signature primitive
        // gets 'unsupported or malformed root' rather than the version
        // sentence. Accepted — no /v2 wire exists (self::FORMAT is the only one
        // ever published), so the version predicate has no genuine traffic to
        // serve today, and shipping the wider door for a hypothetical future
        // reader is worth strictly less than closing it against a real
        // hand-edited file.
        self::assertExactKeys($certificate, ['format', 'signature', 'statement'], 'site adapter certification');
        if (!is_string($certificate['signature'] ?? null)
            || !isset($typed->statement) || !is_object($typed->statement)
            || !is_array($certificate['statement'] ?? null) || array_is_list($certificate['statement'])) {
            throw new \RuntimeException('duo: site adapter certification has an unsupported or malformed root');
        }
        $signature = base64_decode($certificate['signature'], true);
        if ($signature === false || !self::isCanonicalBase64($certificate['signature'], $signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new \RuntimeException('duo: site adapter certification signature is not a base64 Ed25519 signature');
        }
        // The predicate is the exact family at a different integer version and
        // nothing else — see SupersededWireSiteAdapterCertificate for why one
        // term wider would be a laundering path. The bytes reaching here are
        // already proved canonical JSON by readCanonicalObjectFile()/
        // parseCanonicalObject(), so an unparseable file never arrives at all.
        $format = $certificate['format'] ?? null;
        if (is_string($format) && $format !== self::FORMAT
            && preg_match('#^duo-adapter-certification/v[1-9][0-9]*$#D', $format) === 1) {
            throw new SupersededWireSiteAdapterCertificate(
                // The regex above has already proved $format is exactly
                // `duo-adapter-certification/v<digits>`, so it is safe to
                // print verbatim: there is nothing left in it to inject.
                "duo: site adapter certification is written in wire version '$format', which this agent does not "
                . "verify; it verifies '" . self::FORMAT . "'"
            );
        }
        // The same refusal the root-shape test above raises, deliberately
        // restated after the wire test rather than folded into it: the format
        // VALUE is the one root term the version signal is allowed to answer
        // for, so it is the one term that may not preempt it.
        if ($format !== self::FORMAT) {
            throw new \RuntimeException('duo: site adapter certification has an unsupported or malformed root');
        }
    }

    /**
     * The statement's shape AND its wire generation, in that order.
     *
     * WHICH GENERATION A CERTIFICATE IS, decided without a signature: the
     * member SET. A v2 statement carries `version`; a v1 statement is exactly
     * the five members R-06 closed. That is the only discriminator available —
     * a signature cannot be verified until the domain is chosen, and the domain
     * is what the generation names — so it must be a shape question, and
     * `version` exists to make the NEXT such question a version question
     * instead (§ v3.6, and § v3.7's `record_version` for the same reason).
     *
     * ORDERING, kept from WP-1.1's review hardening on assertCertificateShape():
     * every unconditional shape proof runs before any degrade signal, so the
     * cheapest hand-authored file — a statement carrying `version` and nothing
     * else — is refused as malformed rather than reaching the typed withdrawal
     * and quietly stripping an adapter's certification. By the time either
     * signal is raised the root key set, the canonical base64 Ed25519-length
     * signature (assertCertificateShape) and the five common members have all
     * been proved.
     */
    private static function assertStatementShape(string $name, object $typed, array $statement): void {
        // THE RESERVED MEMBERS, FIRST (§ v3.10, WP-4.11). Before the generation
        // discriminator rather than after it, because a reserved member answers
        // the operator's question and a generation verdict does not: a
        // statement carrying `code_digest` is not a v1 statement and is not an
        // unimplemented generation either — it is a document asking for a lane
        // that is shut, and telling its author "this agent verifies generation
        // 2" would send them to re-mint bytes that will be refused again.
        //
        // NO VERDICT MOVES. A statement carrying either member cannot be the
        // exact v1 five (that is six members, so the `$keys !== $v1` branch is
        // taken) and cannot be the exact v2 six either, so today it reaches
        // `assertExactKeys()` and is refused as malformed — a hard failure, not
        // the per-adapter withdrawal a generation mismatch takes. Both arms of
        // that are preserved: same exception class, same hard failure, same
        // certificate refused. Only the message becomes specific.
        foreach (self::STATEMENT_RESERVED_KEYS as $reserved => $why) {
            if (array_key_exists($reserved, $statement)) {
                throw new \RuntimeException(
                    "duo: site adapter certification statement declares '$reserved' — $why"
                );
            }
        }
        $keys = array_keys($statement);
        sort($keys, SORT_STRING);
        $v1 = self::STATEMENT_V1_KEYS;
        sort($v1, SORT_STRING);
        if ($keys !== $v1) {
            self::assertExactKeys($statement, self::STATEMENT_KEYS, 'site adapter certification statement');
        }
        foreach (self::STATEMENT_V1_KEYS as $key) {
            if (!isset($typed->$key) || !is_object($typed->$key)
                || !is_array($statement[$key] ?? null) || array_is_list($statement[$key])) {
                throw new \RuntimeException("duo: site adapter certification statement.$key must be an object");
            }
        }
        if ($keys === $v1) {
            // The previous generation, signed under the retired `/v1`
            // certification domain over a statement that bound the whole
            // platform document. Refused BY NAME and routed to uncertified
            // per-adapter — never a whole-source refusal, which would take the
            // site's unrelated adapters down with it. The remedy is
            // `duo adapter certify --pin`, which mints v2.
            throw new SupersededWireSiteAdapterCertificate(
                'duo: site adapter certification statement is written in wire generation 1, which this agent '
                . 'does not verify; it verifies generation ' . self::STATEMENT_VERSION
            );
        }
        $version = $statement['version'];
        if (!is_int($version) || $version < 1) {
            throw new \RuntimeException(
                'duo: site adapter certification statement.version must be a positive integer wire generation'
            );
        }
        if ($version !== self::STATEMENT_VERSION) {
            throw new SupersededWireSiteAdapterCertificate(
                "duo: site adapter certification statement is written in wire generation $version, which this "
                . 'agent does not verify; it verifies generation ' . self::STATEMENT_VERSION
            );
        }
        self::assertPlatformShape($name, $statement['platform']);
    }

    private static function assertAdapterBinding(
        string $name,
        array $manifest,
        array $adapter,
        string $derivedTier,
        ?array $rawAdapter
    ): void {
        self::assertExactKeys($adapter, [
            'canonical_sha256', 'name', 'path', 'raw_sha256', 'raw_size', 'source', 'trust_tier',
        ], 'site adapter certification adapter binding');
        $expectedPath = 'adapters/' . $name . '.json';
        // Structural/format anomalies FIRST: a malformed, misplaced, wrong-
        // source or wrong-tier companion, or a mis-shaped digest. These are
        // authority anomalies and stay hard failures. Deliberately excludes
        // the canonical/raw VALUE comparison so a plain content edit is not
        // conflated with a corrupt companion.
        if (($adapter['name'] ?? null) !== $name
            || ($adapter['path'] ?? null) !== $expectedPath
            || ($adapter['source'] ?? null) !== AdapterSources::SITE
            || ($adapter['trust_tier'] ?? null) !== $derivedTier
            || !self::sha($adapter['canonical_sha256'] ?? null)
            || !self::sha($adapter['raw_sha256'] ?? null)
            || !is_int($adapter['raw_size'] ?? null) || $adapter['raw_size'] < 1) {
            throw new \RuntimeException(
                "duo: site adapter '$name' certification does not bind the exact source/path/canonical manifest/trust tier"
            );
        }
        // Content supersession: the well-formed companion binds different
        // canonical (or raw) bytes than the adapter carries now — the adapter
        // was edited after certification. Signalled distinctly so scan() can
        // route it to uncertified support (see SupersededSiteAdapterCertificate).
        if (!hash_equals((string) $adapter['canonical_sha256'], self::canonicalHash($manifest))) {
            throw new SupersededSiteAdapterCertificate(
                "duo: site adapter '$name' certification binds a superseded manifest; the adapter changed since it was certified"
            );
        }
        if ($rawAdapter !== null
            && (!hash_equals((string) $adapter['raw_sha256'], hash('sha256', (string) $rawAdapter['raw']))
                || $adapter['raw_size'] !== (int) $rawAdapter['size'])) {
            throw new SupersededSiteAdapterCertificate(
                "duo: site adapter '$name' certification binds superseded raw bytes; the adapter changed since it was certified"
            );
        }
    }

    /** @return array{typed:object,array:array,ratification_typed:object,ratification:array,ratification_raw:string,info:array} */
    private static function verifyBundleForImport(
        string $bundleInput,
        string $evidenceRepo,
        string $name,
        array $manifest,
        string $adapterRaw,
        string $trustRoot
    ): array {
        [$bundleDir, $bundleFile] = self::bundleFile($bundleInput);
        [$bundleRaw, $bundleTyped, $bundle] = self::readBundleObjectFile($bundleFile, 'certification bundle manifest');
        unset($bundleRaw);
        $info = self::verifyBundleManifest($bundleTyped, $bundle, 'certification bundle manifest', $name, $trustRoot);
        self::assertBundleSubjectInput($info['bound_inputs'], $name, [
            'raw_sha256' => hash('sha256', $adapterRaw),
            'raw_size' => strlen($adapterRaw),
        ]);
        $evidenceRoot = self::repoRoot($evidenceRepo, 'certification evidence repository');
        [$ratificationRaw, $ratificationTyped, $ratification] = self::verifyBundleAssets(
            $bundleDir,
            $bundleTyped,
            $bundle,
            $info,
            $evidenceRoot
        );
        [, $disposition] = self::verifyRatification(
            $ratificationTyped,
            $ratification,
            $name,
            $manifest,
            $info,
            $ratificationRaw
        );
        unset($disposition);
        return [
            'typed' => $bundleTyped,
            'array' => $bundle,
            'ratification_typed' => $ratificationTyped,
            'ratification' => $ratification,
            'ratification_raw' => $ratificationRaw,
            'info' => $info,
        ];
    }

    /** @return array{0:string,1:string} */
    private static function bundleFile(string $input): array {
        $candidate = is_dir($input) ? rtrim($input, '/') . '/bundle.json' : $input;
        if (basename($candidate) !== 'bundle.json' || !is_file($candidate) || is_link($candidate)) {
            throw new \RuntimeException('duo: certification bundle input must be a regular bundle.json file or its directory');
        }
        $dir = realpath(dirname($candidate));
        $file = realpath($candidate);
        if ($dir === false || $file === false || !hash_equals($dir . '/bundle.json', $file)) {
            throw new \RuntimeException('duo: certification bundle must resolve to an exact bundle.json inside its directory');
        }
        return [$dir, $file];
    }

    /**
     * Structural bundle verification shared by imported and embedded bundles.
     * The importer additionally opens every descriptor; runtime validates the
     * signed descriptors and content-addressed bundle manifest without finding
     * an arbitrary external evidence directory.
     *
     * @return array{tests:array<string,bool>,assets:array<string,array>,bound_inputs:list<array>,ratification_asset:array,summary:array,bundle_digest:string,git_revision:string,exercised:bool,created_at:string}
     */
    private static function verifyBundleManifest(
        object $typed,
        array $bundle,
        string $label,
        string $name,
        string $trustRoot
    ): array {
        self::assertExactKeys($bundle, [
            'artifacts', 'bound_inputs', 'bundle_digest', 'created_at', 'environment', 'environment_summary',
            'evidence', 'force_hatches', 'git_revision', 'harness', 'ratification', 'ratification_summary',
            'schema_version', 'subject', 'tests', 'verdict',
        ], $label);
        foreach (['environment', 'environment_summary', 'evidence', 'harness', 'ratification', 'ratification_summary'] as $key) {
            if (!isset($typed->$key) || !is_object($typed->$key)
                || !is_array($bundle[$key] ?? null) || array_is_list($bundle[$key])) {
                throw new \RuntimeException("duo: $label.$key must be an object");
            }
        }
        foreach (['artifacts', 'bound_inputs', 'force_hatches', 'tests'] as $key) {
            if (!isset($typed->$key) || !is_array($typed->$key)
                || !is_array($bundle[$key] ?? null) || !array_is_list($bundle[$key])) {
                throw new \RuntimeException("duo: $label.$key must be a JSON list");
            }
        }
        if (!isset($typed->subject) || !is_object($typed->subject)
            || !is_array($bundle['subject'] ?? null) || array_is_list($bundle['subject'])) {
            throw new \RuntimeException("duo: $label.subject must be an object");
        }
        self::assertExactKeys($bundle['subject'], ['kind', 'name'], "$label.subject");
        if (($bundle['subject']['kind'] ?? null) !== 'site_adapter'
            || ($bundle['subject']['name'] ?? null) !== $name) {
            throw new \RuntimeException(
                "duo: $label must be scoped exactly to site_adapter.$name"
            );
        }
        if (($bundle['schema_version'] ?? null) !== self::BUNDLE_FORMAT
            || ($bundle['verdict'] ?? null) !== 'pass'
            || !self::sha($bundle['bundle_digest'] ?? null)
            || !hash_equals((string) $bundle['bundle_digest'], self::bundleDigest($bundle))
            || !is_string($bundle['created_at'] ?? null) || strtotime($bundle['created_at']) === false
            || !is_string($bundle['git_revision'] ?? null)
            || preg_match('/^[0-9a-f]{40}$/D', $bundle['git_revision']) !== 1
            || !is_string($bundle['harness']['name'] ?? null) || $bundle['harness']['name'] === ''
            || !is_int($bundle['harness']['version'] ?? null) || $bundle['harness']['version'] < 1) {
            throw new \RuntimeException("duo: $label is not an intact passing " . self::BUNDLE_FORMAT . ' manifest');
        }
        self::assertExactKeys($bundle['harness'], ['name', 'version'], "$label.harness");
        $exercised = self::bundleEvidence($bundle['evidence'], $label, $trustRoot);
        // An external certificate is not a vehicle for force-flag approval.
        // Binding a non-empty list would make it visible, but still turns an
        // override into a green site claim, which this source never permits.
        if ($bundle['force_hatches'] !== []) {
            throw new \RuntimeException('duo: a site adapter certification bundle may not use force hatches');
        }
        self::assertExactKeys($bundle['ratification_summary'], ['certified_claims', 'manifest_count', 'profile_count'], "$label.ratification_summary");
        if (!is_int($bundle['ratification_summary']['manifest_count'] ?? null)
            || !is_int($bundle['ratification_summary']['profile_count'] ?? null)) {
            throw new \RuntimeException("duo: $label.ratification_summary is malformed");
        }

        $assets = [];
        $environment = self::assetDescriptor($bundle['environment'], "$label.environment", 'environment.json');
        $ratification = self::assetDescriptor($bundle['ratification'], "$label.ratification", 'ratification.json');
        $assets[$environment['path']] = $environment;
        $assets[$ratification['path']] = $ratification;

        $bound = [];
        $boundSeen = [];
        foreach ($bundle['bound_inputs'] as $i => $input) {
            $descriptor = self::assetDescriptor($input, "$label.bound_inputs[$i]");
            if (isset($boundSeen[$descriptor['path']])) {
                throw new \RuntimeException("duo: $label has duplicate bound input '{$descriptor['path']}'");
            }
            $boundSeen[$descriptor['path']] = true;
            $bound[] = $descriptor;
        }
        if ($bound === []) {
            throw new \RuntimeException("duo: $label has no bound code/manifest/harness inputs");
        }

        foreach ($bundle['artifacts'] as $i => $artifact) {
            if (!is_array($artifact) || array_is_list($artifact)) {
                throw new \RuntimeException("duo: $label.artifacts[$i] must be an object");
            }
            self::assertExactKeys($artifact, ['name', 'role', 'sha256', 'url', 'version'], "$label.artifacts[$i]");
            if (!is_string($artifact['name'] ?? null) || $artifact['name'] === ''
                || !is_string($artifact['version'] ?? null) || $artifact['version'] === ''
                || !self::sha($artifact['sha256'] ?? null)
                || !filter_var($artifact['url'] ?? '', FILTER_VALIDATE_URL)
                || !in_array($artifact['role'] ?? null, ['certified-boundary', 'refusal-fixture'], true)) {
                throw new \RuntimeException("duo: $label.artifacts[$i] is malformed");
            }
        }

        $tests = [];
        foreach ($bundle['tests'] as $i => $test) {
            if (!is_array($test) || array_is_list($test)) {
                throw new \RuntimeException("duo: $label.tests[$i] must be an object");
            }
            self::assertExactKeys($test, ['diff', 'id', 'log', 'result', 'verdict'], "$label.tests[$i]");
            $id = self::testId($test['id'] ?? null, "$label.tests[$i].id");
            if (isset($tests[$id]) || ($test['verdict'] ?? null) !== 'pass') {
                throw new \RuntimeException("duo: $label must contain each named passing test exactly once");
            }
            $result = self::assetDescriptor($test['result'], "$label.tests[$i].result", "results/$id.json");
            $diff = self::assetDescriptor($test['diff'], "$label.tests[$i].diff", "diffs/$id.json");
            $log = self::assetDescriptor($test['log'], "$label.tests[$i].log", "logs/$id.txt");
            foreach ([$result, $diff, $log] as $asset) {
                if (isset($assets[$asset['path']])) {
                    throw new \RuntimeException("duo: $label reuses bundle asset path '{$asset['path']}'");
                }
                $assets[$asset['path']] = $asset;
            }
            $tests[$id] = true;
        }
        if ($exercised && $tests === []) {
            throw new \RuntimeException("duo: $label has no named tests");
        }
        if (!$exercised && ($tests !== [] || $bundle['artifacts'] !== [])) {
            // `exercised: false` is a claim about what was NOT done. A bundle
            // that also carried tests or artifacts would be asserting both
            // halves at once, and the projection an operator reads
            // (`exercised: false` beside a named artifact list) would be the
            // exact ambiguity this key exists to remove.
            throw new \RuntimeException(
                "duo: $label declares evidence.exercised false but names tests or artifacts"
            );
        }
        return [
            'tests' => $tests,
            'assets' => $assets,
            'bound_inputs' => $bound,
            'created_at' => (string) $bundle['created_at'],
            'exercised' => $exercised,
            'ratification_asset' => $ratification,
            'summary' => $bundle['ratification_summary'],
            'bundle_digest' => $bundle['bundle_digest'],
            'git_revision' => $bundle['git_revision'],
        ];
    }

    /**
     * What this bundle actually proves, stated by the bundle itself.
     *
     * Before T6 the grammar had exactly one admissible shape — a passing
     * exercise with named tests and result/diff/log assets — and every
     * certificate silently meant that. An operator certifying their OWN
     * adapter cannot produce it: there is no reviewed conformance harness for
     * an adapter that was authored ten minutes ago, and the honest evidence is
     * the grammar verdict plus the operator's stated reason. So the fact is
     * now DECLARED rather than implied, in one grammar with two admissible
     * shapes, and the weaker one is admissible only under the SITE root:
     *
     *   exercised: true  — today's rule, unchanged, and the only shape a
     *                      platform-rooted certificate may take.
     *   exercised: false — no exercise proof; `tests` and `artifacts` must
     *                      both be empty, and the claim carries the fact
     *                      forward so `duo assess` prints it rather than
     *                      letting `certified` imply an exercise nobody ran.
     *
     * `grammar` must be `ok`: a certificate for a manifest the loader itself
     * refuses would be certifying bytes no command can use.
     *
     * @param mixed $evidence
     */
    private static function bundleEvidence($evidence, string $label, string $trustRoot): bool {
        if (!is_array($evidence) || array_is_list($evidence)) {
            throw new \RuntimeException("duo: $label.evidence must be an object");
        }
        // The reviewer tier's reserved slot (§ v3.10, WP-4.11), named before
        // `assertExactKeys()` reaches it. Refused either way — the key set is
        // closed in both directions — so the verdict does not move; what moves
        // is that an author holding a bundle minted for the reviewer tier is
        // told the tier is shut and which gate opens it, rather than being told
        // their evidence object "must contain exactly exercised, grammar,
        // reason", which reads as a malformed bundle.
        if (array_key_exists(self::RESERVED_EVIDENCE_REVIEWER, $evidence)) {
            throw new \RuntimeException(
                "duo: $label.evidence declares '" . self::RESERVED_EVIDENCE_REVIEWER
                . "' — the reviewer evidence member is reserved; it is admitted when the reviewer tier opens at gate G4"
            );
        }
        self::assertExactKeys($evidence, ['exercised', 'grammar', 'reason'], "$label.evidence");
        $exercised = $evidence['exercised'] ?? null;
        if (!is_bool($exercised)
            || ($evidence['grammar'] ?? null) !== AdapterSources::GRAMMAR_OK
            || !is_string($evidence['reason'] ?? null) || trim((string) $evidence['reason']) === '') {
            throw new \RuntimeException(
                "duo: $label.evidence must declare a boolean exercised, grammar '" . AdapterSources::GRAMMAR_OK
                . "', and a non-empty reason"
            );
        }
        if (!$exercised && $trustRoot !== self::TRUST_ROOT_SITE) {
            throw new \RuntimeException(
                "duo: $label declares no exercise proof, which only a " . self::TRUST_ROOT_SITE
                . ' trust root may certify — a platform-rooted certificate states a reviewed exercise'
            );
        }
        return $exercised;
    }

    /** @return array{path:string,sha256:string,size:int} */
    private static function assetDescriptor($descriptor, string $label, ?string $expectedPath = null): array {
        if (!is_array($descriptor) || array_is_list($descriptor)) {
            throw new \RuntimeException("duo: $label must be an asset descriptor object");
        }
        self::assertExactKeys($descriptor, ['path', 'sha256', 'size'], $label);
        if (!is_string($descriptor['path'] ?? null)
            || !self::sha($descriptor['sha256'] ?? null)
            || !is_int($descriptor['size'] ?? null) || $descriptor['size'] < 0) {
            throw new \RuntimeException("duo: $label is malformed");
        }
        $path = self::relativePath($descriptor['path'], $label . '.path');
        if ($expectedPath !== null && !hash_equals($expectedPath, $path)) {
            throw new \RuntimeException("duo: $label path must be exactly $expectedPath");
        }
        return ['path' => $path, 'sha256' => $descriptor['sha256'], 'size' => $descriptor['size']];
    }

    private static function testId($value, string $label): string {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]*$/D', $value) !== 1) {
            throw new \RuntimeException("duo: $label must match ^[a-z][a-z0-9-]*$");
        }
        return $value;
    }

    private static function bundleDigest(array $bundle): string {
        $unsigned = $bundle;
        unset($unsigned['bundle_digest']);
        try {
            $json = json_encode(
                Canon::normalize($unsigned),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('duo: certification bundle is not canonicalizable: ' . $e->getMessage());
        }
        return hash('sha256', $json . "\n");
    }

    /**
     * A passing general bundle is not evidence for this adapter unless it
     * itself bound the exact raw source bytes.  Live signing compares those
     * bytes; frozen verification compares the same descriptor with the raw
     * binding already inside the signed adapter statement.
     */
    private static function assertBundleSubjectInput(array $boundInputs, string $name, array $adapter): void {
        $path = 'adapters/' . $name . '.json';
        $matches = [];
        foreach ($boundInputs as $input) {
            if (($input['path'] ?? null) === $path) {
                $matches[] = $input;
            }
        }
        if (count($matches) !== 1
            || !self::sha($adapter['raw_sha256'] ?? null)
            || !is_int($adapter['raw_size'] ?? null) || $adapter['raw_size'] < 1
            || !hash_equals((string) $matches[0]['sha256'], (string) $adapter['raw_sha256'])
            || $matches[0]['size'] !== $adapter['raw_size']) {
            throw new \RuntimeException(
                "duo: certification bundle must bind exactly current raw adapters/$name.json as one bound input"
            );
        }
    }

    /**
     * Import-time evidence verification.  The bundle manifest is not enough:
     * every described asset and every repository-bound source input must still
     * exist at the recorded bytes before a signing key is used.
     *
     * @return array{0:string,1:object,2:array}
     */
    private static function verifyBundleAssets(
        string $bundleDir,
        object $bundleTyped,
        array $bundle,
        array $info,
        string $evidenceRoot
    ): array {
        // Keep the exact bytes which passed the content-address check.  The
        // semantic checks below must parse these buffers, never reopen a path
        // after an attacker has had a chance to replace its contents.
        $verifiedAssets = [];
        foreach ($info['assets'] as $asset) {
            $verifiedAssets[$asset['path']] = self::readVerifiedBundleAsset($bundleDir, $asset);
        }
        foreach ($info['bound_inputs'] as $input) {
            $file = self::ownedFile($evidenceRoot, $input['path'], 'certification bundle bound input');
            $size = filesize($file);
            $digest = hash_file('sha256', $file);
            if ($size === false || $digest === false || $size !== $input['size']
                || !hash_equals($input['sha256'], $digest)) {
                throw new \RuntimeException(
                    "duo: certification bundle bound input is missing or tampered: {$input['path']}"
                );
            }
        }

        [, $environmentTyped] = self::parseCanonicalObject(
            $verifiedAssets['environment.json'],
            'certification bundle environment asset'
        );
        if (!hash_equals(Canon::encode($environmentTyped), Canon::encode($bundleTyped->environment_summary))) {
            throw new \RuntimeException('duo: certification bundle environment asset disagrees with environment_summary');
        }

        foreach ($bundle['tests'] as $test) {
            $id = (string) $test['id'];
            [, , $result] = self::parseBundleObject(
                $verifiedAssets['results/' . $id . '.json'],
                "certification bundle result '$id'"
            );
            if (($result['test'] ?? null) !== $id || ($result['verdict'] ?? null) !== 'pass'
                || ($result['exit_code'] ?? null) !== 0) {
                throw new \RuntimeException(
                    "duo: certification bundle result '$id' does not record a named passing zero-exit test"
                );
            }
            self::parseCanonicalObject(
                $verifiedAssets['diffs/' . $id . '.json'],
                "certification bundle diff '$id'"
            );
        }

        return self::parseCanonicalObject(
            $verifiedAssets['ratification.json'],
            'certification bundle ratification asset'
        );
    }

    /** @param array{path:string,sha256:string,size:int} $asset */
    private static function readVerifiedBundleAsset(string $bundleDir, array $asset): string {
        $file = self::ownedFile($bundleDir, $asset['path'], 'certification bundle asset');
        $raw = file_get_contents($file);
        if ($raw === false || strlen($raw) !== $asset['size']
            || !hash_equals($asset['sha256'], hash('sha256', $raw))) {
            throw new \RuntimeException(
                "duo: certification bundle asset is missing or tampered: {$asset['path']}"
            );
        }
        self::afterVerifiedBundleAssetRead($asset['path']);
        return $raw;
    }

    private static function afterVerifiedBundleAssetRead(string $path): void {
        if (self::$testVerifiedBundleAssetReadHook !== null) {
            (self::$testVerifiedBundleAssetReadHook)($path);
        }
    }

    /** @return array{0:array,1:array,2:string} */
    private static function verifyEmbeddedRatification(
        object $ratificationTyped,
        array $ratification,
        string $name,
        array $manifest,
        array $bundleInfo
    ): array {
        $raw = Canon::encode($ratificationTyped);
        [$data, $disposition] = self::verifyRatification(
            $ratificationTyped,
            $ratification,
            $name,
            $manifest,
            $bundleInfo,
            $raw
        );
        return [$data, $disposition, $raw];
    }

    /** @return array{tests:array<string,bool>,assets:array<string,array>,bound_inputs:list<array>,ratification_asset:array,summary:array,bundle_digest:string,git_revision:string,exercised:bool} */
    private static function verifyEmbeddedBundle(
        object $bundleTyped,
        array $bundle,
        string $name,
        string $trustRoot
    ): array {
        return self::verifyBundleManifest(
            $bundleTyped,
            $bundle,
            'signed certification bundle manifest',
            $name,
            $trustRoot
        );
    }

    /**
     * Validate the exact one-manifest ratification embedded in or imported
     * beside a bundle.  $ratificationRaw is canonical raw asset bytes when
     * importing and reconstructed canonical bytes at runtime.
     *
     * @return array{0:array,1:array}
     */
    private static function verifyRatification(
        object $ratificationTyped,
        array $ratification,
        string $name,
        array $manifest,
        array $bundleInfo,
        string $ratificationRaw
    ): array {
        self::assertExactKeys($ratification, ['format', 'manifests', 'profiles'], 'site adapter ratification');
        if (($ratification['format'] ?? null) !== self::RATIFICATION_FORMAT
            || !isset($ratificationTyped->manifests) || !is_object($ratificationTyped->manifests)
            || !isset($ratificationTyped->profiles) || !is_array($ratificationTyped->profiles)
            || !is_array($ratification['manifests'] ?? null) || array_is_list($ratification['manifests'])
            || !is_array($ratification['profiles'] ?? null) || !array_is_list($ratification['profiles'])
            || $ratification['profiles'] !== []) {
            throw new \RuntimeException(
                'duo: site adapter ratification must be a canonical one-manifest duo-manifest-dispositions/v1 document with profiles []'
            );
        }
        $ratifiedNames = array_keys($ratification['manifests']);
        foreach ($ratifiedNames as $ratifiedName) {
            if (!is_string($ratifiedName)) {
                throw new \RuntimeException(
                    'duo: site adapter ratification manifests map contains non-string key ' . var_export($ratifiedName, true)
                    . ' — numeric-only identities are forbidden because PHP coerces JSON object-map keys to integers'
                );
            }
            self::adapterName($ratifiedName);
        }
        if ($ratifiedNames !== [$name]
            || !isset($ratificationTyped->manifests->$name) || !is_object($ratificationTyped->manifests->$name)
            || !is_array($ratification['manifests'][$name]) || array_is_list($ratification['manifests'][$name])) {
            throw new \RuntimeException(
                "duo: site adapter ratification must name exactly manifests.$name"
            );
        }
        $disposition = $ratification['manifests'][$name];
        self::validateDisposition($name, $disposition, $manifest, (bool) $bundleInfo['exercised']);
        foreach ($disposition['evidence']['tests'] as $test) {
            if (!isset($bundleInfo['tests'][$test])) {
                throw new \RuntimeException(
                    "duo: certified site adapter '$name' cites absent or non-passing bundle test '$test'"
                );
            }
        }
        $expectedSummary = ['manifests.' . $name];
        if (($bundleInfo['summary']['certified_claims'] ?? null) !== $expectedSummary
            || ($bundleInfo['summary']['manifest_count'] ?? null) !== 1
            || ($bundleInfo['summary']['profile_count'] ?? null) !== 0) {
            throw new \RuntimeException(
                "duo: certification bundle ratification summary does not describe exactly certified manifests.$name"
            );
        }
        $asset = $bundleInfo['ratification_asset'];
        if (!hash_equals($asset['sha256'], hash('sha256', $ratificationRaw))
            || $asset['size'] !== strlen($ratificationRaw)) {
            throw new \RuntimeException(
                "duo: certification bundle ratification asset does not bind the exact ratification for '$name'"
            );
        }
        return [$ratification, $disposition];
    }

    private static function validateDisposition(
        string $name,
        array $disposition,
        array $manifest,
        bool $exercised
    ): void {
        // The authority-specific document is strict about the exact JSON
        // vocabulary it signs.  The semantic rules themselves are delegated to
        // ManifestDispositions so external and shipped entries cannot drift.
        self::assertExactKeys($disposition, [
            'capabilities', 'default_authored_keyspaces', 'evidence', 'reason', 'status', 'supported_versions', 'unsupported',
        ], "site adapter disposition '$name'");
        $capabilities = $disposition['capabilities'] ?? null;
        $evidence = $disposition['evidence'] ?? null;
        if (!is_array($capabilities) || array_is_list($capabilities)
            || !is_array($evidence) || array_is_list($evidence)) {
            throw new \RuntimeException("duo: site adapter disposition '$name' is malformed");
        }
        self::assertExactKeys($capabilities, [
            'deletion_semantics', 'entity_sections', 'field_sections', 'lifecycle_phases', 'operations',
        ], "site adapter disposition '$name'.capabilities");
        if (!is_array($capabilities['deletion_semantics'] ?? null)
            || array_is_list($capabilities['deletion_semantics'])) {
            throw new \RuntimeException("duo: site adapter disposition '$name' deletion semantics are malformed");
        }
        self::assertExactKeys($capabilities['deletion_semantics'], ['supported', 'unsupported'], "site adapter disposition '$name'.capabilities.deletion_semantics");
        self::assertExactKeys($evidence, ['bundle_schema', 'tests'], "site adapter disposition '$name'.evidence");
        foreach ((array) ($disposition['unsupported'] ?? []) as $i => $unsupported) {
            if (!is_array($unsupported) || array_is_list($unsupported)) {
                throw new \RuntimeException("duo: site adapter disposition '$name'.unsupported[$i] is malformed");
            }
            self::assertExactKeys($unsupported, ['operation', 'reason', 'surface'], "site adapter disposition '$name'.unsupported[$i]");
        }
        foreach ((array) ($disposition['default_authored_keyspaces'] ?? []) as $i => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException("duo: site adapter disposition '$name'.default_authored_keyspaces[$i] is malformed");
            }
            self::assertExactKeys($row, ['reason', 'status', 'table'], "site adapter disposition '$name'.default_authored_keyspaces[$i]");
        }
        ManifestDispositions::validate_external_entry(
            $name,
            $disposition,
            $manifest,
            self::BUNDLE_FORMAT,
            // An unexercised bundle has an empty test set by construction
            // (verifyBundleManifest() refuses any other shape), so a citation
            // requirement here would demand a test name that provably does not
            // exist. The bundle's own `exercised` flag is the single fact both
            // rules read.
            $exercised
        );
    }

    /**
     * The object inserted into Policy's existing disposition slot.  It binds
     * source identity and proof facts but deliberately does NOT calculate a
     * final adapter digest: integration derives that only after settling the
     * same disposition row into its normal manifest identity calculation.
     */
    private static function derivedDisposition(
        string $name,
        array $adapter,
        string $tier,
        array $ratifiedDisposition,
        array $authority,
        string $authorityDigest,
        array $bundle,
        string $platformDigest,
        string $ratificationRaw,
        string $certificateDigest,
        string $statementDigest
    ): array {
        unset($name);
        return [
            'certification' => 'certified',
            'provenance' => [
                // Preserve AdapterSources' source/path/canonical-manifest
                // vocabulary, with proof nested rather than widening the
                // source identity into an unauditable set of top-level facts.
                'format' => AdapterSources::FORMAT,
                'path' => $adapter['path'],
                'sha256' => $adapter['canonical_sha256'],
                'source' => $adapter['source'],
                'proof' => [
                    'authority' => [
                        'fingerprint' => $authority['fingerprint'],
                        'key_id' => $authority['key_id'],
                        'record_sha256' => $authorityDigest,
                        // Identity-bearing, like every other proof fact: the
                        // disposition is folded into the adapter digest and a
                        // repository pin binds that digest, so the same adapter
                        // re-certified under a different root is a different
                        // identity and every pin naming the old one refuses.
                        'trust_root' => $authority['trust_root'],
                    ],
                    'bundle' => [
                        'digest' => $bundle['bundle_digest'],
                        'exercised' => $bundle['exercised'],
                        'force_hatches' => [],
                        'git_revision' => $bundle['git_revision'],
                        'schema' => self::BUNDLE_FORMAT,
                        'signed_at' => $bundle['created_at'],
                        'tests' => $ratifiedDisposition['evidence']['tests'],
                    ],
                    'certificate_sha256' => $certificateDigest,
                    // RENAMED with its meaning, not quietly redefined under the
                    // old name (§ v3.6): this is the digest of the exercised
                    // AXES the certificate binds, which is a different number
                    // from `ContractAttestation`'s whole-boundary
                    // `platform_sha256` and must not be readable as it. It is
                    // also why the rider delivers a stable identity rather than
                    // only a valid certificate — this fact is folded into the
                    // adapter digest through the disposition
                    // (ArtifactPolicyIdentity::manifest_rows()), so a release
                    // that moves no exercised cell moves no pin either.
                    'platform_axes_sha256' => $platformDigest,
                    'ratification_sha256' => hash('sha256', $ratificationRaw),
                    'statement_sha256' => $statementDigest,
                    'raw_input' => [
                        'sha256' => $adapter['raw_sha256'],
                        'size' => $adapter['raw_size'],
                    ],
                ],
            ],
            'reason' => $ratifiedDisposition['reason'],
            'status' => 'certified',
            'trust_tier' => $tier,
        ];
    }

    /**
     * A source-scoped current claim.  It shares generic capability projection
     * with shipped claims, but its evidence and platform are the signed row's
     * own data, not the global shipped registry's evidence status.
     */
    private static function projectClaim(
        string $name,
        array $manifest,
        array $disposition,
        array $derived,
        array $platform
    ): array {
        $proof = $derived['provenance']['proof'];
        $evidence = [
            'authority_record_sha256' => $proof['authority']['record_sha256'],
            'bundle_digest' => $proof['bundle']['digest'],
            'bundle_schema' => $proof['bundle']['schema'],
            'certificate_sha256' => $proof['certificate_sha256'],
            // Carried onto the claim so a reader of `status: certified` can
            // see what was and was not proved. A site certificate with no
            // exercise proof is still a certified claim — the customer
            // organization is the authority for its own site — but `certified`
            // must not be readable as "somebody ran it".
            'exercised' => $proof['bundle']['exercised'],
            'force_hatches' => $proof['bundle']['force_hatches'],
            'git_revision' => $proof['bundle']['git_revision'],
            'platform_axes_sha256' => $proof['platform_axes_sha256'],
            'source' => AdapterSources::SITE,
            'status' => 'current',
            'statement_sha256' => $proof['statement_sha256'],
            'tests' => $proof['bundle']['tests'],
        ];
        $claim = ManifestDispositions::claim_from_disposition(
            $manifest,
            $disposition,
            $evidence,
            $platform,
            [
                'mode' => 'unmodified',
                'status' => 'verified',
            ]
        );
        // The generic projection has no source-specific facts.  Keep them
        // alongside it, while deliberately omitting an adapter digest: the
        // caller computes that after it installs this disposition into the
        // normal adapter identity row.
        $claim['name'] = $name;
        // The four facts the host projection needs to print `Site-certified`
        // with a principal (T6 §3.6) and nothing else: WHO certified, under
        // WHICH root, from WHICH adapter source, and WHEN. Every one is a
        // projection of the signed statement — `principal` is the authority
        // key id, `signed_at` is the bundle's own created_at, both inside the
        // signature — so the human line cannot drift from the claim that
        // `duo promote` gates on.
        $claim['certification'] = [
            'principal' => $derived['provenance']['proof']['authority']['key_id'],
            'signed_at' => $derived['provenance']['proof']['bundle']['signed_at'],
            'source' => AdapterSources::SITE,
            'trust_root' => $derived['provenance']['proof']['authority']['trust_root'],
        ];
        $claim['provenance'] = $derived['provenance'];
        $claim['trust_tier'] = $derived['trust_tier'];
        $claim['provider_code'] = [
            'binding' => 'providers_negotiation',
            'runtime_tree_digest' => 'not_bound',
        ];
        return $claim;
    }
}
