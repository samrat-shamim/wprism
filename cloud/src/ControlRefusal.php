<?php
declare(strict_types=1);

namespace Duo\Cloud;

/** A deliberately non-secret diagnostic for a request the authority refused. */
final class ControlRefusal extends \RuntimeException {
}
