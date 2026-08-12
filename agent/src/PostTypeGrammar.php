<?php
namespace Duo;

/**
 * The pure post-type behavior grammar extracted from Policy.php
 * (DUO-3348): the closed `post_types.<type>.body` and `.phase` switches.
 *
 * These values name engine behavior, not adapter-owned data. Keeping their
 * declaration validation beside the vocabulary makes a typo fail while the
 * manifest is still offline bytes, instead of silently falling back in the
 * runtime lookup and changing capture/apply semantics.
 *
 * Policy keeps the public body_mode()/post_type_phase() lookup contract, but
 * its load()/from_snapshot() paths call this validator directly. No engine
 * or WordPress class is needed here, so the grammar remains independently
 * loadable for offline checks.
 */
final class PostTypeGrammar {
    /** The closed `post_types.<type>.body` vocabulary. */
    private const BODY_MODES = ['blocks', 'verbatim'];

    /** The closed `post_types.<type>.phase` vocabulary. */
    private const POST_TYPE_PHASES = ['normal', 'early'];

    /** @return list<string> Policy::closed_vocabularies()'s body vocabulary. */
    public static function bodyModes(): array {
        return self::BODY_MODES;
    }

    /** @return list<string> Policy::closed_vocabularies()'s phase vocabulary. */
    public static function postTypePhases(): array {
        return self::POST_TYPE_PHASES;
    }

    public static function defaultBodyMode(): string {
        return self::BODY_MODES[0];
    }

    public static function defaultPostTypePhase(): string {
        return self::POST_TYPE_PHASES[0];
    }

    /** Validate the closed body/phase switches in one manifest. */
    public static function validate_post_type_contracts(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['post_types'] ?? [] as $postType => $decl) {
            if (!is_array($decl)) {
                continue; // shape already refused by validate_scope_classes()
            }
            foreach ([['body', self::BODY_MODES], ['phase', self::POST_TYPE_PHASES]] as [$key, $legal]) {
                if (!array_key_exists($key, $decl)) {
                    continue;
                }
                if (!in_array($decl[$key], $legal, true)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares post_types.$postType.$key="
                        . var_export($decl[$key], true) . ' but the vocabulary is closed ('
                        . implode(', ', $legal) . ') — it is engine-owned, because each value names engine '
                        . 'behavior the engine implements; a new one is an engine change with a spec bump, not a '
                        . 'manifest declaration'
                    );
                }
            }
        }
    }
}
