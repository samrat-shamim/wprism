<?php
namespace Duo\Regenerators;

use Duo\Policy;

/**
 * TEC (The Events Calendar) regenerator — DUO-3234, task #124's proving
 * fixture. Wraps the exact, verified-live two-call synthesis path
 * `TEC\Events\Custom_Tables\V1\Migration\Strategies\Single_Event_Migration_
 * Strategy::apply()` itself uses (that class is built for the SAME recovery
 * scenario this mechanism needs — a `tribe_events` post whose custom-table
 * rows are missing or stale — so this regenerator mirrors its own two calls
 * rather than inventing a new path), read from the real TEC 6.17.2 source
 * (src/Events/Custom_Tables/V1/Migration/Strategies/Single_Event_Migration_
 * Strategy.php:70-100), not guessed from the class name alone:
 *
 *   $upserted = Event::upsert(['post_id'], Event::data_from_post($post_id));
 *   // upsert() returns FALSE (not an exception) on failure — checked
 *   // explicitly, mirroring Single_Event_Migration_Strategy's own check.
 *   $event = Event::find($post_id, 'post_id');
 *   // find() can return non-Event on a miss — checked via instanceof,
 *   // again mirroring the plugin's own verified-live guard.
 *   $event->occurrences()->save_occurrences();
 *
 * `Event extends Model`, and Model::__callStatic()
 * (src/Events/Custom_Tables/V1/Models/Model.php) proxies undefined static
 * calls to an internal Builder — upsert(), find(), and last_errors() are
 * ALL dispatched this way, invisible to method_exists() even though
 * calling them works. A pre-check that used method_exists() on those
 * names was tried against a real, working TEC install and produced a
 * false-positive "not found" for upsert() — confirmed empirically, not
 * assumed (see this task's design comment for the exact error). This file
 * now pre-checks only data_from_post(), the one method confirmed (grep on
 * Event.php itself) to be a real, directly-declared static method; every
 * other call below — upsert(), last_errors(), find(), the instance-side
 * occurrences()/save_occurrences() (whose real-vs-magic status was never
 * independently confirmed either way) — is unchecked and relies on
 * Apply::regen_dependencies()'s own try/catch to wrap whatever \Error or
 * \RuntimeException surfaces. That's deliberate: guessing wrong about
 * real-vs-magic in either direction is worse than a slightly less
 * custom-tailored failure message on a genuine break.
 */
final class TheEventsCalendar {
    private Policy $policy;

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    public function regenerate(int $localId): void {
        $eventClass = '\\TEC\\Events\\Custom_Tables\\V1\\Models\\Event';
        if (!class_exists($eventClass)) {
            throw new \RuntimeException(
                "duo: TEC regenerator: $eventClass not found — The Events Calendar's Custom Tables v1 "
                . 'architecture may have been restructured in a version outside this manifest\'s pinned range'
            );
        }
        if (!method_exists($eventClass, 'data_from_post')) {
            throw new \RuntimeException(
                "duo: TEC regenerator: $eventClass::data_from_post() not found — cannot regenerate tec_occurrences"
            );
        }

        $upserted = $eventClass::upsert(['post_id'], $eventClass::data_from_post($localId));
        if ($upserted === false) {
            $errors = (array) $eventClass::last_errors();
            throw new \RuntimeException(
                "duo: TEC Event::upsert() failed for post $localId: " . implode('. ', $errors)
            );
        }

        $event = $eventClass::find($localId, 'post_id');
        if (!($event instanceof $eventClass)) {
            throw new \RuntimeException(
                "duo: TEC Event::find(\$localId, 'post_id') could not locate the just-upserted event for post $localId"
            );
        }
        $event->occurrences()->save_occurrences();
    }
}
