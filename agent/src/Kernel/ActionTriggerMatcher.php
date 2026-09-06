<?php
declare(strict_types=1);

namespace WPrism;

/** Dependency-free matching for the closed action-trigger selector vocabulary. */
final class ActionTriggerMatcher {
    /** The one bounded non-literal trigger; it matches concrete post kinds only. */
    public const POST_KIND_TRIGGER = 'post:*';

    public static function matches(string $trigger, string $surface): bool {
        if ($trigger === $surface) {
            return true;
        }
        return $trigger === self::POST_KIND_TRIGGER
            && preg_match('/^post:[a-z0-9][a-z0-9._-]{0,127}$/D', $surface) === 1;
    }

    /** @param list<string> $triggers */
    public static function any_matches(array $triggers, string $surface): bool {
        foreach ($triggers as $trigger) {
            if (self::matches((string) $trigger, $surface)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $triggers @param list<string> $surfaces @return list<string> */
    public static function matching_surfaces(array $triggers, array $surfaces): array {
        $matching = [];
        foreach ($surfaces as $surface) {
            $surface = (string) $surface;
            if ($surface !== '' && self::any_matches($triggers, $surface)) {
                $matching[$surface] = true;
            }
        }
        $out = array_keys($matching);
        sort($out, SORT_STRING);
        return $out;
    }
}
