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
 * agent version — `AdapterCertification::SIGNATURE_DOMAIN` (:174) is "kept
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
 * the engine. Everything factual beside them is projected, and the
 * the engine. Everything factual beside them is projected, and seven
 * completeness gates below refuse the whole run rather than emit a register
 * that has gone quiet about a surface:
 *
 *   1. every `sodium_crypto_sign_detached()` call site in agent/, cli/ and
 *      recovery/ is in a file this register covers;
 *   2. every `duo-…-signature/vN` literal in those trees is one of the
 *      projected domain constants;
 *   3. `AdapterCertification` still has no expiry vocabulary at all (row
 *      R-14 says so, and says what it would cost to add);
 *   4. the typed revocation vocabulary is exactly `{effective_at, fingerprint,
 *      key_id, reason}`, lives in exactly one signing file, and `revoked_at`
 *      appears nowhere (rows R-15 and R-26);
 *   5. the shipped `spec_version` acceptance window is exactly {N-1, N} — the
 *      floor is `DUO_SPEC_VERSION - 1` and never deeper (row R-18), measured
 *      by probing the shipped validator rather than by reading its condition.
 *   6. the shipped platform trust root is either the empty v1 registry byte for
 *      byte or a v2 document that VERIFIES through the shipped reader (row
 *      R-20), so an unsigned or tampered root never ships.
 *   7. the validator's admitted top-level key set and the signer's partition
 *      are the SAME SET, in both directions, and the only excess is what an
 *      implemented `engine_features` value claims (row R-21). § v3.3's "one
 *      definition, not two" is a property a copy satisfies on the day it is
 *      typed, so it is asserted rather than reviewed.
 *      by probing the shipped validator rather than by reading its condition;
 *   6. the shipped platform trust root is the empty v1 registry byte for byte,
 *      or a v2 document that verifies through the shipped reader (row R-08);
 *   7. the § v3.9 grandfather list is declared under `agent/src` — never under
 *      `manifests/`, where rule 2 would make it an adapter-digest input — and
 *      its membership equals the shipped library exactly (row R-27).
 *
 * A new signed surface therefore cannot be added quietly: it fails gate 1 or 2
 * until it is registered, and any moved constant fails the byte-compare with
 * the first differing line named.
 *
 * Four rows here are not about a signature (R-17, R-18, R-19, R-21). They are
 * in the register because it is the list of decisions an external party's bytes
 * make permanent, and a bare `id_kind`, an accepted `spec_version`, a declared
 * engine feature name and a recognised top-level manifest key are each inside
 * bytes this product cannot rewrite afterwards — captured state and `duo_map`
 * rows for the first, every adapter in the field authored against the window
 * for the second, and the manifest bytes an adapter digest folds for the last
 * two.
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
require_once $repo . '/agent/src/Adapter/IdentityNamespaces.php';
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
use Duo\IdentityNamespaces;
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

/**
 * A projected small count, spelled the way the surrounding prose spells one.
 *
 * The count itself is read out of the engine (WP-4.7 moved the signed statement
 * from five members to six), so this exists only so the sentence does not have
 * to choose between being projected and being readable.
 */
function ws_spelled(int $count): string {
    return [
        1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
        6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
    ][$count] ?? (string) $count;
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
    // R-15's absence became a BOUNDED PRESENCE with WP-4.9, the same ratchet
    // change WP-4.8 made to R-14's half above. Revocation is no longer only a
    // `status` word: § v3.8 adds a typed, platform-signed revocation document,
    // and R-26 records exactly what it may say. So the grep is now "the typed
    // vocabulary appears in exactly one file, and is exactly these members" —
    // a fifth member, or the same vocabulary appearing in a second signing
    // file, fails the gate rather than quietly making R-15/R-26's sentences
    // wrong. `revoked_at` stays forbidden everywhere: the entry member is
    // `effective_at`, and two spellings of one instant is the drift this file
    // exists to refuse.
    foreach (WS_SIGNING_FILES as $relative) {
        $source = (string) file_get_contents($repo . '/' . $relative);
        if (preg_match('/revocation_list|revoked_at|\bcrl\b/i', $source) === 1) {
            ws_fail(
                "$relative now carries revocation-list vocabulary; rows R-15/R-26 record the typed revocation "
                . 'entry as exactly {effective_at, fingerprint, key_id, reason} and nothing else'
            );
        }
        $typed = preg_match('/REVOCATION_ENTRY_KEYS|SIGNATURE_DOMAIN_REVOCATION/', $source) === 1;
        if ($typed && $relative !== 'agent/src/Adapter/AdapterCertification.php') {
            ws_fail(
                "$relative now carries typed-revocation vocabulary; row R-26 records it as living in exactly "
                . 'one signing file, so a second copy of the grammar is refused before it can drift'
            );
        }
    }
    $entryKeys = ws_const(AdapterCertification::class, 'REVOCATION_ENTRY_KEYS');
    if ($entryKeys !== ['effective_at', 'fingerprint', 'key_id', 'reason']) {
        ws_fail(
            'the typed revocation entry is now {' . implode(', ', (array) $entryKeys) . '}; row R-26 records it '
            . 'as exactly {effective_at, fingerprint, key_id, reason} and must be rewritten before that ships'
        );
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
 * Gate 7 (WP-4.3, spec § v3.3): ONE closed top-level key set, not two.
 *
 * § v3.3's load-bearing sentence is "the set has one definition, not two": the
 * validator refuses an unrecognised top-level key at `spec_version: 3` and the
 * signer refuses one it cannot classify at any version, and those two must be
 * refusing over the SAME SET. The failure this gate is written against is not a
 * disagreement anyone would introduce deliberately — it is the copy. A list
 * typed into the validator equals the signer's partition on the day it is
 * typed; it stops equalling it the day one arm grows, which is the day nobody
 * is looking, and the symptom is a manifest that loads on every site and cannot
 * be certified (or, worse, one that certifies and then refuses to load).
 * `regress_spec_v3_dry_run.php` measured that exact shape before the rule
 * shipped: `theme_version_range` was mandatory in the grammar and in no arm of
 * the partition, so a theme adapter validated `[ok]` and was unsignable.
 *
 * Asserted in BOTH directions and against the shipped accessor, so neither side
 * can quietly hold a key the other does not. The feature arm is asserted too:
 * § v3.2's growth rule is the only way the admitted set may exceed the
 * partition, so a manifest declaring an implemented feature must admit exactly
 * the partition plus that feature's claimed keys and nothing else.
 */
function ws_assert_closed_key_set(): void {
    $partition = AdapterCertification::topLevelKeyPartition();
    $arms = array_merge(
        $partition['entity_sections'],
        $partition['field_sections'],
        $partition['non_surface_keys']
    );
    sort($arms, SORT_STRING);
    if (count($arms) !== count(array_unique($arms))) {
        ws_fail(
            'the signer partition\'s three arms overlap; row R-21 records them as disjoint, because a key in '
            . 'two arms would make what a derived ratification says about it depend on iteration order'
        );
    }

    // No `engine_features` declared, so this is the BASE set — the one that has
    // to be the partition exactly.
    $admitted = AdapterContractGrammar::admitted_top_level_keys([]);
    if ($admitted !== $arms) {
        $onlyValidator = array_values(array_diff($admitted, $arms));
        $onlySigner = array_values(array_diff($arms, $admitted));
        ws_fail(
            'the validator\'s admitted top-level key set and the signer\'s partition are not the same set — '
            . 'admitted-but-unclassifiable {' . implode(', ', $onlyValidator) . '}, classifiable-but-refused {'
            . implode(', ', $onlySigner) . '}. Row R-21 records ONE definition (spec/repo-format.md § v3.3): '
            . 'both readers must resolve AdapterCertification::topLevelKeyPartition(), never a second list'
        );
    }

    foreach (AdapterContractGrammar::implemented_features() as $feature) {
        $claimed = AdapterContractGrammar::admitted_feature_keys(['engine_features' => [$feature]]);
        $expected = array_values(array_unique(array_merge($arms, $claimed)));
        sort($expected, SORT_STRING);
        $withFeature = AdapterContractGrammar::admitted_top_level_keys(['engine_features' => [$feature]]);
        if ($withFeature !== $expected) {
            ws_fail(
                "declaring the implemented engine feature '$feature' admits {" . implode(', ', $withFeature)
                . '} rather than the partition plus its claimed keys {' . implode(', ', $expected)
                . '} — row R-21 records § v3.2\'s channel as the ONLY way the admitted set exceeds the partition'
            );
        }
    }
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

/**
 * The shipped library's own identities, read off `manifests/*.json`.
 *
 * `dispositions.json` is the reviewed claim source, not an adapter, and the
 * `capabilities/` documents live one directory down, so the flat glob is
 * already exactly the adapter set. The declared `name` is preferred over the
 * basename only to say out loud that they are the same value —
 * `AdapterSources::assert_declared_name()` refuses a manifest where they
 * disagree, so a disagreement here would be an engine bug, not a data one.
 *
 * @return array{names:list<string>, id_kinds:list<string>}
 */
function ws_shipped_identities(string $repo): array {
    $names = [];
    $kinds = [];
    foreach (glob($repo . '/manifests/*.json') ?: [] as $file) {
        $base = basename($file, '.json');
        if ($base === 'dispositions') {
            continue;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            ws_fail("manifests/$base.json does not decode as an object; row R-27 enumerates it");
        }
        $names[] = is_string($decoded['name'] ?? null) ? (string) $decoded['name'] : $base;
        foreach ((array) ($decoded['tables'] ?? []) as $table) {
            if (is_array($table) && is_string($table['id_kind'] ?? null)) {
                $kinds[$table['id_kind']] = true;
            }
        }
    }
    $kinds = array_map('strval', array_keys($kinds));
    sort($names, SORT_STRING);
    sort($kinds, SORT_STRING);

    return ['names' => array_values(array_unique($names)), 'id_kinds' => $kinds];
}

/**
 * Gate 7 (WP-4.10, spec § v3.9): the closed grandfather list, in its place and
 * with its exact membership.
 *
 * Two halves, and the LOCATION half is the one that is easy to lose. A
 * reserved-name list under `manifests/` would be folded into every adapter's
 * `digest` by `ArtifactPolicyIdentity::manifest_rows()` (AGENTS.md rule 2), so
 * adding the seventeenth shipped adapter would invalidate every pin and every
 * certificate in the fleet for the other sixteen. In `agent/src` it moves no
 * digest at all. The check is reflection over the class rather than a path
 * literal, because a path literal is a claim about where a file was, not about
 * where the constants a refusal reads actually live.
 *
 * The MEMBERSHIP half is what makes the list CLOSED rather than merely
 * present: it must equal the shipped library exactly, in both directions. A
 * seventeenth adapter name — prefixed or not — therefore cannot enter the
 * library without a reviewed edit to the enumerated list, which is the whole
 * property § v3.9 claims and the reason the list enumerates instead of testing
 * shape (`the-events-calendar` is hyphen-shaped and is not vendor `the`).
 */
function ws_assert_grandfather_list(string $repo): void {
    // Both sides through realpath(): `--root=` accepts a relative directory
    // (tests/Tooling/WireSurfaceTest.php passes an absolute one, the offline
    // suite a repo-relative one), and reflection always answers absolute, so a
    // raw prefix compare would report "outside agent/src" for a tree that is
    // inside it.
    $file = (new ReflectionClass(IdentityNamespaces::class))->getFileName();
    $file = is_string($file) ? (realpath($file) ?: $file) : null;
    $agentSrc = realpath(rtrim($repo, '/') . '/agent/src');
    $agentSrc = $agentSrc === false ? rtrim($repo, '/') . '/agent/src' : $agentSrc;
    if (!is_string($file) || !str_starts_with($file, $agentSrc . '/')) {
        ws_fail(
            'the § v3.9 grandfather list is declared in ' . var_export($file, true) . ', outside agent/src — '
            . 'row R-27 records that it lives in agent code precisely so it is not a manifest byte, which '
            . 'AGENTS.md rule 2 would fold into every adapter digest'
        );
    }
    $shipped = ws_shipped_identities($repo);
    $pairs = [
        'adapter names' => [IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES, $shipped['names']],
        'id_kinds' => [IdentityNamespaces::GRANDFATHERED_ID_KINDS, $shipped['id_kinds']],
    ];
    foreach ($pairs as $space => [$listed, $actual]) {
        if ($listed === $actual) {
            continue;
        }
        $added = array_values(array_diff($actual, $listed));
        $dropped = array_values(array_diff($listed, $actual));
        ws_fail(
            "the § v3.9 grandfather list of $space disagrees with the shipped library: "
            . ($added === [] ? 'nothing unlisted' : 'unlisted [' . implode(', ', $added) . ']')
            . ', ' . ($dropped === [] ? 'nothing stale' : 'stale [' . implode(', ', $dropped) . ']')
            . ($added === [] && $dropped === [] ? ', and the two are only out of sort order' : '')
            . ' — the list is CLOSED (row R-27): edit '
            . 'agent/src/Adapter/IdentityNamespaces.php in review, which is the reviewed act that admitting a '
            . 'new unprefixed identity is meant to be'
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
    // The member count is PROJECTED, not written: WP-4.7 grew the statement
    // from five members to six, and a hand-written number here is exactly the
    // kind of quiet staleness §5 claims this document cannot have.
    $statementKeys = ws_key_list('self::STATEMENT_KEYS', AdapterCertification::class, __FILE__);
    $rows[] = [
        'surface' => 'site adapter certification (`' . AdapterCertification::FORMAT . '`)',
        'domain' => '`' . ws_bytes($adapterDomain) . '`',
        'input' => 'domain &#124;&#124; `Canon::encode(statement)` — the whole '
            . ws_spelled(count($statementKeys)) . '-member statement',
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

    // WP-4.9's two statement kinds, proved the same way as the three above: the
    // shipped framer is called and its domain is stripped, so the table cannot
    // describe framing the engine does not perform.
    foreach ([
        ['SIGNATURE_DOMAIN_DELEGATION', 'delegationSignatureBytes', AdapterCertification::DELEGATIONS_FORMAT,
            'one delegation statement — the grant, both key identities and the window'],
        ['SIGNATURE_DOMAIN_REVOCATION', 'revocationSignatureBytes', AdapterCertification::REVOCATIONS_FORMAT,
            'the whole revocation statement — every entry at once, so no row can be dropped'],
    ] as [$constant, $framer, $format, $what]) {
        $domain = (string) ws_const(AdapterCertification::class, $constant);
        $probe = (object) ['probe' => 'wire-surface'];
        $input = (string) ws_probe_value(AdapterCertification::class, $framer, [$probe]);
        if (!str_starts_with($input, $domain) || substr($input, strlen($domain)) !== Canon::encode($probe)) {
            ws_fail("the $format signature input is no longer domain . Canon::encode(statement)");
        }
        $rows[] = [
            'surface' => '`' . $format . '`',
            'domain' => '`' . ws_bytes($domain) . '`',
            'input' => 'domain &#124;&#124; `Canon::encode(statement)` — ' . $what,
            'source' => '`AdapterCertification::' . $constant . '`',
        ];
    }

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
            . 'elsewhere" (AdapterCertification.php:161).',
        'reserved' => 'The `/vN` suffix is the whole change channel, and WP-4.7 spent it once: `/v2` is a '
            . 'NEW statement type verified alongside `/v1`, never an edit of it. A certificate says which '
            . 'generation it is by the bytes it was signed over — and, because a signature cannot be '
            . 'checked until the domain is chosen, by the statement MEMBER SET this agent reads first '
            . '(R-24). A third domain works the same way.',
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
        'permanent' => 'Same closure as R-05, and covered by the signature: a seventh member changes the '
            . 'signed bytes AND is refused by every deployed verifier. This is the row that makes every '
            . 'other adapter-certification decision permanent, because none of them can be revisited '
            . 'without adding or moving a member here — which is exactly what WP-4.7 had to do, and why '
            . 'it could only be done in the same change that moved the domain (R-01, R-24). The v1 '
            . 'five-member set (`adapter`, `authority`, `bundle`, `platform`, `ratification`) is still '
            . 'read, for one purpose: it is how a v1-generation statement is RECOGNISED, before any '
            . 'signature, so it can be withdrawn by name instead of refused as corruption.',
        'reserved' => 'Nothing, again, and WP-4.11 kept it that way on purpose: the reserved slots '
            . 'spec/repo-format.md § v3.10 names (`code_digest`, `delegated_authority`) are REFUSALS '
            . 'that name the member and its gate, never admitted members, so this set is the same six '
            . 'and every statement already signed keeps its exact bytes (R-28). Admitting either is '
            . 'still a wire generation. Facts that need signing meanwhile go inside an existing member '
            . '— `bundle` and `ratification` are whole objects the signature already covers.',
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
            . '. Both trust roots bind the key IDENTITY — everything but `adapter_names`, `trust_tiers` and '
            . '(at record v2) `not_before`/`not_after` — self-consistently by digest, and re-check all four '
            . 'LIVE against the current record, revocation with them. The WINDOW left the binding in '
            . 'G2-FIXES M1: a window is a scope, and with it inside the identity a v2 key had NO renewal '
            . 'path — extending `not_after` invalidated every certificate ever signed under that key, and '
            . 'not extending it expired them.',
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
            . 'are either inside the signature or enforced live, and doing both is the first option. The '
            . 'same one-way rule is why dropping the window was decidable NOW and never again: a '
            . 'NARROWING admits certificates the wider binding refused, so it may only be made while the '
            . 'root has signed nothing — and this one has not.',
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
            . 'backwards clock would otherwise find every retired record inside its window; G2-FIXES C1 '
            . 'gave the typed revocation channel the same test against its own `issued_at`, where the '
            . 'failure was OPEN (a backwards clock read an in-force revocation as merely scheduled). '
            . 'WHAT NO CLOCK TEST CAN CLOSE, recorded rather than left to be discovered: a host clock set '
            . 'INSIDE a lapsed window resurrects what that window retired, because both tests read the '
            . 'same wall clock and no clock can witness its own wrongness. Closing it needs a monotonic '
            . 'anchor this product does not have — a signed time beacon, or persisted state the agent '
            . 'refuses to move backwards — and both are new permanent decisions rather than fixes. What '
            . 'IS bounded is the blast radius: since G2-FIXES C3 an expired authority WITHDRAWS the '
            . 'adapters it certified to uncertified instead of refusing the whole site source.',
        'reserved' => 'Expiry on the CERTIFICATE itself, as opposed to the authority that signed it, is '
            . 'still a statement member (R-06) and therefore a new format, not a field. A future skew '
            . 'allowance would have to be a REFUSAL widening, which no deployed verifier would apply to '
            . 'an artifact it already holds.',
    ];
    $rows[] = [
        'id' => 'R-15',
        'title' => 'Revocation is per KEY, never per certificate, on both of its two channels',
        'now' => 'Channel 1, in the authority file: `status` is `trusted` or `revoked`, per key, in a '
            . 'document that ships inside the agent archive. Channel 2, added by WP-4.9 and detailed in '
            . 'R-26: a platform-signed `' . AdapterCertification::REVOCATIONS_FORMAT . '` document '
            . 'installed out of band. Neither has a serial number and neither can revoke ONE certificate '
            . '— every entry names key material. A revoked site key still verifies inside an '
            . 'already-frozen snapshot through channel 1, because frozen verification reopens no mutable '
            . 'site file (`verifyCertificate()`\'s site branch, AdapterCertification.php:1338-1379); '
            . 'channel 2 and a revoked platform key both DO reach a frozen snapshot. WHAT DOES NOT REACH '
            . 'ONE, recorded here because it looks like it should: revoking a DELEGATOR. A frozen '
            . 'snapshot holds no repository, so it reads no `adapters/delegations.json` '
            . '(`delegatedKeys()` returns `[]` without one) and the delegate\'s record is the one inside '
            . 'the signature. Burning a vendor\'s grant therefore reaches every LIVE scan at once and no '
            . 'promoted site: an incident response must name the DELEGATE\'s own fingerprint to reach '
            . 'those, and the operator guide says so in the same words.',
        'permanent' => 'Revoking a key revokes EVERY artifact it ever signed, retroactively and all at '
            . 'once — there is no way to revoke one certificate, on either channel. Operators sign under '
            . 'per-adapter keys or accept that blast radius; that trade is fixed the moment a second '
            . 'adapter is signed under one key. What WP-4.9 changed is REACHABILITY, not granularity: '
            . 'holders now have a channel that does not wait for an agent release and that a frozen '
            . 'snapshot can read. Going back — removing channel 2 — would silently restore the frozen-path '
            . 'gap for every vendor key already federated by copy.',
        'reserved' => 'Per-certificate revocation still needs an identifier the statement does not carry '
            . '(R-06), so it remains a v3 statement type rather than an addition. A third channel is not '
            . 'reserved: two are already the maximum an operator can reason about, and R-26 states which '
            . 'one answers where.',
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
    ];
    // WP-4.8's rider authored this row as R-18 in its own worktree, unaware
    // WP-4.2 had claimed R-18/R-19 in a sibling; the integrator renumbered it
    // R-20 at merge. Register ids are ordinal bookkeeping, not signed wire —
    // nothing on disk or in a certificate embeds them — so the renumber moves
    // no identity; the generated document and its suites follow this constant.
    $rows[] = [
        'id' => 'R-20',
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
    // R-21 and not R-18: tranche 1 had two riders mint R-18 in parallel
    // worktrees and the integrator renumbered (see the R-20 note above), so
    // ids through R-20 are spent. Nothing on disk embeds a register id.
    $rows[] = [
        'id' => 'R-21',
        'title' => 'The top-level manifest key set is closed at `spec_version: 3`, from one definition',
        'now' => 'A `spec_version: 3` manifest may declare '
            . (string) count(AdapterContractGrammar::admitted_top_level_keys([]))
            . ' top-level keys — the signer\'s three-arm partition, '
            . ws_partition_text() . ' — plus whatever keys its own declared, IMPLEMENTED `engine_features` '
            . 'values claim (`engine_features` itself, via `'
            . implode('`, `', ws_features_claiming('engine_features'))
            . '`). A key in none of those refuses at load BY NAME, and `_draft` — the sidecar '
            . '`duo adapter-draft` writes — refuses with its own remedy, to strip it. v2 manifests keep the '
            . 'open behaviour byte for byte, so none of the shipped library changes. Measured here by asking '
            . 'the shipped validator and the shipped signer for their sets and comparing them in both '
            . 'directions.',
        'permanent' => 'A key REMOVED from the set later refuses every manifest in the field that declared '
            . 'it, and takes its adapter digest with it: the key is inside the manifest bytes '
            . '`ArtifactPolicyIdentity::manifest_rows()` folds, so the remedy is an edit that moves every '
            . '`site.duo.json` content pin and invalidates every certificate over that adapter (R-19 records '
            . 'the same irreversibility for a feature name). Closing the set is therefore a one-way door: it '
            . 'can be opened wider through the growth rule and can never be narrowed. The one definition is '
            . 'load-bearing for the same reason — two lists that agree today diverge silently, and the '
            . 'symptom is an adapter that loads everywhere and cannot be certified.',
        'reserved' => 'Growth is § v3.2\'s channel and nothing else: a post-v3 primitive ships as an engine '
            . 'feature name, the top-level keys that feature claims, and a refusal for the engine that lacks '
            . 'it — so no key is ever added by widening this set for everyone. A feature whose key must also '
            . 'be SIGNABLE gives it an arm in the partition in the same change, because a feature record '
            . 'carries `{since, keys}` and no arm, and an arm is what decides whether a certificate covers '
            . 'the key as a surface.',
    ];
    // R-22 was minted and never spent: a tranche-1 rider held it in its
    // disjoint range and shipped nothing that needed a row, so the register
    // printed R-21 followed by R-23. G2-FIXES M4 allocates it rather than
    // leaving a hole, and ws_assert_row_continuity() below now refuses a
    // register with a gap in it — an id nobody can account for reads as a row
    // somebody deleted.
    $rows[] = [
        'id' => 'R-22',
        'title' => 'The shipped library wins the adapter-NAME namespace, against a pattern',
        'now' => 'A non-platform authority record\'s `adapter_names` entry may not be a `<vendor>-*` '
            . 'namespace that COVERS one of the '
            . count(IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES) . ' names the shipped library '
            . 'reserves (R-27\'s list). Judged at authority-record validation time, so it fires on both '
            . 'roots\' registration and verification paths — the site trust root, a delegated grant, and '
            . 'the record embedded in a certificate the frozen path re-validates. The refusal names the '
            . 'covered member. The platform root is exempt, and an EXACT reserved name stays legal '
            . 'everywhere: out of tree a shipped name is reachable only as the reviewed '
            . '`{name, source:"site"}` override (T6 §3.3), which `duo adapter certify` records by exact '
            . 'name.',
        'permanent' => 'Without it, enrolling a vendor with the namespace its own products live in '
            . 'silently handed that vendor the SHIPPED adapter of the same name — 10 of the '
            . count(IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES) . ' sit inside a legal one '
            . '(`ninja-*`, `yoast-*`, `duo-*`, `code-*` …) — and an out-of-tree adapter answering a '
            . 'shipped name is the override, which INHERITS that adapter\'s interpreter, regenerator and '
            . 'provider declarations. That is executable privilege reached through a name nobody meant '
            . 'to grant. The window closes at the first vendor key: narrowing a namespace after one is '
            . 'issued orphans whatever was certified under it, so this is decidable only while the '
            . 'platform root is empty.',
        'reserved' => 'Nothing for the EXACT form, deliberately. A record that names a reserved adapter '
            . 'exactly is a decision someone wrote down — the operator overriding their own site, or a '
            . 'platform grant that names the adapter on purpose — and refusing it would delete a shipped '
            . 'capability to close a hole the pattern form is the whole of. What is not reserved either '
            . 'is a way to make the list itself grow at a site: R-27 keeps it a closed enumeration in '
            . 'agent code.',
    ];
    // WP-4.9's two rows. Ids R-25/R-26 rather than R-21: the tranche-1
    // integrator's note on R-20 above records that two riders both minted R-18
    // in parallel worktrees, so this program now assigns each rider a DISJOINT
    // id range up front. Register ids are ordinal bookkeeping — nothing on disk
    // or in a certificate embeds one — so a gap between R-20 and R-25 costs
    // nothing and a collision would cost a renumber at merge.
    $rows[] = [
        'id' => 'R-25',
        'title' => 'Delegation is depth-1, and the bound is in the verifier rather than in a policy',
        'now' => 'A `' . AdapterCertification::DELEGATIONS_FORMAT . '` document at `adapters/'
            . AdapterSources::SITE_DELEGATIONS_FILE . '` holds a map of '
            . ws_set($sets, 'DELEGATION_KEYS') . ' objects. The signed statement is '
            . ws_set($sets, 'DELEGATION_STATEMENT_KEYS') . ', with `delegate` '
            . ws_set($sets, 'DELEGATION_DELEGATE_KEYS') . ' and `delegator` '
            . ws_set($sets, 'DELEGATION_DELEGATOR_KEYS') . ', under domain `'
            . ws_bytes((string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN_DELEGATION'))
            . '`. Verification chains exactly '
            . (string) ws_const(AdapterCertification::class, 'DELEGATION_DEPTH')
            . ' level: a delegator that is itself a delegate is refused BY NAME before it is looked up. A '
            . 'delegator is resolved ONLY in `' . AdapterCertification::AUTHORITIES_FORMAT
            . '`\'s shipped file, so a site key cannot delegate; the grant may only narrow the '
            . 'delegator\'s `adapter_names`, `trust_tiers` and window; and a delegated key resolves under '
            . 'trust root `' . AdapterCertification::TRUST_ROOT_SITE . '`, not a third word.',
        'permanent' => 'The depth bound is inside the VERIFIER, not a configurable maximum, and that is '
            . 'what makes it a property rather than a setting: every holder of this agent enforces it '
            . 'identically and no document can ask for more. Raising it later would admit paths that were '
            . 'unrepresentable when the trust decision was made — a delegate that could not delegate '
            . 'yesterday could hand on a grant tomorrow, retroactively, with nothing in the field '
            . 're-reviewed. Lowering it to zero orphans every delegation already issued. The '
            . 'narrow-only rule is the same shape: it is a grammar restriction, so a widening grant is '
            . 'unrepresentable rather than merely refused by review, and relaxing it would silently widen '
            . 'every grant in the field at the next verification.',
        'reserved' => 'Nothing about depth. A vendor that must hand on authority enrolls its sub-vendor '
            . 'with the platform root directly, which is one review rather than an unbounded path. The '
            . 'extension channel for the statement itself is `version`, inside the signature: a grammar '
            . 'this engine does not implement is refused BY VERSION rather than read as corruption.',
    ];
    $rows[] = [
        'id' => 'R-26',
        'title' => 'Typed revocation, and the one channel that reaches a frozen snapshot',
        'now' => 'A `' . AdapterCertification::REVOCATIONS_FORMAT . '` document, envelope '
            . ws_set($sets, 'REVOCATIONS_ENVELOPE_KEYS') . ', statement '
            . ws_set($sets, 'REVOCATION_STATEMENT_KEYS') . ', each entry '
            . ws_set($sets, 'REVOCATION_ENTRY_KEYS') . ', under domain `'
            . ws_bytes((string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN_REVOCATION'))
            . '`. It is installed at `capabilities/adapter-revocations.json` in the agent\'s MANIFEST '
            . 'LIBRARY — the only path frozen verification holds — is signed by a key the shipped '
            . 'platform root carries, and ships ABSENT. An entry binds `fingerprint` = '
            . '`sha256(public_key)`, never the key id. The signer\'s own window is deliberately not '
            . 'applied; its `issued_at` IS, as the anchor that stops a backwards clock reading an '
            . 'in-force revocation as merely scheduled (R-14, G2-FIXES C1). THREE STATES, not two '
            . '(G2-FIXES C2): absent means nothing is revoked; a document signed by an ENROLLED key '
            . 'applies; and a document whose signer this root does not carry is INERT — reported as a '
            . 'library-scoped row by `AdapterSources::survey()`, entries not applied, site not refused. '
            . 'That third state is what makes the channel installable at all: the shipped root is '
            . '`{"keys":{}}`, so before enrollment a hard refusal was the ONLY outcome a correctly-signed '
            . 'revocation could produce. Tampering is unchanged and still fatal. What the channel takes '
            . 'away is the certified claim of the adapters that key signed, not the site: a revoked '
            . 'authority is the third typed withdrawal (C3), on the live path and inside a frozen '
            . 'snapshot alike.',
        'permanent' => 'The FINGERPRINT binding cannot be exchanged for an id binding afterwards: an id '
            . 'can be re-minted over new key material, so an id-bound revocation would be escapable by '
            . 'rotating a name. Absence meaning "nothing is revoked" is equally fixed — every deployed '
            . 'agent already reads it that way, so a future "absent means refuse" would brick every site '
            . 'that never installed one. And the reachability itself is one-way: this is the only channel '
            . 'that reaches an already-frozen snapshot for a site-rooted key, so removing it restores a '
            . 'gap for every vendor key already federated by copy, silently. And its LOCATION is a '
            . 'residual with a name: the manifest library is inside the adoption tar, so `duo adopt` '
            . 'replaces the library and takes any installed revocation document with it — absence means '
            . '"nothing is revoked", so the erasure is SILENT. Re-install it after an adopt, or point '
            . '`DUO_MANIFESTS_DIR` at a library the tar does not overwrite. A detector (recording the '
            . 'installed digest where adopt does not overwrite, and a diagnostic row when a '
            . 'previously-present document disappears) is deferred: it needs durable state outside the '
            . 'library, which is its own decision about where an agent may keep memory a re-adopt cannot '
            . 'reach.',
        'reserved' => 'The signer\'s window is unapplied ON PURPOSE and that is not an oversight to fix '
            . 'later: applying it would let a lapsed window RESURRECT the exact identities this document '
            . 'exists to burn. A future per-certificate revocation still needs R-06\'s missing '
            . 'identifier. What this document deliberately does NOT do is revoke the operator\'s own '
            . 'self-minted site key through a channel the operator does not control — that asymmetry is '
            . 'preserved, and every refusal it raises says so in its own sentence.',
    ];
    // WP-4.10's row. R-21 … R-26 are held by the sibling riders of this
    // tranche; ids are ordinal bookkeeping and nothing on disk or in a
    // certificate embeds one, so a renumber at integration moves no identity
    // (the note above R-20 records the last time that happened).
    $rows[] = [
        'id' => 'R-27',
        'title' => 'The reserved `<vendor>-` form, and the closed grandfather list under it',
        'now' => 'At `spec_version ' . (string) ws_const(IdentityNamespaces::class, 'NAMESPACED_SINCE')
            . '` an out-of-tree adapter name is `<vendor>-<name>` and every `providers[].id` it declares '
            . 'sits in that same vendor namespace (`IdentityNamespaces::assert_out_of_tree_identity()`, '
            . 'reached from `AdapterSources::assert_out_of_tree_contract()`, the one boundary all four '
            // WP-4.12: this clause used to read "so at DUO_SPEC_VERSION 2 it
            // refuses nothing", which was a statement about the engine that
            // happened to be true while the engine sat below NAMESPACED_SINCE.
            // The flip made it false, and a generated row that restates a
            // stale premise is worse than one that computes it — so the
            // sentence is now the COMPARISON, and it stays true through any
            // later bump without another edit here.
            . 'out-of-tree entry points share). The rule returns before reading a member below that '
            . 'version, so this engine, at `DUO_SPEC_VERSION ' . (string) DUO_SPEC_VERSION . '`, '
            . (DUO_SPEC_VERSION >= (int) ws_const(IdentityNamespaces::class, 'NAMESPACED_SINCE')
                ? 'enforces it on every out-of-tree adapter'
                : 'refuses nothing under it')
            . '. The unprefixed '
            . 'space is reserved to the shipped library as a CLOSED ENUMERATION of '
            . count(IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES) . ' adapter names (`'
            . implode('`, `', IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES) . '`) and '
            . count(IdentityNamespaces::GRANDFATHERED_ID_KINDS) . ' `id_kind`s (`'
            . implode('`, `', IdentityNamespaces::GRANDFATHERED_ID_KINDS) . '`), living in `agent/src` and '
            . 'never under `manifests/`. Gate 9 below asserts both halves. Ownership of a namespace is the '
            . "authority record's, not this list's: `adapter_names: [\"<vendor>-*\"]` (R-20) is what decides "
            . 'which names a key may certify — except that no non-platform grant may reach a name on this '
            . 'list through a pattern (R-22). The grandfather exemption is the NAME\'s alone: a '
            . 'grandfathered name that has a vendor half is still held to the provider-id rule '
            . '(G2-FIXES M2), and one with no vendor half — `core`, `acf`, `woocommerce`, `elementor`, '
            . '`polylang`, `yoast` — has no namespace for a provider id to be bound to, so that rule has '
            . 'nothing to say about it.',
        'permanent' => 'The separator forecloses every other scheme: `<vendor>-<name>` cannot later become '
            . '`<vendor>/<name>` or `<vendor>.<name>` without re-spelling every out-of-tree identity already '
            . 'authored, and an adapter name is inside the manifest bytes '
            . '`ArtifactPolicyIdentity::manifest_rows()` folds into that adapter\'s digest — so a re-spelling '
            . 'invalidates every pin and certificate that named it (the same door R-19 reaches). The '
            . 'ENUMERATION cannot be replaced by a shape test afterwards either: 10 of the '
            . count(IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES) . ' shipped names are hyphen-shaped '
            . 'without being vendor-prefixed (`the-events-calendar` is not vendor `the`), so a shape test '
            . 'admits precisely the rows a reviewer would want to see. And the list can never simply grow: '
            . 'each addition hands one more unprefixed identity to the shipped library permanently, which is '
            . 'why the release gate refuses any membership but equality with the library itself.',
        'reserved' => 'ONE HYPHEN DEEP, and no deeper — stated because the binding reads stronger than it '
            . 'is (G2-FIXES M3). `IdentityNamespaces::vendor()` splits on the FIRST hyphen, so a '
            . 'sub-vendor delegated `acme-forms-*` may name its adapter `acme-forms-widget`, whose vendor '
            . 'half is `acme`, and mint provider ids across the PARENT\'s whole `acme-` space rather than '
            . 'inside the scope its own certificate was checked against. A provider id is bound to the '
            . 'first segment of the declaring adapter\'s name — the top of the namespace its scope lies '
            . 'within — and not to the narrowest scope entry that certified it. Binding it to the matched '
            . 'scope entry means carrying a certificate into a loader that runs with no certificate in '
            . 'hand, which is a new permanent decision rather than a correction. '
            . 'This row deliberately reserves NOTHING for `tables.<t>.id_kind`. R-17 rules the prefix '
            . 'RULE out permanently — captured state and `duo_map` rows embed the bare kind — so the '
            . (string) count(IdentityNamespaces::GRANDFATHERED_ID_KINDS) . ' shipped kinds are recorded here '
            . 'as a permanent floor and a CONVENTION for authors, never as a break list. A future scheme for '
            . 'that space is a new `id_kind`-carrying wire, not an edit of this one.',
    ];
    // WP-4.7's two rows. R-21/R-22 are a sibling rider's and R-25/R-26 are
    // WP-4.9's; the gap is deliberate and the ids are ordinal bookkeeping, not
    // signed wire — nothing on disk or in a certificate embeds them (see the
    // note above R-20).
    $rows[] = [
        'id' => 'R-23',
        'title' => 'What a certificate binds about the platform: exercised cells, not the boundary document',
        'now' => '`statement.platform` is ' . ws_set($sets, 'STATEMENT_PLATFORM_KEYS')
            . ', where each member of `axes` is ' . ws_set($sets, 'STATEMENT_AXIS_KEYS')
            . '. Bound: `spec_version`, `site_mode`, and per compatibility axis the exercised CELL names '
            . 'plus a digest of what each cell admits — series names for a `'
            . (string) ws_const(AdapterCertification::class, 'PLATFORM_AXIS_SERIES')
            . '` map, the min/max line for each `'
            . (string) ws_const(AdapterCertification::class, 'PLATFORM_AXIS_ENGINES')
            . '` entry, the whole profile object minus its `'
            . (string) ws_const(AdapterCertification::class, 'PLATFORM_AXIS_NOTE')
            . '` for an axis with neither. Recorded and NOT bound: `agent_version`. Outside the '
            . 'member entirely: `branchable_state`, `plugin_execution`, every `note`, every `min`/`max`, '
            . 'and `wordpress`\'s derived `last_verified`.',
        'permanent' => 'The v1 statement bound `Canon::encode()` of the WHOLE platform record, so every '
            . 'agent release withdrew every certificate in the field — `manifests/capabilities/'
            . 'platform.json` restates both `define()`s (AGENTS.md rule 8), so a patch release that moved '
            . 'no axis anyone exercised still moved those bytes. Undoing this — widening back to the '
            . 'whole record, or binding `min`/`max` — restores that behaviour silently, because it is not '
            . 'a refusal anyone sees until the next release. Narrowing further is equally one-way: a cell '
            . 'dropped from the binding stops being a thing a certificate can be shown to have covered, '
            . 'and no artifact already signed records what it would have said.',
        'reserved' => 'A SIXTH compatibility axis needs nothing here: an axis the boundary GAINS is '
            . 'coverage no existing certificate claimed, and gaining one refuses nothing. Binding a fact '
            . 'this member does not carry is the other direction and needs a new statement generation '
            . '(R-24), because `assertExactKeys()` closes this member in both directions too.',
    ];
    $rows[] = [
        'id' => 'R-24',
        'title' => 'The statement generation: `version` inside the signature, and how a generation is recognised without one',
        'now' => 'Statements carry `version: '
            . (string) ws_const(AdapterCertification::class, 'STATEMENT_VERSION')
            . '`, signed under `' . ws_bytes($adapterDomain) . '`. A statement whose member set is exactly '
            . 'the v1 five (`adapter`, `authority`, `bundle`, `platform`, `ratification`) is recognised as '
            . 'the previous generation and WITHDRAWN by name through '
            . '`SupersededWireSiteAdapterCertificate`; a `version` this engine does not implement is '
            . 'withdrawn the same way, by VERSION. Both tests run behind the closed root key set, the '
            . 'canonical base64 Ed25519-length signature check and the statement\'s own member-shape '
            . 'proofs, and both degrade ONE adapter — on the live scan and inside a frozen snapshot alike '
            . '— never the whole source.',
        'permanent' => 'The generation must be decidable BEFORE a signature, because the signature domain '
            . 'is what the generation names (R-01): a verifier that needed the signature first could only '
            . 'ever guess. That forces the discriminator to be the member set, which is why `version` '
            . 'could not be added additively and had to arrive in the same change that moved the domain '
            . '(R-06). What `version` buys is that the NEXT such change is a version question instead: a '
            . 'grammar this engine does not implement refuses by number rather than reading as '
            . 'corruption, and a tamperer cannot downgrade a statement by DELETING bytes without hitting '
            . 'a named refusal. Removing it later would spend that property for every holder at once.',
        'reserved' => 'Nothing about this signal is authenticated, and that is accepted rather than '
            . 'argued away: anyone who can write the companion file can delete `version` and reach '
            . '`uncertified`, which is strictly weaker than the certificate and is the same state '
            . 'deleting the file reaches. A future generation may make the discriminator cheaper — a '
            . 'generation member OUTSIDE the statement, beside `format` — but it cannot make it '
            . 'authenticated, for the same reason the first sentence gives.',
    ];
    // WP-4.11's row. Every name below is READ from the shipped constants and
    // every refusal is RUN, so a slot that was quietly admitted, renamed or
    // reworded moves this document and fails the byte-compare in
    // `make release-gate` before anyone can call the lane still shut.
    $reservedStatement = (array) ws_const(AdapterCertification::class, 'STATEMENT_RESERVED_KEYS');
    $reservedWord = (string) ws_const(AdapterSources::class, 'CERTIFICATION_RESERVED_REVIEWER');
    $rows[] = [
        'id' => 'R-28',
        'title' => 'The reserved slots are REFUSALS, never admitted members',
        'now' => 'Four attachment points refuse by name, each naming the gate that would open it '
            . '(spec/repo-format.md § v3.10): the manifest key `'
            . (string) ws_const(AdapterContractGrammar::class, 'RESERVED_PACKAGE_KEY')
            . '` inside the closed key set (§ v3.3, so at `spec_version '
            . (string) ws_const(AdapterContractGrammar::class, 'CLOSED_KEY_SET_SINCE')
            // WP-4.12, for the reason recorded on R-27 above: "inert at
            // DUO_SPEC_VERSION N" was true only while N sat below
            // CLOSED_KEY_SET_SINCE, and the flip crossed it.
            . '` and therefore '
            . (DUO_SPEC_VERSION >= (int) ws_const(AdapterContractGrammar::class, 'CLOSED_KEY_SET_SINCE')
                ? 'live at' : 'inert at')
            . ' `DUO_SPEC_VERSION ' . (string) DUO_SPEC_VERSION . '`); the statement '
            . 'members `' . implode('`, `', array_keys($reservedStatement)) . '`; the bundle evidence '
            . 'member `' . (string) ws_const(AdapterCertification::class, 'RESERVED_EVIDENCE_REVIEWER')
            . '`; and the certification word `' . $reservedWord . '`. None of them is in any closed set: '
            . 'the statement is still ' . ws_spelled(count((array) ws_const(AdapterCertification::class, 'STATEMENT_KEYS')))
            . ' members (R-06) and the evidence object is still '
            . ws_set($sets, 'bundleEvidence') . '. Run, not restated — the '
            . 'evidence slot answers: "' . (string) ws_probe(AdapterCertification::class, 'bundleEvidence', [
                [
                    'exercised' => true,
                    'grammar' => AdapterSources::GRAMMAR_OK,
                    'reason' => 'reviewed',
                    'reviewer' => 'acme',
                ],
                'site certification bundle manifest',
                AdapterCertification::TRUST_ROOT_PLATFORM,
            ]) . '", and the word answers: "'
            . (string) AdapterSources::reserved_certification_refusal($reservedWord) . '".',
        'permanent' => 'A reservation on a SIGNED surface can only be a refusal, and that is a property '
            . 'of signatures rather than a style choice: the statement member set is closed in both '
            . 'directions AND is the generation discriminator a verifier reads before it has a domain to '
            . 'check a signature with (R-06, R-24), and the evidence object sits inside the bundle digest '
            . 'the statement binds. Admitting either member "for later" would therefore change the bytes '
            . 'every holder recomputes on the day it was admitted, for a capability that does not exist '
            . 'yet — the flag day this program exists to avoid, paid early and for nothing. What cannot '
            . 'be undone is the OPPOSITE direction: once one of these words is minted by a shipped '
            . 'engine, every deployed verifier that refuses it is refusing a live document, so the '
            . 'refusal has to exist in the field BEFORE the policy that mints it — which is why these '
            . 'ride v3 rather than the change that opens them.',
        'reserved' => 'What is deliberately NOT reserved: any SCHEMA for what eventually rides on these '
            . 'points. A reservation that guessed the shape would have to be right about a design nobody '
            . 'has reviewed; § v3.2\'s `engine_features` channel carries the detail later, so a slot need '
            . 'only be right about WHERE an extension attaches. Also not reserved, and recorded so it is '
            . 'not re-taken: the graduated `outside_version_range` verdict, which is a SHIPPED word '
            . '(`version_range_graduated`, WP-2.8) and not a slot at all. Opening any of the four is a '
            . 'policy flip proven by '
            . '`sandbox/tests/offline/adapter/regress_v3_reservations.php`, which pins each sentence and '
            . 'the statement\'s exact canonical bytes and signature — gate G5\'s condition 7 '
            . '(spec/repo-format.md § v3.11).',
    ];

    return $rows;
}

/**
 * GATE 10: the register is CONTINUOUS — R-01 … R-NN, each id exactly once.
 *
 * Register ids are ordinal bookkeeping and nothing on disk or in a certificate
 * embeds one, which is exactly why a gap is worth refusing: it costs nothing to
 * keep and it is the only visible trace a deleted row would leave. The register
 * carried one for three tranches — R-22, minted inside a rider's disjoint range
 * and never spent, so §2 printed R-21 followed by R-23 and no reader could tell
 * "never used" from "removed". G2-FIXES allocated it; this stops the next one
 * from going unnoticed.
 *
 * A DUPLICATE is refused for the sharper reason the R-20 note records: two
 * riders in parallel worktrees have already minted the same id twice, and the
 * integrator caught it by reading. This is that reading, mechanised.
 */
function ws_assert_row_continuity(): void {
    $ids = [];
    foreach (ws_rows() as $row) {
        $id = (string) $row['id'];
        if (preg_match('/^R-([0-9]{2})$/D', $id, $m) !== 1) {
            ws_fail("register row id '$id' is not of the form R-NN; §2's ids are ordinal and two digits wide");
        }
        $ordinal = (int) $m[1];
        if (isset($ids[$ordinal])) {
            ws_fail(
                "register row id '$id' appears twice — two riders minting one id is how R-18 was spent "
                . 'twice already (see the note above R-20); renumber one of them at integration'
            );
        }
        $ids[$ordinal] = true;
    }
    if ($ids === []) {
        ws_fail('the register carries no rows at all');
    }
    $highest = max(array_keys($ids));
    for ($ordinal = 1; $ordinal <= $highest; $ordinal++) {
        if (!isset($ids[$ordinal])) {
            ws_fail(
                'the register skips R-' . str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT)
                . ' — allocate it or record its withdrawal, because an id nobody can account for reads as a '
                . 'row somebody deleted'
            );
        }
    }
}

/**
 * The partition as `5 entity + 14 field + 14 non-surface` — projected, never typed.
 */
function ws_partition_text(): string {
    $partition = AdapterCertification::topLevelKeyPartition();

    return count($partition['entity_sections']) . ' entity + ' . count($partition['field_sections'])
        . ' field + ' . count($partition['non_surface_keys']) . ' non-surface';
}

/**
 * The implemented engine features that CLAIM one top-level key, sorted.
 *
 * R-21's sentence is about which feature admits a key, so it must name the
 * claimants and not the roster. Those were the same list until WP-6.2 added
 * `invalidate-vocabulary/v1`, which claims no key at all — it widens a value
 * vocabulary inside `tables.<t>.invalidate[]` — and printing the roster there
 * would have made the register say that feature admits `engine_features`, which
 * is false in the one direction the row exists to be exact about.
 *
 * Asked of the engine rather than listed, the same way every other cell here is
 * measured: the answer is whatever admitted_feature_keys() returns for a
 * manifest declaring exactly that one feature, so a feature that gains or loses
 * a key moves this sentence without anybody remembering to.
 *
 * @return list<string>
 */
function ws_features_claiming(string $key): array {
    $out = [];
    foreach (AdapterContractGrammar::implemented_features() as $feature) {
        $claimed = AdapterContractGrammar::admitted_feature_keys(['engine_features' => [$feature]]);
        if (in_array($key, $claimed, true)) {
            $out[] = $feature;
        }
    }
    if ($out === []) {
        throw new RuntimeException(
            "wire-surface: no implemented engine feature claims '$key'; R-21's sentence would name nothing"
        );
    }

    return $out;
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
    ws_assert_closed_key_set();
    ws_assert_grandfather_list($repo);
    ws_assert_row_continuity();
    $domains = [
        (string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN'),
        (string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN_AUTHORITIES'),
        // WP-4.9's two statement kinds (spec § v3.8). Registered here rather
        // than anywhere else because gate 2 below refuses the whole run over an
        // unregistered `duo-…-signature/vN` literal in the shipped trees — which
        // is exactly how a new permanent decision is stopped from shipping
        // quietly, and is why these two lines are part of the rider that
        // introduced them rather than a follow-up.
        (string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN_DELEGATION'),
        (string) ws_const(AdapterCertification::class, 'SIGNATURE_DOMAIN_REVOCATION'),
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
    $out .= "`php tools/wire-surface.php --check` proves ten things and refuses the run rather than\n";
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
    $out .= "   vocabulary is exactly `not_after`/`not_before` judged against `\$now ?? time()`; the typed\n";
    $out .= "   revocation entry is exactly `{effective_at, fingerprint, key_id, reason}` and lives in\n";
    $out .= "   exactly one signing file; `revoked_at` and CRL vocabulary appear in none (R-14, R-15, R-26).\n";
    $out .= "5. **The rollback signature really is domain-free.** A signature is minted and verified\n";
    $out .= "   against the unprefixed canonical payload at generation time (R-03).\n";
    $out .= "6. **The spec-version window has not accumulated.** The shipped validator is probed over\n";
    $out .= '   N-3 … N+2 and must accept exactly {N-1, N} — floor `DUO_SPEC_VERSION - 1`, never deeper'
        . " (R-18).\n\n";
    // Numbered 7 and not 6: the trust-root gate arrived with WP-4.8's merge and
    // kept the previous item's number, so this list printed "6." twice. A
    // one-character correction, made here because the item below it would
    // otherwise be unreadable. It happened AGAIN with WP-4.10's grandfather
    // gate, which is why item 9 below is numbered 9 and why the register now
    // carries a continuity gate of its own (ws_assert_row_continuity()): a list
    // that miscounts itself is the cheapest possible evidence that nobody read
    // it.
    $out .= "7. **The shipped platform trust root is one of its two legal states.** It is the empty\n";
    $out .= '   `' . AdapterCertification::AUTHORITIES_FORMAT . "` registry byte for byte, or a\n";
    $out .= '   `' . AdapterCertification::AUTHORITIES_FORMAT_V2 . "` document that VERIFIES through the\n";
    $out .= "   shipped reader — envelope signature, fingerprint-bound ids, windows and namespaces all\n";
    $out .= "   checked by the code a site runs (R-08, R-18).\n";
    $out .= "8. **The closed top-level manifest key set has one definition.** The set the shipped\n";
    $out .= "   validator admits at `spec_version: 3` and the partition the shipped signer\n";
    $out .= "   classifies against are compared in both directions, and the only excess admitted is what\n";
    $out .= "   an implemented engine feature claims (R-21).\n\n";
    $out .= "9. **The § v3.9 grandfather list is in its place and is still closed.** Its constants are\n";
    $out .= "   declared under `agent/src` — never under `manifests/`, where AGENTS.md rule 2 would fold\n";
    $out .= '   them into every adapter digest — and their membership equals the shipped library exactly: '
        . count(IdentityNamespaces::GRANDFATHERED_ADAPTER_NAMES) . " adapter\n";
    $out .= '   names and ' . count(IdentityNamespaces::GRANDFATHERED_ID_KINDS)
        . " `id_kind`s, in both directions, so a seventeenth unprefixed name is a reviewed\n";
    $out .= "   edit rather than a file appearing in a directory (R-27).\n";
    $out .= '10. **The register has no gaps and no duplicates.** Row ids run R-01 … R-'
        . str_pad((string) count(ws_rows()), 2, '0', STR_PAD_LEFT) . " with every integer\n";
    $out .= "    present exactly once. Ids are ordinal bookkeeping — nothing on disk or in a certificate\n";
    $out .= "    embeds one — but an id nobody can account for reads as a row somebody deleted, and this\n";
    $out .= "    document is the only place a deleted decision would be missed.\n\n";
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
