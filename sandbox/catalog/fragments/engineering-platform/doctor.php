#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Doctor;

require_once __DIR__ . '/DoctorService.php';

$root = dirname(__DIR__, 4);
exit((new Doctor())->render((new Doctor())->inspect($root)));
