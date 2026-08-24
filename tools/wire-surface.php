#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate/check docs/wire-surface.md — the irreversibility register.
 *
 * Usage:
 *   php tools/wire-surface.php generate          # rewrite docs/wire-surface.md
 *   php tools/wire-surface.php --check           # rebuild in memory, byte-compare, exit 1 on drift
 *   php tools/wire-surface.php                   # same as --check
 *   php tools/wire-surface.php --check --root=D  # run the whole thing against another checkout
 *
 * WHAT THIS DOCUMENT IS FOR
 * -------------------------
 * A signature is a promise about bytes. The moment one certificate, one
 * attestation or one rollback receipt leaves this repository, every decision
 * inside the bytes it covers is fixed for as long as anyone holds it: the
 * domain string, the exact member set of the signed statement, the key-id
 * grammar that names the verifying key, the closed key set of the authority
 * record, the absence of an expiry field. None of those is versioned by the
 * agent version — `AdapterCertification::SIGNATURE_DOMAIN` (:52) is "kept
 * independent from JSON framing so this signature cannot verify elsewhere",
 * which is the same sentence read from the other end: nothing else can be made
 * to verify these bytes later either. The register states each decision once,
 * with what it costs to change and what it deliberately reserves.
 *
 * WHY IT IS GENERATED, AND WHAT THAT BUYS
 * ---------------------------------------
 * Same posture as tools/capability-doc.php (:11-12) and
 * tools/provider-protocol-doc.php (:12-20): a hand-written register is a
 * second copy of the wire, and the copy nobody executes is the one that rots.
 * The specific failure this design refuses is a register that reads correctly
 * at review time and has silently stopped describing the shipped constants by
 * the time an external party holds an artifact — false assurance exactly where
 * assurance is load-bearing.
 *
 * So every value below is READ OUT OF THE ENGINE, three ways, and never
 * restated:
 *
 *   - constants by reflection (private ones included: `KEY_ID_PATTERN`,
 *     `EXPIRES_FORMAT` and `RECEIPT_KEYS` are private and are still exactly
 *     what a holder's bytes are checked against);
 *   - closed key sets by reading the `assertExactKeys()`/`closedKeys()` call
 *     sites out of the shipped source, so an inline list nobody hoisted into a
 *     constant is projected from the one place it exists;
 *   - grammars, statuses and signature framing by RUNNING the shipped
 *     refusals — `keyId()`, `assertKeyId()`, `validateRecord()`,
 *     `signatureBytes()`, `RollbackControl::sign()` — and printing what they
 *     actually answer.
 *
 * The prose halves of each row (why a decision cannot change, what it
 * reserves) are this file's own bytes, because a rationale lives nowhere in
 * the engine. Everything factual beside them is projected, and four
 * completeness gates below refuse the whole run rather than emit a register
 * that has gone quiet about a surface:
 *
 *   1. every `sodium_crypto_sign_detached()` call site in agent/, cli/ and
 *      recovery/ is in a file this register covers;
 *   2. every `duo-…-signature/vN` literal in those trees is one of the
 *      projected domain constants;
 *   3. `AdapterCertification` still has no expiry vocabulary at all (row
 *      R-14 says so, and says what it would cost to add);
 *   4. no revocation-list vocabulary exists anywhere in the signing files
 *      (row R-15);
 *   5. the shipped `spec_version` acceptance window is exactly {N-1, N} — the
 *      floor is `DUO_SPEC_VERSION - 1` and never deeper (row R-18), measured
 *      by probing the shipped validator rather than by reading its condition.
 *
 * A new signed surface therefore cannot be added quietly: it fails gate 1 or 2
 * until it is registered, and any moved constant fails the byte-compare with
 * the first differing line named.
 *
 * Two rows here are not about a signature (R-17, R-19) and one is not either
 * (R-18). They are in the register because it is the list of decisions an
 * external party's bytes make permanent, and a bare `id_kind`, a declared
 * engine feature name and an accepted `spec_version` are each inside bytes this
 * product cannot rewrite afterwards — captured state and `duo_map` rows for the
 * first, the manifest bytes an adapter digest folds for the second, and every
 * adapter in the field authored against the window for the third.
 */

// $_SERVER['argv'] rather than the bare superglobal, for the reason
// tools/affected.php:1501-1504 states: PHPStan cannot prove
// register_argc_argv is on (it always is under the CLI SAPI), so the bare
// form is a permanent false positive.
$wsArgv = is_array($_SERVER['argv'] ?? null) ? array_map('strval', $_SERVER['argv']) : [];

$wsRoot = null;
foreach (array_slice($wsArgv, 1) as $wsArg) {
    if (str_starts_with($wsArg, '--root=')) {
        $wsRoot = rtrim(substr($wsArg, 7), '/');
    }
}
$repo = $wsRoot !== null && $wsRoot !== '' ? $wsRoot : dirname(__DIR__);

require_once $repo . '/agent/src/Adapter/AdapterCertification.php';
require_once $repo . '/agent/src/Kernel/ReferenceKindGrammar.php';
require_once $repo . '/agent/src/Adapter/AdapterContractGrammar.php';
require_once $repo . '/cli/src/Contract/ContractAttestation.php';
require_once $repo . '/recovery/rollback-control.php';

// The spec-version window (R-18) is a CONDITION inside the shipped validator,
// not a list, so the only honest way to project it is to run that validator —
// which needs the define the engine itself reads. Parsed out of agent/duo.php
// exactly as tools/capability-doc.php:118-127 parses it, rather than requiring
// the drop-in: agent/duo.php returns immediately outside WordPress
// (agent/duo.php:8), so the defines would never be reached.
if (!defined('DUO_SPEC_VERSION')) {
    $wsDuo = (string) file_get_contents($repo . '/agent/duo.php');
    if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $wsDuo, $wsSpec) !== 1) {
        fwrite(STDERR, "wire-surface: agent/duo.php no longer declares DUO_SPEC_VERSION; row R-18 projects it\n");
        exit(1);
    }
    define('DUO_SPEC_VERSION', (int) $wsSpec[1]);
}

use Duo\AdapterCertification;
use Duo\AdapterContractGrammar;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\ContractAttestation;
use Duo\Recovery\CanonicalJson;
use Duo\Recovery\RollbackControl;
use Duo\ReferenceKindGrammar;

/** The three files that own an Ed25519 signature, relative to the repo root. */
const WS_SIGNING_FILES = [
    'agent/src/Adapter/AdapterCertification.php',
    'cli/src/Contract/ContractAttestation.php',
    'recovery/rollback-control.php',
];

function ws_fail(string $message): never {
    fwrite(STDERR, "wire-surface: $message\n");
    exit(1);
}

/** Render bytes so a NUL or a control character is visible in the document. */
function ws_bytes(string $value): string {
    $out = '';
    foreach (str_split($value) as $byte) {
        $ord = ord($byte);
        $out .= $ord < 0x20 || $ord === 0x7f
            ? ($byte === "\0" ? '\\0' : sprintf('\\x%02x', $ord))
            : $byte;
    }

    return $out;
}

/** @return mixed a constant's value, private ones included */
function ws_const(string $class, string $name): mixed {
    $reflection = new ReflectionClass($class);
    if (!$reflection->hasConstant($name)) {
        ws_fail("$class::$name no longer exists; the register row that projects it is stale");
    }

    return $reflection->getConstant($name);
}

/**
 * Run one shipped refusal and report what it answered.
 *
 * The point of invoking a private static rather than re-implementing its test
 * is that this IS the code path a holder's bytes take; a re-implementation
 * would be the second list this whole file exists to avoid.
 */
function ws_probe(string $class, string $method, array $args): ?string {
    try {
        (new ReflectionMethod($class, $method))->invokeArgs(null, $args);

        return null;
    } catch (ReflectionException $e) {
        ws_fail("$class::$method is no longer reachable: " . $e->getMessage());
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function ws_verdict(?string $refusal): string {
    return $refusal === null ? 'accepted' : 'refused';
}

/** @return list<string> the raw source text of each argument of a call */
function ws_call_args(array $tokens, int $open): array {
    $depth = 0;
    $inString = false;
    $args = [''];
    for ($i = $open, $n = count($tokens); $i < $n; $i++) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        // An interpolated label such as "$label.tests[$i]" tokenizes its own
        // brackets as bare `[`/`]`, which would close the argument early and
        // truncate the label. Nothing inside a double-quoted string nests.
        if ($text === '"') {
            $inString = !$inString;
        }
        if ($inString) {
            $args[count($args) - 1] .= $text;
            continue;
        }
        if ($text === '(' || $text === '[') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($text === ')' || $text === ']') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        } elseif ($text === ',' && $depth === 1) {
            $args[] = '';
            continue;
        }
        $args[count($args) - 1] .= $text;
    }

    return array_map('trim', $args);
}

/**
 * Every closed key set in one shipped file, read out of its call sites.
 *
 * `assertExactKeys()` and `closedKeys()` are where a document's key set is
 * DECIDED — both refuse a missing key and an unknown key with the same
 * failure, which is exactly why the set is irreversible once anyone holds a
 * document. Most of these lists are inline literals at the call site rather
 * than constants, so reflection cannot see them and a hand copy here would be
 * the drift this file exists to prevent.
 *
 * @param list<string> $callees
 * @return list<array{function:string,label:string,keys:list<string>,optional:list<string>}>
 */
function ws_key_sets(string $file, array $callees, string $class, ?string $onlyFunction = null): array {
    $source = @file_get_contents($file);
    if ($source === false) {
        ws_fail("cannot read $file");
    }
    $tokens = token_get_all($source);
    $rows = [];
    $function = '?';
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($tokens[$j] === '(') {
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $function = $tokens[$j][1];
                    break;
                }
            }
            continue;
        }
        if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], $callees, true)) {
            continue;
        }
        if (($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }
        // The declaration of the helper itself matches the same token shape as
        // a call to it, and its `array $expected` parameter is not a key set.
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_FUNCTION) {
                continue 2;
            }
            break;
        }
        if ($onlyFunction !== null && $function !== $onlyFunction) {
            continue;
        }
        $args = ws_call_args($tokens, $i + 1);
        $rows[] = [
            'function' => $function,
            'label' => ws_key_set_label($args[3] ?? $args[2] ?? '', $function),
            // Three receipt shapes share one label at the call site and are
            // told apart only by the constant each one names, so the constant
            // is part of the row's identity.
            // Digits included: a versioned key set is spelled with the version
            // in its own name (AUTHORITIES_ENVELOPE_V2_KEYS), and a pattern
            // that could not see one would report it as a non-constant.
            'constant' => preg_match('/^self::([A-Z0-9_]+)$/D', $args[1] ?? '', $match) === 1 ? $match[1] : '',
            'keys' => ws_key_list($args[1] ?? '', $class, $file),
            'optional' => isset($args[2]) && $token[1] === 'closedKeys'
                ? ws_key_list($args[2], $class, $file)
                : [],
        ];
    }
    if ($rows === []) {
        ws_fail("no closed key set is reachable in $file; the register cannot project what it cannot find");
    }

    return $rows;
}

/** @return list<string> */
function ws_key_list(string $argument, string $class, string $file): array {
    if (preg_match('/^self::([A-Z0-9_]+)$/D', $argument, $match) === 1) {
        $value = ws_const($class, $match[1]);
        if (!is_array($value)) {
            ws_fail("$class::{$match[1]} is not a key list");
        }

        return array_values(array_map('strval', $value));
    }
    if (str_starts_with($argument, '[') && preg_match_all("/'([^']*)'/", $argument, $matches) > 0) {
        return $matches[1];
    }
    ws_fail("a closed key set in $file is not a literal list or a constant: $argument");
}

function ws_key_set_label(string $argument, string $function): string {
    $label = trim($argument);
    if (preg_match('/^\'(.*)\'$/Ds', $label, $match) === 1) {
        return $match[1];
    }
    if (preg_match('/^"(.*)"$/Ds', $label, $match) === 1) {
        return $match[1];
    }

    return $label === '' ? $function . '()' : $label;
}

/**
 * Gate 1: no signed surface exists outside the three registered files.
 *
 * A fourth signing site would be a fourth set of permanent decisions with no
 * register row, which is the exact failure this document exists to prevent.
 */
function ws_assert_signing_files(string $repo): void {
    $found = [];
    foreach (['agent', 'cli', 'recovery'] as $tree) {
        foreach (ws_php_files($repo . '/' . $tree) as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, 'sodium_crypto_sign_detached(')) {
                $found[] = substr($file, strlen($repo) + 1);
            }
        }
    }
    sort($found, SORT_STRING);
    $expected = WS_SIGNING_FILES;
    sort($expected, SORT_STRING);
    if ($found !== $expected) {
        ws_fail(
            'the set of files that mint an Ed25519 signature moved: found ' . implode(', ', $found)
            . ' — every signing surface needs a register row before it ships'
        );
    }
}

/**
 * Gate 2: every signature-domain literal in the shipped trees is one of the
 * domains this register projects. A new domain string anywhere (a delegation
 * kind, a second authorities envelope) fails here until it is registered.
 *
 * @param list<string> $domains
 */
function ws_assert_domain_literals(string $repo, array $domains): void {
    $known = [];
    foreach ($domains as $domain) {
        $known[rtrim($domain, "\0")] = true;
    }
    foreach (['agent', 'cli', 'recovery'] as $tree) {
        foreach (ws_php_files($repo . '/' . $tree) as $file) {
            preg_match_all(
                '/duo-[A-Za-z0-9._-]*-signature\/v[0-9]+/',
                (string) file_get_contents($file),
                $matches
            );
            foreach ($matches[0] as $literal) {
                if (!isset($known[$literal])) {
                    ws_fail(
                        "an unregistered signature domain '$literal' appears in "
                        . substr($file, strlen($repo) + 1)
                        . ' — a new domain is a new permanent decision and needs a register row'
                    );
                }
            }
        }
    }
}

/**
 * Gates 3 and 4: one ABSENCE and one BOUNDED PRESENCE the register records.
 *
 * An absence is the hardest thing to keep honest in a document, because
 * nothing about adding the field would make a hand-written sentence wrong in
 * any visible way. So the sentences in R-14 and R-15 are backed by a grep that
 * fails the gate the moment the vocabulary appears.
 *
 * R-14's adapter-certification half was such an absence until WP-4.8: the
 * authority record v2 grammar adds `not_after`/`not_before` (§ v3.7), so the
 * grep changed from "no expiry vocabulary at all" to "EXACTLY this expiry
 * vocabulary, judged against exactly this clock". The ratchet is the same one:
 * a third time member, or a second time source, fails the gate rather than
 * quietly making R-14's sentence wrong.
 */
function ws_assert_reserved_absences(string $repo): void {
    $certification = (string) file_get_contents($repo . '/agent/src/Adapter/AdapterCertification.php');
    $timeMembers = [];
    if (preg_match_all("/'(not_after|not_before|expires_at|expires|valid_until|not_valid_after)'/", $certification, $found) > 0) {
        $timeMembers = array_values(array_unique($found[1]));
    }
    sort($timeMembers, SORT_STRING);
    if ($timeMembers !== ['not_after', 'not_before']) {
        ws_fail(
            'the adapter certification expiry vocabulary is now {' . implode(', ', $timeMembers) . '}; row R-14 '
            . 'records it as exactly {not_after, not_before} and must be rewritten before that ships'
        );
    }
    // The substring, not the parenthesized form, because the two files reach
    // the same expression differently — ContractAttestation compares it inline,
    // AdapterCertification returns it — and what R-14 claims is that both read
    // ONE clock, not that both spell the surrounding statement the same way.
    if (!str_contains($certification, '$now ?? time()')) {
        ws_fail(
            'AdapterCertification no longer judges its window against `$now ?? time()`; row R-14 names that '
            . 'expression as the one clock BOTH expiry-bearing roots read'
        );
    }
    foreach (WS_SIGNING_FILES as $relative) {
        $source = (string) file_get_contents($repo . '/' . $relative);
        if (preg_match('/revocation_list|revoked_at|\bcrl\b/i', $source) === 1) {
            ws_fail(
                "$relative now carries revocation-list vocabulary; row R-15 records that revocation is a "
                . 'per-key status word and nothing else'
            );
        }
    }
    $attestation = (string) file_get_contents($repo . '/cli/src/Contract/ContractAttestation.php');
    if (!str_contains($attestation, '($now ?? time())')) {
        ws_fail(
            'ContractAttestation no longer compares against `$now ?? time()`; row R-14 names that expression '
            . 'as the one clock an expiry is judged against'
        );
    }
}

/**
 * The `spec_version` integers the shipped validator ACCEPTS, measured.
 *
 * Probed over N-3 … N+2 rather than over the window's own two integers, so a
 * window that widened DOWNWARD shows up as a longer list instead of being
 * clipped by the range that asked. A minimal manifest is the right probe
 * subject because every other check in `validate_adapter_contract()` is keyed
 * on a declaration it does not carry, so the only verdict measured is the
 * version one — the same argument ManifestValidate::specWindow() states for the
 * identical technique.
 *
 * @return list<int>
 */
function ws_spec_window(): array {
    $supported = DUO_SPEC_VERSION;
    $accepted = [];
    for ($candidate = $supported - 3; $candidate <= $supported + 2; $candidate++) {
        try {
            AdapterContractGrammar::validate_adapter_contract([
                'name' => 'wire-surface-probe',
                'spec_version' => $candidate,
            ]);
            $accepted[] = $candidate;
        } catch (Throwable) {
            // Outside the window. The refusal is the author's coordinate, not
            // this register's; `duo manifest-validate` prints it verbatim.
        }
    }

    return $accepted;
}

/**
 * Gate 5: the acceptance window's FLOOR is exactly `DUO_SPEC_VERSION - 1`.
 *
 * The window exists so that a format change stages one adapter at a time
 * instead of being a flag day (spec/repo-format.md § v3.1). The failure that
 * would quietly undo it is not a narrowing — a narrowing refuses loudly, on
 * every site holding an N-1 manifest — but an ACCUMULATION: an engine that
 * kept accepting N-2 "for one more release" turns a staging channel into
 * permanent tolerance, and nothing about the extra integer is visible in any
 * refusal, any document or any suite that only asks whether N-1 loads. So the
 * equality is asserted here, where `make release-gate` runs it, and closing the
 * window stays its own dated decision (§ v3.12) rather than a side effect of the
 * next release.
 */
function ws_assert_spec_window(): void {
    $expected = [DUO_SPEC_VERSION - 1, DUO_SPEC_VERSION];
    $accepted = ws_spec_window();
    if ($accepted === $expected) {
        return;
    }
    ws_fail(
        'the shipped spec_version acceptance window is {' . implode(', ', $accepted) . '}, not {'
        . implode(', ', $expected) . '} — row R-18 records the floor as exactly DUO_SPEC_VERSION - 1, so '
        . 'N-2 can never accumulate by inattention and a widened window is a reviewed change here first'
    );
}

/**
 * Gate 6 (WP-4.8, spec § v3.7 change (d)): the SHIPPED authorities document.
 *
 * `manifests/capabilities/adapter-authorities.json` is the platform trust root
 * — the one file in this repository whose contents decide what a stranger's
 * key may certify on every managed site. It has exactly two legal states and
 * this gate refuses everything else:
 *
 *   - the EMPTY v1 registry, byte for byte. An empty registry grants nothing,
 *     so there is no key inside it that could sign it and a signature over an
 *     empty key set would prove nothing about any key. This is the state that
 *     ships through the flag day;
 *   - a v2 registry that VERIFIES — read through the shipped reader, so the
 *     envelope signature, the fingerprint-bound ids, the windows and the
 *     namespace patterns are all checked by the code a site runs, never by a
 *     second implementation living in a tool.
 *
 * The second state is what makes this a gate rather than a byte-equality
 * check: the day enrollment fills this file, the release gate is what refuses
 * an unsigned or tampered one before it can reach a site.
 */
function ws_assert_shipped_authorities(string $repo): void {
    $relative = 'manifests/capabilities/adapter-authorities.json';
    $raw = @file_get_contents($repo . '/' . $relative);
    if ($raw === false) {
        ws_fail("$relative is missing; it is the platform trust root and its absence is not an empty root");
    }
    $empty = Canon::encode((object) [
        'format' => AdapterCertification::AUTHORITIES_FORMAT,
        'keys' => new stdClass(),
    ]);
    if ($raw === $empty) {
        return;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || ($decoded['format'] ?? null) !== AdapterCertification::AUTHORITIES_FORMAT_V2) {
        ws_fail(
            "$relative is neither the empty " . AdapterCertification::AUTHORITIES_FORMAT . ' registry nor a '
            . AdapterCertification::AUTHORITIES_FORMAT_V2
            . ' document — a populated trust root must carry the signed envelope R-18 requires'
        );
    }
    try {
        (new ReflectionMethod(AdapterCertification::class, 'authorityKeys'))
            ->invoke(null, $repo . '/' . $relative, 'adapter certification authorities');
    } catch (Throwable $e) {
        ws_fail(
            "$relative does not verify through the shipped reader: " . $e->getMessage()
            . ' — an unsigned or tampered platform trust root never ships'
        );
    }
}

/** @return list<string> */
function ws_php_files(string $directory): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = $entry->getPathname();
        }
    }
    sort($files, SORT_STRING);

    return $files;
}

/**
 * The signed bytes of each surface, proved rather than described.
 *
 * Each row is produced by calling the shipped signer/framer: the adapter and
 * contract rows strip the projected domain off the real signature input and
 * assert the remainder is exactly the canonical statement, and the rollback
 * row mints a real signature and verifies it against the UNPREFIXED canonical
 * payload — which is how the "no domain separation" row proves itself instead
 * of asserting itself.
 *
 * @return list<array{surface:string,domain:string,input:string,source:string}>
 */
function ws_signature_inputs(): array {
    $rows = [];

    $statement = ['probe' => 'wire-surface'];
    $adapterDomain = (string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN');
    $adapterInput = (string) ws_probe_value(AdapterCertification::class, 'signatureBytes', [$statement]);
    if (!str_starts_with($adapterInput, $adapterDomain)
        || substr($adapterInput, strlen($adapterDomain)) !== Canon::encode($statement)) {
        ws_fail('the adapter certification signature input is no longer domain . Canon::encode(statement)');
    }
    $rows[] = [
        'surface' => 'site adapter certification (`' . AdapterCertification::FORMAT . '`)',
        'domain' => '`' . ws_bytes($adapterDomain) . '`',
        'input' => 'domain &#124;&#124; `Canon::encode(statement)` — the whole five-member statement',
        'source' => '`AdapterCertification::SIGNATURE_DOMAIN`',
    ];

    $contractDomain = (string) ws_const(ContractAttestation::class, 'SIGNATURE_DOMAIN');
    $contractInput = (string) ws_probe_value(ContractAttestation::class, 'signedBytes', ['sha256:' . str_repeat('0', 64)]);
    if (!str_starts_with($contractInput, $contractDomain)) {
        ws_fail('the contract attestation signature input no longer begins with its domain');
    }
    $members = json_decode(substr($contractInput, strlen($contractDomain)), true);
    if (!is_array($members)) {
        ws_fail('the contract attestation signature input is no longer canonical JSON after its domain');
    }
    // The v2 authorities envelope (WP-4.8). Proved the same way as the two
    // above: the shipped framer is called, its domain is stripped, and the
    // remainder must be exactly the canonical document MINUS its own signature.
    $authoritiesDomain = (string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN_AUTHORITIES');
    $probeKeys = new stdClass();
    $probeKeys->{'probe-000000000000'} = ['probe' => 'wire-surface'];
    $authoritiesInput = (string) ws_probe_value(
        AdapterCertification::class,
        'authoritiesSignatureBytes',
        [AdapterCertification::AUTHORITIES_FORMAT_V2, $probeKeys]
    );
    if (!str_starts_with($authoritiesInput, $authoritiesDomain)
        || substr($authoritiesInput, strlen($authoritiesDomain)) !== Canon::encode((object) [
            'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
            'keys' => $probeKeys,
        ])) {
        ws_fail('the authorities envelope signature input is no longer domain . Canon::encode({format, keys})');
    }
    $rows[] = [
        'surface' => 'authorities envelope (`' . AdapterCertification::AUTHORITIES_FORMAT_V2 . '`)',
        'domain' => '`' . ws_bytes($authoritiesDomain) . '`',
        'input' => 'domain &#124;&#124; `Canon::encode({format, keys})` — the document minus its own signature',
        'source' => '`AdapterCertification::SIGNATURE_DOMAIN_AUTHORITIES`',
    ];

    $rows[] = [
        'surface' => 'contract attestation (`' . ApplicationContract::ATTESTATION_FORMAT . '`)',
        'domain' => '`' . ws_bytes($contractDomain) . '`',
        'input' => 'domain &#124;&#124; `Canon::encode({' . implode(', ', array_keys($members)) . '})`',
        'source' => '`ContractAttestation::SIGNATURE_DOMAIN`',
    ];

    $rows[] = [
        'surface' => 'rollback receipt / event (`' . RollbackControl::RECEIPT_FORMAT . '`, `'
            . RollbackControl::EVENT_FORMAT . '`)',
        'domain' => '**none** — proved at generation time',
        'input' => '`CanonicalJson::encode(payload)` with nothing prepended',
        'source' => '`RollbackControl::sign()`',
    ];

    return $rows;
}

/** Prove the rollback signature covers the bare canonical payload. */
function ws_assert_rollback_is_domain_free(): void {
    if (!function_exists('sodium_crypto_sign_keypair')) {
        ws_fail('this checker needs ext-sodium, which is what every signature it registers needs too');
    }
    $keypair = sodium_crypto_sign_keypair();
    $payload = ['format' => RollbackControl::RECEIPT_FORMAT, 'probe' => 'wire-surface'];
    $signed = RollbackControl::sign($payload, 'wire-surface-probe', sodium_crypto_sign_secretkey($keypair));
    $signature = base64_decode((string) $signed['signature'], true);
    if ($signature === false || !sodium_crypto_sign_verify_detached(
        $signature,
        CanonicalJson::encode($payload),
        sodium_crypto_sign_publickey($keypair)
    )) {
        ws_fail(
            'RollbackControl::sign() no longer signs the bare canonical payload; row R-03 records the '
            . 'absence of domain separation as the shipped decision'
        );
    }
}

/** @return mixed the return value of a private static, for framing probes */
function ws_probe_value(string $class, string $method, array $args): mixed {
    try {
        return (new ReflectionMethod($class, $method))->invokeArgs(null, $args);
    } catch (Throwable $e) {
        ws_fail("$class::$method could not be projected: " . $e->getMessage());
    }
}

/**
 * The key-id grammar matrix: one probe corpus, three shipped validators.
 *
 * The corpus is chosen to sit exactly on the disagreements — uppercase, a
 * leading hyphen, a numeric-only id, a 65th character — because the register's
 * claim is not "there is a grammar" but "there are three and they differ".
 *
 * @return array{corpus:list<string>,rows:list<array{probe:string,adapter:string,contract:string,rollback:string}>}
 */
function ws_key_id_matrix(): array {
    $corpus = [
        'wpforms', 'acme.key_1', 'Acme-Key', '2026', 'a', '-leading', 'trailing-',
        str_repeat('k', 65), 'has space', '',
    ];
    $rows = [];
    foreach ($corpus as $probe) {
        $adapter = ws_probe(AdapterCertification::class, 'keyId', [$probe]);
        $name = ws_probe(AdapterCertification::class, 'adapterName', [$probe]);
        if (ws_verdict($adapter) !== ws_verdict($name)) {
            ws_fail(
                "the authority key-id grammar and the adapter-name grammar disagree on '$probe'; row R-12 "
                . 'records them as the one AdapterSources::assert_name() grammar'
            );
        }
        $rows[] = [
            'probe' => $probe === '' ? '(empty)' : '`' . $probe . '`',
            'adapter' => ws_verdict($adapter),
            'contract' => ws_verdict(ws_probe(ContractAttestation::class, 'assertKeyId', [$probe])),
            'rollback' => ws_verdict(ws_probe(RollbackControl::class, 'assertKeyId', [$probe])),
        ];
    }

    return ['corpus' => $corpus, 'rows' => $rows];
}

/**
 * The contract authority record's closed set, derived rather than copied.
 *
 * `ContractAttestation::validateRecord()` holds its vocabulary in a local
 * variable, so neither reflection nor a call-site read can reach it. What CAN
 * be read is the answer: a record with the four members is accepted, a record
 * with a fifth is refused by name, and dropping any one of the four is
 * refused. That is the definition of a closed set, obtained from the refusal
 * itself.
 *
 * @return list<array{variation:string,verdict:string,refusal:string}>
 */
function ws_contract_record_probes(): array {
    $canonical = [
        'algorithm' => 'ed25519',
        'public_key' => base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
        'scope' => ContractAttestation::SCOPE,
        'status' => 'trusted',
    ];
    $probes = [['the four members', $canonical]];
    // `expires_at` rather than a nonsense key: R-14 reserves expiry for a
    // future root, and this is the refusal that says what adding it costs.
    $probes[] = ['plus `expires_at`', $canonical + ['expires_at' => '2027-01-01T00:00:00Z']];
    foreach (array_keys($canonical) as $key) {
        $without = $canonical;
        unset($without[$key]);
        $probes[] = ["minus `$key`", $without];
    }
    $probes[] = ['`status: suspended`', ['status' => 'suspended'] + $canonical];
    $rows = [];
    foreach ($probes as [$variation, $record]) {
        $refusal = ws_probe(ContractAttestation::class, 'validateRecord', [$record, 'k1']);
        $rows[] = [
            'variation' => $variation,
            'verdict' => ws_verdict($refusal),
            'refusal' => $refusal === null ? '—' : $refusal,
        ];
    }

    return $rows;
}

/**
 * The register itself: the projected fact, then the two things only a human
 * can state — what a change costs once artifacts are held, and what the
 * current decision deliberately leaves open.
 *
 * @return list<array{id:string,title:string,now:string,permanent:string,reserved:string}>
 */
function ws_rows(): array {
    $adapterDomain = (string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN');
    $contractDomain = (string) ws_const(ContractAttestation::class, 'SIGNATURE_DOMAIN');
    $keyIdPattern = (string) ws_const(ContractAttestation::class, 'KEY_ID_PATTERN');
    $expires = (string) ws_const(ContractAttestation::class, 'EXPIRES_FORMAT');
    $sets = ws_indexed_key_sets();
    $rows = [];

    $rows[] = [
        'id' => 'R-01',
        'title' => 'The adapter-certification signature domain',
        'now' => '`' . ws_bytes($adapterDomain) . '`, prepended to `Canon::encode(statement)`.',
        'permanent' => 'A holder verifies by recomputing these bytes. Changing the string does not '
            . 'invalidate old certificates — it makes them unverifiable by the agent that changed, which '
            . 'is the same outcome as revoking every one of them at once, with no message saying so. The '
            . 'domain is also the only thing standing between this statement and a verifier for another '
            . 'statement type: it is "kept independent from JSON framing so this signature cannot verify '
            . 'elsewhere" (AdapterCertification.php:52).',
        'reserved' => 'The `/v1` suffix is the whole change channel. A v2 domain is a NEW statement type '
            . 'verified alongside this one, never an edit of it; an agent may verify both, and a '
            . 'certificate says which it is by the bytes it was signed over.',
    ];
    $rows[] = [
        'id' => 'R-02',
        'title' => 'The contract-attestation signature domain and its two-member statement',
        'now' => '`' . ws_bytes($contractDomain) . '`, prepended to a canonical two-member statement '
            . '(`' . ContractAttestation::STATEMENT_FORMAT . '`). The signature binds `attested_digest` — '
            . 'the document minus `contract_digest`, with `attestation.signature` forced to null — never '
            . 'the document bytes.',
        'permanent' => 'The statement is rebuilt from the document at verify time, so a third member '
            . 'would change the bytes for every attestation already signed. The two exclusions are forced '
            . 'and cannot be revisited either: `contract_digest` is the digest of everything else, and a '
            . 'signature is never inside its own input.',
        'reserved' => 'Facts an operator wants signed go in `attestation`, which is already inside '
            . '`attested_digest`\'s input — that is the additive channel, and it is why the statement '
            . 'stays two members forever.',
    ];
    $rows[] = [
        'id' => 'R-03',
        'title' => 'Rollback receipts and events are signed with NO domain separation',
        'now' => 'Proved at generation time: a signature minted by `RollbackControl::sign()` verifies '
            . 'against `CanonicalJson::encode(payload)` with nothing prepended. Three payload kinds share '
            . 'one key and are told apart only by their own `format` member (`'
            . RollbackControl::RECEIPT_FORMAT . '`, `' . RollbackControl::EVENT_FORMAT . '`, `'
            . RollbackControl::SCOPED_PROMOTION_RECEIPT_FORMAT . '`) and by the key file at '
            . '`<root>/public-keys/<key_id>.pub`.',
        'permanent' => 'Prepending a domain later invalidates every receipt and every event already '
            . 'written into a site\'s recovery root — the artifacts a rollback reads precisely when '
            . 'everything else is broken, and the one class of artifact that cannot be re-minted after '
            . 'the fact. The three payload key sets (below) are what keep the kinds apart, so they carry '
            . 'the whole burden a domain would otherwise share.',
        'reserved' => 'A v3 payload may carry a domain member INSIDE the payload: additive, still '
            . 'canonical, and old signatures keep verifying. The prefix channel is spent.',
    ];
    $rows[] = [
        'id' => 'R-04',
        'title' => 'Both domains end in NUL, and neither is a prefix of the other',
        'now' => 'Checked at generation time against the two projected constants.',
        'permanent' => 'The trailing NUL is inside the signed bytes; it is what stops one domain from '
            . 'being a prefix of a longer one and turning a statement of one kind into a valid statement '
            . 'of another. Removing it from either domain is a domain change (R-01).',
        'reserved' => 'Any third domain must keep the terminator and must not be a prefix of an existing '
            . 'one — this checker refuses the register otherwise.',
    ];
    $rows[] = [
        'id' => 'R-05',
        'title' => 'The certificate root and the frozen envelope key sets',
        'now' => 'Certificate root: ' . ws_set($sets, 'site adapter certification')
            . '. Frozen envelope: ' . ws_set($sets, 'frozen certification envelope') . '.',
        'permanent' => 'Both are checked with `assertExactKeys()`, which refuses a MISSING key and an '
            . 'UNKNOWN key with one message. A v1 agent therefore refuses a certificate carrying a field '
            . 'it does not know rather than ignoring it, so no field can ever be added to this envelope '
            . 'for holders of today\'s agent.',
        'reserved' => 'Extension is by a new `format` value — a new envelope verified beside this one, '
            . 'never a widened one.',
    ];
    $rows[] = [
        'id' => 'R-06',
        'title' => 'The signed statement member set',
        'now' => ws_set($sets, 'site adapter certification statement') . '.',
        'permanent' => 'Same closure as R-05, and covered by the signature: a sixth member changes the '
            . 'signed bytes AND is refused by every deployed verifier. This is the row that makes every '
            . 'other adapter-certification decision permanent, because none of them can be revisited '
            . 'without adding or moving a member here.',
        'reserved' => 'Nothing. Facts that need signing go inside an existing member — `bundle` and '
            . '`ratification` are whole objects the signature already covers.',
    ];
    $rows[] = [
        'id' => 'R-07',
        'title' => 'The adapter binding, and what a certificate names',
        'now' => ws_set($sets, 'site adapter certification adapter binding')
            . '. `path` is always `adapters/<name>.json` and `source` is always `'
            . AdapterSources::SITE . '`.',
        'permanent' => 'The path string is inside the signed statement, so the certificate directory '
            . 'layout (`' . AdapterSources::SITE_DIR . '/`, certificates under `'
            . AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR . '/`) is signed data, '
            . 'not a local convention: moving the directory orphans every certificate in the field.',
        'reserved' => 'A second bindable source (a plugin-bundled adapter, `'
            . AdapterSources::PLUGIN . '`) would be a new `source` VALUE, which the key set already '
            . 'admits — the value vocabulary is the extension point, the key set is not.',
    ];
    $rows[] = [
        'id' => 'R-08',
        'title' => 'The authority binding inside the statement: BOTH roots bind the key identity',
        'now' => ws_set($sets, 'certification authority binding')
            . '. Both trust roots bind the key IDENTITY — everything but `adapter_names`/`trust_tiers` — '
            . 'self-consistently by digest, and re-check the two scope lists LIVE against the current '
            . 'record, revocation and (at record v2) the validity window with them.',
        'permanent' => 'A trust root is a LIVING registry: it grows every time another adapter is '
            . 'certified under a key, so whole-record binding invalidates every earlier certificate under '
            . 'that key the moment a second one is signed. The site root has bound identity since T6 for '
            . 'that reason; the platform root bound the whole record until WP-4.8, justified by a premise '
            . 'ENROLLMENT FALSIFIES — that the shipped file never grows under an operator\'s hand. It was '
            . 'changeable only because the platform root has never signed a certificate: '
            . '`manifests/capabilities/adapter-authorities.json` is `{"keys": {}}`, and gate 6 below '
            . 'refuses a populated one that does not verify. Going the other way — widening either root '
            . 'back to whole-record binding — would invalidate every certificate in the field at the next '
            . 'enrollment, silently.',
        'reserved' => 'A future root chooses one of these two bindings at the moment its first '
            . 'certificate is signed, and never after. There is no third choice, because the scope lists '
            . 'are either inside the signature or enforced live, and doing both is the first option.',
    ];
    $rows[] = [
        'id' => 'R-09',
        'title' => 'The two authority record key sets, and why they are two files',
        'now' => 'Adapter root (`' . AdapterCertification::AUTHORITIES_FORMAT . '`): '
            . ws_set($sets, 'validateAuthorityRecord') . ', and at `'
            . AdapterCertification::AUTHORITIES_FORMAT_V2 . '` the nine of R-18. Contract root (`'
            . ContractAttestation::AUTHORITIES_FORMAT . '`, at `'
            . ContractAttestation::AUTHORITIES_RELATIVE . '`): the four members derived by probe below, '
            . 'scope `' . ContractAttestation::SCOPE . '`.',
        'permanent' => 'The adapter root requires `adapter_names` and `trust_tiers` on EVERY record and '
            . 'validates the whole file the moment it exists, so a single contract-scoped record in that '
            . 'file breaks every adapter certificate in the repository. The two roots can never be '
            . 'merged; each record set can never gain a member, because both are closed in both '
            . 'directions.',
        'reserved' => 'A third scope word means a third file. That is the shape, and it is already '
            . 'load-bearing.',
    ];
    $rows[] = [
        'id' => 'R-10',
        'title' => 'The authorities envelope, and `keys` as a JSON object',
        'now' => 'Envelope: ' . ws_set($sets, 'authorityKeys') . ' (adapter root), and the same '
            . '`{format, keys}` shape for the contract root. `keys` is a JSON OBJECT even when it holds '
            . 'one member. A numeric-only key id cannot appear in either file at all, even though the '
            . 'contract grammar accepts one as a string (see the matrix): both roots refuse a non-string '
            . 'map key by name.',
        'permanent' => 'PHP decodes a numeric JSON object-map key as an integer, so a numeric identity '
            . 'would compare unequal to the string the signed document carries — refusing it is what '
            . 'makes the map key and the `key_id` inside the statement the same value. An empty PHP array '
            . 'canonically encodes as `[]`, which both readers refuse, so the object cast at write time '
            . 'is part of the wire, not a nicety.',
        'reserved' => 'A revocation list (R-15) cannot be added to this envelope: its key set is closed. '
            . 'It needs a new `format` value — which is exactly the channel `'
            . AdapterCertification::AUTHORITIES_FORMAT_V2 . '` used to add the envelope signature (R-18).',
    ];
    $rows[] = [
        'id' => 'R-11',
        'title' => 'Three key-id grammars, and they disagree',
        'now' => 'Adapter root: `AdapterSources::assert_name()` — lowercase slugs only, must start and '
            . 'end alphanumeric, at least one lowercase letter. Contract root: `' . $keyIdPattern
            . '`. Recovery root: `[A-Za-z0-9._-]{1,64}`, which admits a leading separator and carries a '
            . 'length bound the adapter root does not have at all. The '
            . 'matrix below is the shipped verdict of each, probe by probe.',
        'permanent' => 'A key id is an object-map key in signed documents, the `key_id` member inside a '
            . 'signed statement, and — in the recovery root — a FILENAME (`public-keys/<key_id>.pub`). '
            . 'Narrowing any of the three orphans installed keys and every artifact they signed; widening '
            . 'one makes an id legal in one root and unreadable in another. The divergence itself is now '
            . 'permanent: unifying them would narrow at least two of the three.',
        'reserved' => 'A future root may narrow at MINT time (refusing to register a key id) without '
            . 'touching what verification accepts. That is the only safe direction.',
    ];
    $rows[] = [
        'id' => 'R-12',
        'title' => 'Adapter names and authority key ids share one grammar',
        'now' => '`AdapterCertification::keyId()` and `::adapterName()` are both nothing but '
            . '`AdapterSources::assert_name()`; the matrix below is checked probe-by-probe for agreement '
            . 'and the generator refuses if they ever diverge.',
        'permanent' => 'The adapter name is inside the signed statement twice (as `name` and inside '
            . '`path`), and it is a filename on disk. One grammar for both is what lets a certificate '
            . 'name a key and an adapter with the same rules; splitting them later would make some '
            . 'existing certificate\'s `key_id` or `name` illegal.',
        'reserved' => 'Nothing. A wider name grammar is a wider filename grammar.',
    ];
    $rows[] = [
        'id' => 'R-13',
        'title' => 'Two trust roots, and the shipped library wins the key-id namespace',
        'now' => 'Adapter certification: `' . AdapterCertification::TRUST_ROOT_PLATFORM . '` and `'
            . AdapterCertification::TRUST_ROOT_SITE . '`. Contract attestation: `'
            . ContractAttestation::TRUST_ROOT_SITE . '` only — a platform-rooted contract attestation '
            . 'refuses by name (`contract_attestation_trust_root_unsupported`). A site certificate may '
            . 'never claim a key id the shipped file reviews.',
        'permanent' => 'The `trust_root` word is inside the signed statement and is what a host prints '
            . 'beside a certified claim. The shipped-wins rule needs no repository to evaluate, which is '
            . 'what lets frozen verification enforce it; relaxing it would let a site key answer for a '
            . 'reviewed identity in an artifact already frozen.',
        'reserved' => 'A third root is a new VALUE in a member that already exists — admitted without a '
            . 'schema change, which is why the refusal for an unsupported one is by name.',
    ];
    $rows[] = [
        'id' => 'R-14',
        'title' => 'Expiry exists on two surfaces, reads one clock, and allows no skew',
        'now' => 'Contract attestation: `expires_at` is mandatory, grammar `' . $expires
            . '` (UTC seconds, checked at both ends so "expired" is never a parse accident), compared '
            . 'against `$now ?? time()` and refused at `>=`. Adapter certification: the expiry vocabulary '
            . 'is EXACTLY `not_after`/`not_before`, mandatory on a `'
            . AdapterCertification::AUTHORITIES_FORMAT_V2 . '` authority record and absent from a v1 one, '
            . 'the identical grammar string, compared against the identical `$now ?? time()` and refused '
            . 'at `>=` — all four facts checked by grep, not asserted. Recovery: `claim_expires_at` '
            . 'bounds a claimant epoch, never a signature.',
        'permanent' => 'An expired attestation or authority REFUSES; it never silently becomes an '
            . 'unsigned one, because a silent downgrade would make a stale claim indistinguishable from a '
            . 'fresh one at every consumer. There is no skew tolerance in either direction: a wrong '
            . 'operator clock refuses rather than accepts, which is the safe failure and is now the '
            . 'behaviour holders depend on. The adapter root additionally refuses an IMPLAUSIBLE clock — '
            . 'one reading before the record\'s own `not_before` — BEFORE it tests expiry, because a '
            . 'backwards clock would otherwise find every retired record inside its window.',
        'reserved' => 'Expiry on the CERTIFICATE itself, as opposed to the authority that signed it, is '
            . 'still a statement member (R-06) and therefore a new format, not a field. A future skew '
            . 'allowance would have to be a REFUSAL widening, which no deployed verifier would apply to '
            . 'an artifact it already holds.',
    ];
    $rows[] = [
        'id' => 'R-15',
        'title' => 'Revocation is one status word per key, and nothing else',
        'now' => 'Both roots: `status` is `trusted` or `revoked`, per KEY, in the authority file. There '
            . 'is no per-certificate revocation, no serial number, no revocation list, no timestamp — '
            . 'checked by grep across all three signing files. A revoked site key still verifies inside '
            . 'an already-frozen snapshot, because frozen verification reopens no mutable site file '
            . '(AdapterCertification.php:976-999); a revoked platform key stops verifying frozen '
            . 'snapshots immediately.',
        'permanent' => 'Revoking a key revokes EVERY artifact it ever signed, retroactively and all at '
            . 'once — there is no way to revoke one certificate, and holders have no channel to learn '
            . 'that a key moved except by re-reading the root. Operators sign under per-adapter keys or '
            . 'accept that blast radius; that trade is fixed the moment a second adapter is signed under '
            . 'one key.',
        'reserved' => 'A revocation list needs a new authorities `format` (R-10). Per-certificate '
            . 'revocation needs an identifier the statement does not carry (R-06), so it is a v3 '
            . 'statement type, not an addition.',
    ];
    $rows[] = [
        'id' => 'R-16',
        'title' => 'One algorithm, no negotiation, and canonical base64',
        'now' => 'Both roots require `algorithm: ed25519` literally; key material is '
            . SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES . ' bytes, a signature is ' . SODIUM_CRYPTO_SIGN_BYTES
            . '. Base64 is checked by round-trip equality (`base64_encode(decode(x)) === x`), never '
            . 'merely by decodability.',
        'permanent' => 'The `algorithm` word is inside a signed record, so it is already covered by a '
            . 'signature that only Ed25519 can produce. A v1 agent has no second verifier to negotiate '
            . 'with, so agility could only ever be forward-looking: today\'s artifacts stay Ed25519 for '
            . 'their whole life. Round-trip base64 exists because a non-canonical encoding of the same '
            . 'key bytes is a second spelling of one identity, and the map key would not match.',
        'reserved' => 'A second algorithm is a second record shape and therefore a new authorities '
            . 'format (R-09/R-10), verified beside this one.',
    ];
    $rows[] = [
        'id' => 'R-17',
        'title' => '`id_kind` is a flat, unprefixed namespace',
        'now' => 'The engine owns exactly `' . implode('`, `', ReferenceKindGrammar::engineRefKinds())
            . '` (ref), `' . implode('`, `', ReferenceKindGrammar::engineTokenKinds())
            . '` (token) and `' . implode('`, `', ReferenceKindGrammar::engineLedgerKinds())
            . '` (ledger); every other legal value is an `id_kind` a pinned manifest declared for a table '
            . 'it owns. There is no vendor prefix and no reservation mechanism.',
        'permanent' => 'Captured state and `duo_map` rows embed the BARE kind, so a prefix rule '
            . 'introduced later would have to rewrite every token in every branch of every site — the one '
            . 'migration this product cannot perform, because the branches are the customer\'s data. Two '
            . 'adapters that pick one name collide with no arbiter.',
        'reserved' => 'A convention (a vendor-shaped name) can be recommended to authors at any time; a '
            . 'RULE cannot be introduced without invalidating existing captures.',
    ];
    $rows[] = [
        'id' => 'R-18',
        'title' => 'The `spec_version` acceptance window is exactly {N-1, N}',
        'now' => 'Measured by handing candidate integers to the shipped '
            . '`AdapterContractGrammar::validate_adapter_contract()`: this engine accepts `'
            . implode('`, `', array_map('strval', ws_spec_window())) . '` and refuses every other integer '
            . 'wholesale, naming the window. An absent or non-integer `spec_version` keeps the older '
            . 'refusal, because it is not a version and so is not outside anything. A manifest inside the '
            . 'window that declares a section this engine implements only at a HIGHER version refuses '
            . 'naming the section (`' . implode('`, `', array_keys(AdapterContractGrammar::section_min_spec()))
            . '` today).',
        'permanent' => 'The floor is DUO_SPEC_VERSION - 1 and never deeper, checked at generation time. '
            . 'Narrowing the window later refuses every adapter in the field that took it at its word, which '
            . 'is a flag day of exactly the kind the window exists to end; widening it to N-2 costs nothing '
            . 'on the day it is done and converts a staging channel with an expiry into permanent tolerance '
            . 'that no refusal, document or suite would report. So the equality is the gate, not the '
            . 'intention.',
        'reserved' => 'Closing the window is its own dated decision (spec/repo-format.md § v3.12), gated on '
            . 'no v2-declaring pinned manifests in the fleet plus at least one grammar section shipped '
            . 'post-v3 through `engine_features` with no version bump — the replacement proven before the '
            . 'thing it replaces is retired.',
    ];
    $rows[] = [
        'id' => 'R-19',
        'title' => 'Engine feature names are engine-owned, and permanent once declared',
        'now' => 'This engine implements `' . implode('`, `', AdapterContractGrammar::implemented_features())
            . '`. A manifest declares names through the top-level `engine_features` list; an engine lacking '
            . 'a listed name refuses THAT ADAPTER, naming the feature. An adapter declares a name and never '
            . 'mints one: a name nothing implements is refused as unimplemented rather than admitted as '
            . 'forward-looking.',
        'permanent' => 'A declared feature name is inside the manifest bytes '
            . '`ArtifactPolicyIdentity::manifest_rows()` folds into that adapter\'s `digest`, which every '
            . '`site.duo.json` content pin and every certificate\'s `adapter.canonical_sha256` binds. '
            . 'Renaming or re-spelling a feature therefore moves the digest of every manifest that declares '
            . 'it and invalidates their pins and certificates at once — the same irreversibility R-17 '
            . 'records for `id_kind`, reached through a different door.',
        'reserved' => 'The `/vN` suffix is the change channel: a feature whose meaning moves is a NEW name '
            . 'implemented beside the old one, never an edit of it, so a manifest that declared the old name '
            . 'keeps its bytes and its digest.',
        'title' => 'The v2 authority record, and the four decisions it fixes at once',
        'now' => '`' . AdapterCertification::AUTHORITIES_FORMAT_V2 . '` records are '
            . ws_set($sets, 'AUTHORITY_RECORD_V2_KEYS') . ', inside the envelope '
            . ws_set($sets, 'AUTHORITIES_ENVELOPE_V2_KEYS') . ' whose `signature` is '
            . ws_set($sets, 'AUTHORITIES_SIGNATURE_KEYS') . '. A key id must END in the first '
            . (string) ws_const(AdapterCertification::class, 'AUTHORITY_KEY_FINGERPRINT_LENGTH')
            . ' hex characters of `sha256(public_key)`; an `adapter_names` entry is an exact name or a '
            . '`<vendor>-*` namespace; the window is judged as R-14 states; and the envelope signature is '
            . 'made by a key the document itself carries. `record_version: 2` restates the envelope '
            . 'format inside every record, and a disagreement between the two refuses. v1 records and v1 '
            . 'documents keep today\'s behaviour byte for byte — the four rules read only a record that '
            . 'declared `record_version`.',
        'permanent' => 'All four land together because they are one document: a holder who accepts a v2 '
            . 'record accepts all of them, and shipping any one later would be a second flag day for '
            . 'whoever already holds a v2 file. The fingerprint rule cannot be relaxed afterwards without '
            . 'admitting ids that were unrepresentable when the trust decision was made; it cannot be '
            . 'tightened (a longer fingerprint) without orphaning every id already issued. The window is '
            . 'mandatory because `assertExactKeys()` refuses missing and unknown alike, so an optional '
            . 'member has no honest home in the set — a holder wanting no expiry stays at v1, where there '
            . 'is none. An EMPTY v2 registry is unrepresentable by construction: the envelope signature '
            . 'names a key inside the document, so a registry with no keys has nothing that could sign '
            . 'it. That is what lets the shipped empty root stay v1 and byte-identical.',
        'reserved' => 'The envelope signature proves the document was assembled WHOLE by a holder of a '
            . 'key it carries — nobody else can append a key, widen a scope list, move a window or flip a '
            . 'status in it. It is deliberately NOT a chain to an off-document root: delegation is its '
            . 'own signed statement type with its own domain (spec/repo-format.md § v3.8), never a member '
            . 'or an arm inside this one.',
    ];

    return $rows;
}

/**
 * The projected closed key sets, indexed by the label the engine gives them.
 *
 * @return array<string,list<string>>
 */
function ws_indexed_key_sets(): array {
    static $index = null;
    if ($index !== null) {
        return $index;
    }
    $index = [];
    foreach (ws_all_key_sets() as $row) {
        // Three handles, because none alone is unique: several call sites label
        // their document with the same `$label` variable, one function can
        // decide more than one key set (a v1 and a v2 grammar in the same
        // reader), and only the CONSTANT tells those two apart. First-wins per
        // handle, so a function handle names its first set in source order —
        // which is why the v1 arm is written first at both branching sites.
        foreach ([$row['label'], $row['function'], $row['constant']] as $handle) {
            if ($handle === '') {
                continue;
            }
            if (!isset($index[$handle])) {
                $index[$handle] = $row['keys'];
            }
        }
    }

    return $index;
}

/** @param array<string,list<string>> $sets */
function ws_set(array $sets, string $label): string {
    if (!isset($sets[$label])) {
        ws_fail("the closed key set '$label' is no longer declared; the register row that cites it is stale");
    }

    return '`{' . implode(', ', $sets[$label]) . '}`';
}

/**
 * Every closed key set the three signed surfaces reach, in source order.
 *
 * @return list<array{file:string,function:string,label:string,keys:list<string>,optional:list<string>}>
 */
function ws_all_key_sets(): array {
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    // The checkout being projected, which `--root` may point elsewhere: the
    // register rows reach this through ws_set() with no argument to thread it
    // through, and the whole file already runs against exactly one root.
    global $repo;
    $rows = [];
    $sources = [
        ['agent/src/Adapter/AdapterCertification.php', ['assertExactKeys'], AdapterCertification::class, null],
        ['recovery/rollback-control.php', ['assertExactKeys'], RollbackControl::class, null],
        ['cli/src/Contract/ApplicationContract.php', ['closedKeys'], ApplicationContract::class, 'validateAttestation'],
    ];
    foreach ($sources as [$relative, $callees, $class, $only]) {
        foreach (ws_key_sets($repo . '/' . $relative, $callees, $class, $only) as $row) {
            $rows[] = ['file' => $relative] + $row;
        }
    }

    return $rows;
}

function ws_build(string $repo): string {
    ws_assert_signing_files($repo);
    ws_assert_reserved_absences($repo);
    ws_assert_rollback_is_domain_free();
    ws_assert_spec_window();
    ws_assert_shipped_authorities($repo);
    $domains = [
        (string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN'),
        (string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN_AUTHORITIES'),
        (string) ws_const(ContractAttestation::class, 'SIGNATURE_DOMAIN'),
    ];
    foreach ($domains as $domain) {
        if (!str_ends_with($domain, "\0")) {
            ws_fail('a signature domain no longer ends in NUL; row R-04 records the terminator as signed data');
        }
    }
    foreach ($domains as $one) {
        foreach ($domains as $other) {
            if ($one !== $other && str_starts_with($other, rtrim($one, "\0"))) {
                ws_fail('one signature domain is a prefix of another; row R-04 exists to forbid exactly that');
            }
        }
    }
    ws_assert_domain_literals($repo, $domains);

    $out = "# The irreversibility register\n\n";
    $out .= "**Generated — never hand-edited.** Written by `tools/wire-surface.php` from the shipped\n";
    $out .= "constants, the shipped closed-key-set call sites, and the shipped refusals themselves;\n";
    $out .= "`make release-gate` runs `php tools/wire-surface.php --check` and byte-compares it. Edit the\n";
    $out .= "code, then run `php tools/wire-surface.php generate`.\n\n";
    $out .= "A signature is a promise about bytes. The moment one certificate, one attestation or one\n";
    $out .= "rollback receipt leaves this repository, every decision inside the bytes it covers is fixed\n";
    $out .= "for as long as anyone holds it — not until the next agent version, and not until the next\n";
    $out .= "release. This register is the list of those decisions, each with the shipped value, what a\n";
    $out .= "change would cost a party that already holds an artifact, and what the decision deliberately\n";
    $out .= "reserves for a future format.\n\n";
    $out .= "Nothing here is a second copy of the engine. Every value is read out of it: constants by\n";
    $out .= "reflection (private ones included), closed key sets from the `assertExactKeys()` and\n";
    $out .= "`closedKeys()` call sites that decide them, and grammars, statuses and signature framing by\n";
    $out .= "running the shipped refusals and printing what they answer. §5 lists what that mechanically\n";
    $out .= "proves, and what it does not.\n\n";

    $out .= "## 1. The cross-root replay table\n\n";
    $out .= "Every signature this product mints, its domain, and the exact bytes it covers. Read it as\n";
    $out .= "the answer to one question: can a statement minted for one surface be made to verify as a\n";
    $out .= "statement of another?\n\n";
    $out .= "| surface | domain prefix | signed bytes | source |\n|---|---|---|---|\n";
    foreach (ws_signature_inputs() as $row) {
        $out .= "| {$row['surface']} | {$row['domain']} | {$row['input']} | {$row['source']} |\n";
    }
    $out .= "\nThe first three are separated by construction, and the checker refuses the run if any one of\n";
    $out .= "them is a prefix of another. The last is not separated at all, and R-03 is where that decision\n";
    $out .= "and its cost are written down.\n\n";

    $out .= "## 2. The register\n\n";
    foreach (ws_rows() as $row) {
        $out .= "### {$row['id']} — {$row['title']}\n\n";
        $out .= "**Shipped now.** {$row['now']}\n\n";
        $out .= "**Why it cannot change.** {$row['permanent']}\n\n";
        $out .= "**Reserved.** {$row['reserved']}\n\n";
    }

    $out .= "## 3. The grammars, as the shipped validators answer them\n\n";
    $out .= "### 3.1 Key ids, three roots\n\n";
    $out .= "Each cell is the verdict of the named validator on that exact probe, obtained by calling it.\n\n";
    $out .= "| probe | adapter root | contract root | recovery root |\n|---|---|---|---|\n";
    foreach (ws_key_id_matrix()['rows'] as $row) {
        $out .= "| {$row['probe']} | {$row['adapter']} | {$row['contract']} | {$row['rollback']} |\n";
    }
    $out .= "\n### 3.2 The contract authority record, derived from its own refusals\n\n";
    $out .= "`ContractAttestation::validateRecord()` keeps its vocabulary in a local variable, so the\n";
    $out .= "closed set is derived the only honest way: by asking it.\n\n";
    $out .= "| record | verdict | refusal |\n|---|---|---|\n";
    foreach (ws_contract_record_probes() as $row) {
        $out .= "| {$row['variation']} | {$row['verdict']} | {$row['refusal']} |\n";
    }

    $out .= "\n## 4. Every closed key set the signing runtimes decide\n\n";
    $out .= "Projected from the call sites that decide them: the three signing files, plus the contract\n";
    $out .= "attestation object itself. Not every row is inside a signature — the recovery runtime's\n";
    $out .= "request envelope and target record are store shapes — but every one of them refuses a\n";
    $out .= "MISSING key and an UNKNOWN key with the same failure, so each row is a set that cannot gain a\n";
    $out .= "member for anyone holding today's agent. A row appearing or moving here is the alarm: a wire\n";
    $out .= "surface changed, and the byte-compare in `make release-gate` will not pass until someone\n";
    $out .= "regenerates this document and, in doing so, reads the change.\n\n";
    $out .= "| file | validator | document | keys |\n|---|---|---|---|\n";
    foreach (ws_all_key_sets() as $row) {
        $keys = '`' . implode('`, `', $row['keys']) . '`';
        if ($row['optional'] !== []) {
            $keys .= ' — plus, only when signed: `' . implode('`, `', $row['optional']) . '`';
        }
        $document = '`' . $row['label'] . '`'
            . ($row['constant'] === '' ? '' : ' (`' . $row['constant'] . '`)');
        $out .= "| `{$row['file']}` | `{$row['function']}()` | $document | $keys |\n";
    }

    $out .= "\n## 5. What the checker proves, and what it does not\n\n";
    $out .= "`php tools/wire-surface.php --check` proves six things and refuses the run rather than\n";
    $out .= "printing a register it cannot stand behind:\n\n";
    $out .= "1. **Every value above is the shipped value.** The document is rebuilt from the code and\n";
    $out .= "   byte-compared; a moved constant, a renamed key, a widened grammar or a reworded refusal\n";
    $out .= "   fails with the first differing line named.\n";
    $out .= "2. **No signed surface is missing.** Every `sodium_crypto_sign_detached()` call site in\n";
    $out .= '   `agent/`, `cli/` and `recovery/` is in a file this register covers — '
        . count(WS_SIGNING_FILES) . " today.\n";
    $out .= "3. **No unregistered domain exists.** Every `duo-…-signature/vN` literal in those trees is\n";
    $out .= "   one of the domains in §1, and none is a prefix of another.\n";
    $out .= "4. **The bounded vocabularies are still bounded.** `AdapterCertification`'s expiry\n";
    $out .= "   vocabulary is exactly `not_after`/`not_before` judged against `\$now ?? time()`, and no\n";
    $out .= "   signing file carries revocation-list vocabulary (R-14, R-15).\n";
    $out .= "5. **The rollback signature really is domain-free.** A signature is minted and verified\n";
    $out .= "   against the unprefixed canonical payload at generation time (R-03).\n";
    $out .= "6. **The spec-version window has not accumulated.** The shipped validator is probed over\n";
    $out .= '   N-3 … N+2 and must accept exactly {N-1, N} — floor `DUO_SPEC_VERSION - 1`, never deeper'
        . " (R-18).\n\n";
    $out .= "6. **The shipped platform trust root is one of its two legal states.** It is the empty\n";
    $out .= '   `' . AdapterCertification::AUTHORITIES_FORMAT . "` registry byte for byte, or a\n";
    $out .= '   `' . AdapterCertification::AUTHORITIES_FORMAT_V2 . "` document that VERIFIES through the\n";
    $out .= "   shipped reader — envelope signature, fingerprint-bound ids, windows and namespaces all\n";
    $out .= "   checked by the code a site runs (R-08, R-18).\n\n";
    $out .= "What it does not prove: that the decisions are *right*, that any artifact in the field was\n";
    $out .= "signed under these exact rules, or that a holder's verifier implements them. The rationale\n";
    $out .= "halves of §2 are prose, reviewed by a human, and the register is only as good as the review\n";
    $out .= "that put them there. What the checker guarantees is narrower and is the thing that rots\n";
    $out .= "first: the register cannot go quietly out of date.\n";

    return $out;
}

function ws_run(string $repo, bool $check): void {
    $path = $repo . '/docs/wire-surface.md';
    $expected = ws_build($repo);
    if ($check) {
        $actual = is_file($path) ? (string) file_get_contents($path) : '';
        if ($actual !== $expected) {
            $expectedLines = explode("\n", $expected);
            $actualLines = explode("\n", $actual);
            $line = 0;
            while (($expectedLines[$line] ?? null) === ($actualLines[$line] ?? null)
                && $line < max(count($expectedLines), count($actualLines))) {
                $line++;
            }
            fwrite(STDERR, 'wire-surface: docs/wire-surface.md disagrees with the shipped wire at line '
                . ($line + 1) . "\n");
            fwrite(STDERR, '  committed: ' . ($actualLines[$line] ?? '(end of file)') . "\n");
            fwrite(STDERR, '  shipped:   ' . ($expectedLines[$line] ?? '(end of file)') . "\n");
            ws_fail('run `php tools/wire-surface.php generate` after reading what moved — a row here is a '
                . 'decision an external party may already hold');
        }
        fwrite(STDOUT, "wire surface check: docs/wire-surface.md agrees with the shipped constants\n");

        return;
    }
    if (file_put_contents($path, $expected) === false) {
        ws_fail('could not write docs/wire-surface.md');
    }
    fwrite(STDOUT, "generated docs/wire-surface.md\n");
}

$wsCommand = '--check';
foreach (array_slice($wsArgv, 1) as $wsArg) {
    if (!str_starts_with($wsArg, '--root=')) {
        $wsCommand = $wsArg;
    }
}
try {
    if ($wsCommand === 'generate') {
        ws_run($repo, false);
    } elseif ($wsCommand === '--check' || $wsCommand === 'check') {
        ws_run($repo, true);
    } else {
        throw new RuntimeException('usage: php tools/wire-surface.php [generate|--check] [--root=DIR]');
    }
} catch (Throwable $e) {
    ws_fail($e->getMessage());
}
