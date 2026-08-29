#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate/check docs/branch-environment-provider.md.
 *
 * Usage:
 *   php tools/provider-protocol-doc.php generate   # rewrite docs/branch-environment-provider.md
 *   php tools/provider-protocol-doc.php --check    # regenerate in memory, byte-compare, exit 1 on drift
 *   php tools/provider-protocol-doc.php            # same as --check
 *
 * WHY THIS IS GENERATED
 * ---------------------
 * Same posture as tools/capability-doc.php (:11-12): a hand-written protocol
 * document is a second implementation of the boundary, and the one that is
 * never executed is the one that rots. `duo env materialize` refuses on a
 * frozen production source, so a stale sentence here is not a documentation
 * bug — it is an operator learning the real contract mid-freeze.
 *
 * THE SINGLE SOURCE IS THREE CLASSES IN ONE SHIPPED FILE
 * -----------------------------------------------------
 *   Duo\Orchestrator\EnvironmentProviderProtocol   the closed actions, their
 *       gating capability, their request input and their closed result key
 *       sets with per-field types
 *   Duo\Orchestrator\EnvironmentProviderCapability the 19 capability ids
 *   Duo\Orchestrator\CommandEnvironmentProvider    the two wire format ids
 *
 * Nothing below invents a field name, a type, a capability id or a bound: each
 * is projected. The ONE thing this file states in its own bytes is the
 * refusal catalogue, because those strings live inside method bodies where no
 * projection can reach them. `provider_doc_refusals()` therefore carries its
 * own gate: every string it prints is asserted to appear verbatim in
 * cli/src/Environment/EnvironmentLifecycle.php, so a moved refusal fails here
 * rather than in the document.
 */

$repo = dirname(__DIR__);
require_once $repo . '/cli/src/Environment/EnvironmentLifecycle.php';

use Duo\Orchestrator\CommandEnvironmentProvider;
use Duo\Orchestrator\EnvironmentProviderCapability;
use Duo\Orchestrator\EnvironmentProviderProtocol;

function provider_doc_fail(string $message): never {
    fwrite(STDERR, "provider-protocol-doc: $message\n");
    exit(1);
}

/**
 * The refusal catalogue: the exact bytes an operator will see, paired with
 * what a provider did to earn them.
 *
 * @return list<array{0:string,1:string}> [refusal, cause]
 */
function provider_doc_refusals(): array {
    return [
        ["env '<env>': branch materialization requires machine-local environment_provider configuration",
            'the environment has no `environment_provider` object at all'],
        ["env '<env>': environment_provider is privileged host configuration and is allowed only in .duo-envs.json",
            'the block was found in the shared, committed `site.duo.json`'],
        ["env '<env>': environment_provider has missing or unknown fields",
            'the block is not exactly `{command, timeout_seconds}`'],
        ["env '<env>': environment_provider.command must be a non-empty argv array",
            '`command` is a string, an object, or empty'],
        ["env '<env>': environment_provider.command[<i>] is invalid",
            'an argv element is not a non-empty NUL-free string'],
        ["env '<env>': environment_provider executable must be absolute",
            'argv[0] does not begin with `/` — no PATH lookup happens here'],
        ["env '<env>': environment_provider.timeout_seconds must be 1..3600",
            '`timeout_seconds` is not an integer in 1..3600'],
        ['could not start environment provider', 'argv could not be executed'],
        ['environment provider failed; provider output is redacted', 'the process exited non-zero'],
        ['environment provider timed out; provider output is redacted', 'no complete response within `timeout_seconds`'],
        ['environment provider output exceeded the redacted evidence limit',
            'stdout+stderr exceeded ' . number_format(1048576) . ' bytes'],
        ['environment provider returned malformed JSON', 'stdout did not parse as JSON'],
        ['environment provider returned noncanonical evidence',
            're-encoding the parsed response canonically did not reproduce stdout byte for byte'],
        ['environment provider response has missing or unknown fields',
            'the response object is not exactly the seven top-level keys'],
        ['environment provider response is not bound to the request',
            '`format`, `action`, `environment`, `operation_id` or `status` does not match what was sent'],
        ['environment provider identity is malformed', '`provider` is not a JSON object'],
        ['environment provider identity has missing or unknown fields', '`provider` is not exactly `{id, protocol}`'],
        ['environment provider id is invalid', '`provider.id` is not `[A-Za-z0-9._:@+-]{1,128}`'],
        ['environment provider protocol must be 1', '`provider.protocol` is not the integer 1'],
        ['environment provider result must be an object', '`result` is a non-empty JSON list or a scalar'],
        ['environment provider capabilities must be a list', 'the `capabilities` result field is not a JSON list'],
        ['environment provider capability IDs must be strings', 'a capability id is not a string'],
        ["environment provider '<id>' declared unknown capability '<cap>'",
            'an advertised id is outside the vocabulary below'],
        ["environment provider '<id>' repeated a capability", 'the advertised list has a duplicate'],
        ["environment provider '<id>' cannot <operation>; missing <ids>",
            'the advertised set does not cover a requirement set below'],
        ['environment provider identity changed during capability negotiation',
            '`provider.id`/`provider.protocol` moved between two `capabilities` calls'],
        ['environment provider identity changed after capability negotiation',
            '`provider` moved between negotiation and an action'],
        ['environment provider capabilities must be negotiated before operations',
            'an action was attempted before `capabilities` (an orchestrator-side invariant, listed so a provider author can rely on the ordering)'],
        ['<action> result has missing or unknown fields',
            "the result key set is not exactly the action's closed set below"],
        ['environment provider <label> is invalid', 'an identifier field is not `[A-Za-z0-9._:@+-]{8,256}`'],
        ['environment provider <label> must be a SHA-256 digest', 'a digest field is not 64 lowercase hex characters'],
        ['environment provider <label> must be a positive integer', 'a generation field is not a JSON integer >= 1'],
        ['environment provider branch commit is invalid', '`branch_commit` is not a 40- or 64-character lowercase hex oid'],
        ['environment provider expiry must be canonical UTC seconds', '`expires_at` is not `YYYY-MM-DDTHH:MM:SSZ`'],
        ['environment provider URL must be a credential-free HTTP(S) base URL without query or fragment',
            '`url` carries userinfo, a query, a fragment, a non-HTTP scheme, or exceeds 2048 bytes'],
        ['environment provider <action> returned invalid presence',
            '`presence` is not `present`/`absent`, or `attach`/`create` returned `absent`'],
        ['environment provider snapshot-read did not prove immutable readback', '`immutable` is not JSON `true`'],
        ['environment provider snapshot-abort returned wrong disposition', '`disposition` is not `aborted`'],
        ['environment provider <action> returned an invalid mutation fence state',
            '`state` does not match the action (`acquire` -> `held`, `release` -> `released`)'],
        ['environment provider <action> did not return an active TTL lease', '`ttl_state` is not `active`'],
        ['environment provider <action> returned wrong disposition',
            '`destroy` did not answer `destroyed`, or `detach` did not answer `detached`'],
        ["unknown environment provider action '<action>'", 'an action outside the closed list below'],
    ];
}

/**
 * Assert every catalogued refusal still exists in the shipped enforcement.
 *
 * The placeholders above stand for interpolated values, so the check is on the
 * literal fragments either side of them. A refusal that is reworded fails the
 * generator, which fails `make release-gate` and this stream's offline suite.
 */
function provider_doc_assert_refusals_exist(string $repo): void {
    $source = (string) file_get_contents($repo . '/cli/src/Environment/EnvironmentLifecycle.php');
    // Two seams, because these refusals are COMPOSED rather than literal:
    // assertExactKeys() (:548-556) appends its own tail to a caller-supplied
    // label, so the label and the tail live in different places in the file.
    $seams = [' has missing or unknown fields'];
    foreach ($seams as $seam) {
        if (!str_contains($source, ltrim($seam))) {
            provider_doc_fail("refusal seam is no longer in cli/src/Environment/EnvironmentLifecycle.php: \"$seam\"");
        }
    }
    foreach (provider_doc_refusals() as [$refusal]) {
        foreach (preg_split('/<[a-z_]+>/', $refusal) ?: [] as $part) {
            foreach (explode($seams[0], $part) as $fragment) {
                if (strlen($fragment) < 12) {
                    continue;
                }
                if (!str_contains($source, $fragment)) {
                    provider_doc_fail(
                        "refusal fragment is no longer in cli/src/Environment/EnvironmentLifecycle.php: \"$fragment\""
                    );
                }
            }
        }
    }
}

/** @param array<string,string> $fields */
function provider_doc_field_rows(array $fields): string {
    $rows = '';
    ksort($fields, SORT_STRING);
    foreach ($fields as $field => $type) {
        $rows .= "| `$field` | " . EnvironmentProviderProtocol::describeType($type) . " |\n";
    }
    return $rows;
}

function provider_doc_build(string $repo): string {
    provider_doc_assert_refusals_exist($repo);
    $actions = EnvironmentProviderProtocol::actions();
    $capabilities = EnvironmentProviderCapability::all();

    $out = "# The branch-environment provider protocol\n\n";
    $out .= "**Generated — never hand-edited.** Written by `tools/provider-protocol-doc.php` from\n";
    $out .= "`cli/src/Environment/EnvironmentProviderProtocol.php`, `EnvironmentProviderCapability`\n";
    $out .= "and `CommandEnvironmentProvider`; `make release-gate` byte-compares it. Edit the code,\n";
    $out .= "then run `php tools/provider-protocol-doc.php generate`.\n\n";

    $out .= "Duo orchestrates providers; it does not supply hosting. A **branch-environment\n";
    $out .= "provider** is a program on the machine that runs `duo`, which can snapshot a source\n";
    $out .= "environment, acquire a disposable target, materialize a repository into it, and reap\n";
    $out .= "it again. `duo env materialize`, `duo env reap` and `duo rehearse` refuse without one.\n";
    $out .= "This document is the whole contract; `tools/reference-env-provider.php` is a worked\n";
    $out .= "example of it, not a second specification.\n\n";

    $out .= "Before pointing `duo env materialize` at production, run the harness:\n\n";
    $out .= "```\n";
    $out .= "duo env provider-check <env>                      # non-mutating: config, capabilities, one inspect\n";
    $out .= "duo env provider-check <env> --cycle --from <disposable-src> --confirm-disposable\n";
    $out .= "```\n\n";
    $out .= "The harness drives your provider through the same `CommandEnvironmentProvider` the\n";
    $out .= "orchestrator uses, so it cannot send a request the product would not send.\n";
    $out .= "`--cycle` FREEZES the named source with `snapshot-prepare`, which is why\n";
    $out .= "`--confirm-disposable` is mandatory and never inferred.\n\n";

    $out .= "## 1. Where a provider is configured\n\n";
    $out .= "The `environment_provider` block is privileged host configuration. It is accepted\n";
    $out .= "**only** from the machine-local `.duo-envs.json`, never from the shared, committed\n";
    $out .= "`site.duo.json`:\n\n";
    $out .= "```json\n";
    $out .= "{\n";
    $out .= "  \"envs\": {\n";
    $out .= "    \"<env>\": {\n";
    $out .= "      \"transport\": \"local\",\n";
    $out .= "      \"wp_path\": \"/absolute/path/to/wordpress\",\n";
    $out .= "      \"repo_path\": \"/absolute/path/to/site-repo\",\n";
    $out .= "      \"environment_provider\": {\n";
    $out .= "        \"command\": [\"/absolute/path/to/provider\", \"/absolute/path/to/config.json\"],\n";
    $out .= "        \"timeout_seconds\": 30\n";
    $out .= "      }\n";
    $out .= "    }\n";
    $out .= "  }\n";
    $out .= "}\n";
    $out .= "```\n\n";
    $out .= "This is a complete one-environment local registry shape; use the required transport\n";
    $out .= "keys for SSH or Docker instead when that is how the environment is reached.\n\n";
    $out .= "* the object's key set is exactly `{command, timeout_seconds}`;\n";
    $out .= "* `command` is a non-empty argv **list** — it is executed with `bypass_shell`, so\n";
    $out .= "  there is no shell, no word splitting and no PATH lookup;\n";
    $out .= "* `command[0]` must be absolute;\n";
    $out .= '* `timeout_seconds` is an integer in **1..3600**, applied per action. Long operations should still '
        . "be idempotent by `operation_id`.\n\n";

    $out .= "## 2. The wire\n\n";
    $out .= "One request object plus a newline on stdin; one response object plus a newline on\n";
    $out .= "stdout; exit 0. Both are **canonical JSON**: object keys sorted bytewise, no\n";
    $out .= "insignificant whitespace, `/` unescaped. The orchestrator re-encodes the response it\n";
    $out .= "parsed and compares bytes, so any other spacing or key order is refused as\n";
    $out .= 'noncanonical evidence. stdout plus stderr may not exceed ' . number_format(1048576) . " bytes, and\n";
    $out .= "raw provider output is redacted on every failure — it may carry host or production-data\n";
    $out .= 'diagnostics. A non-zero provider may return the canonical error response below to surface bounded, '
        . "operator-safe diagnostics without exposing stderr.\n\n";
    $out .= '**Request** — `' . CommandEnvironmentProvider::REQUEST_FORMAT . "`, key set exactly:\n\n";
    $out .= "| field | value |\n|---|---|\n";
    $out .= '| `action` | one of the ' . count(EnvironmentProviderProtocol::actions()) . " names in §5 |\n";
    $out .= "| `environment` | the registry name of the environment being acted on |\n";
    $out .= '| `format` | `' . CommandEnvironmentProvider::REQUEST_FORMAT . "` |\n";
    $out .= "| `input` | the per-action object in §5 — the empty JSON list `[]` for `capabilities` |\n";
    $out .= "| `operation_id` | one opaque `[A-Za-z0-9._:@+-]{8,256}` id, stable for a whole operation |\n\n";
    $out .= '**Response** — `' . CommandEnvironmentProvider::RESPONSE_FORMAT . "`, key set exactly:\n\n";
    $out .= "| field | value |\n|---|---|\n";
    $out .= "| `action` | echoed verbatim |\n";
    $out .= "| `environment` | echoed verbatim |\n";
    $out .= '| `format` | `' . CommandEnvironmentProvider::RESPONSE_FORMAT . "` |\n";
    $out .= "| `operation_id` | echoed verbatim |\n";
    $out .= "| `provider` | exactly `{id, protocol}`; `id` matches `[A-Za-z0-9._:@+-]{1,128}`, `protocol` is the integer `1` |\n";
    $out .= "| `result` | the per-action **closed** object in §5 |\n";
    $out .= "| `status` | `ok` on exit 0; `error` on a non-zero structured failure |\n\n";
    $out .= 'On a non-zero exit, stdout may carry the same request-bound envelope with `status: "error"` and '
        . '`result` exactly `{code,message,remediation}`. `code` is 3..64 lowercase identifier characters; '
        . 'message and remediation are non-empty, control-free strings up to 1024 bytes. Only those fields are '
        . "shown. Any malformed/noncanonical failure response falls back to the fully redacted failure.\n\n";
    $out .= "`provider` must not change for the life of an `operation_id`: a same-named\n";
    $out .= "environment whose provider identity moved is not a continuation of the journaled\n";
    $out .= "operation. `result` key sets are **closed** — a missing field and an extra field are\n";
    $out .= "the same refusal.\n\n";
    $out .= "`input` is deliberately **not** closed. Refuse when a key you need is absent; never\n";
    $out .= "refuse merely because a key you ignore is present.\n\n";

    $out .= "## 3. Capabilities\n\n";
    $out .= "`capabilities` is the first action of every operation. Advertise only what you\n";
    $out .= "implement: serving `create` with an attach, or `destroy` with a detach, converts a\n";
    $out .= "missing capability into a silent data-loss class. Refuse any action whose id you did\n";
    $out .= "not advertise, and name that id.\n\n";
    $out .= 'The ' . count($capabilities) . " ids:\n\n";
    foreach ($capabilities as $capability) {
        $out .= "* `$capability`\n";
    }
    $out .= "\n## 4. What each operation requires\n\n";
    $out .= "Each row is a set the orchestrator checks before it acts. The operation phrase is\n";
    $out .= "the text an operator sees after `cannot ` in\n";
    $out .= "`environment provider '<id>' cannot <operation>; missing <ids>`.\n\n";
    $out .= "| side | operation | required ids | also required when |\n|---|---|---|---|\n";
    foreach (EnvironmentProviderProtocol::requirementSets() as $set) {
        $ids = '`' . implode('`, `', $set['capabilities']) . '`';
        $extra = $set['conditional'] === []
            ? '—'
            : '`' . implode('`, `', $set['conditional']) . '` when ' . $set['conditional_when'];
        $out .= "| {$set['side']} | {$set['operation']} | $ids | $extra |\n";
    }
    $out .= "\n## 5. The " . count($actions) . " actions\n\n";
    foreach ($actions as $action) {
        $capability = EnvironmentProviderProtocol::capabilityFor($action);
        $input = EnvironmentProviderProtocol::requestInput($action);
        $out .= "### `$action`\n\n";
        $out .= 'Gated by ' . ($capability === null
            ? '**nothing** — negotiation cannot be gated on a negotiated capability.'
            : "`$capability`.") . "\n\n";
        $out .= $input['notes'] . "\n\n";
        if ($input['required'] === []) {
            $out .= "**Request `input`:** none.\n\n";
        } else {
            $out .= "**Request `input`:**\n\n| field | value |\n|---|---|\n";
            $out .= provider_doc_field_rows($input['required']);
            $out .= "\n";
        }
        if ($input['conditional'] !== []) {
            $out .= "Additionally, in the phases named above:\n\n| field | value |\n|---|---|\n";
            $out .= provider_doc_field_rows($input['conditional']);
            $out .= "\n";
        }
        $out .= "**Response `result`** (closed set):\n\n| field | value |\n|---|---|\n";
        $out .= provider_doc_field_rows(EnvironmentProviderProtocol::resultFields($action));
        $out .= "\n";
    }
    $out .= "## 6. Every refusal, and what earns it\n\n";
    $out .= "`<…>` stands for an interpolated value. These are the operator-visible bytes;\n";
    $out .= "provider stdout/stderr is never among them.\n\n";
    $out .= "| refusal | cause |\n|---|---|\n";
    foreach (provider_doc_refusals() as [$refusal, $cause]) {
        $out .= '| `' . str_replace('|', '\\|', $refusal) . "` | $cause |\n";
    }
    $out .= "\n## 7. The worked example\n\n";
    $out .= '`tools/reference-env-provider.php` implements all ' . count($actions) . " actions against Duo's own\n";
    $out .= "sandbox pair (`sandbox/bin/pair.sh` over the shared MariaDB). It is **DEV-ONLY** —\n";
    $out .= "`tools/` never ships, and `cli/src/Onboarding/Adopt.php` tars only `agent`,\n";
    $out .= "`manifests` and `recovery` — so read it as a demonstration of this document, not as\n";
    $out .= "an artifact you can deploy. Its `--print-plan` flag runs the same negotiation and\n";
    $out .= "argument validation and prints the command boundary an action would use, executing\n";
    $out .= "nothing.\n\n";
    $out .= "Two habits it demonstrates that this contract does not spell out but every operation\n";
    $out .= "depends on:\n\n";
    $out .= "* **idempotency per `operation_id`.** Any response may be lost after the work\n";
    $out .= "  happened. Every action is replayed with a byte-identical `input`, and must return\n";
    $out .= "  the same evidence rather than doing the work twice.\n";
    $out .= "* **never emulate.** Refuse an action you cannot perform exactly, naming the\n";
    $out .= "  capability id. A near-miss is worse than a refusal at every one of these actions.\n";
    return $out;
}

function provider_doc_run(string $repo, bool $check): void {
    $path = $repo . '/docs/branch-environment-provider.md';
    $expected = provider_doc_build($repo);
    if ($check) {
        $actual = is_file($path) ? (string) file_get_contents($path) : null;
        if ($actual !== $expected) {
            provider_doc_fail(
                'docs/branch-environment-provider.md is stale; run `php tools/provider-protocol-doc.php generate`'
            );
        }
        fwrite(STDOUT, "provider protocol doc check: docs/branch-environment-provider.md agrees with the code\n");
        return;
    }
    if (file_put_contents($path, $expected) === false) {
        provider_doc_fail('could not write docs/branch-environment-provider.md');
    }
    fwrite(STDOUT, "generated docs/branch-environment-provider.md\n");
}

try {
    $command = $argv[1] ?? '--check';
    if ($command === 'generate') {
        provider_doc_run($repo, false);
    } elseif ($command === '--check' || $command === 'check') {
        provider_doc_run($repo, true);
    } else {
        throw new RuntimeException('usage: php tools/provider-protocol-doc.php [generate|--check]');
    }
} catch (Throwable $e) {
    provider_doc_fail($e->getMessage());
}
