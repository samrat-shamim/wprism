<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/CatalogException.php';
require_once dirname(__DIR__) . '/DeterministicArchive.php';
require_once dirname(__DIR__) . '/Build.php';
require_once dirname(__DIR__) . '/Loader.php';
require_once dirname(__DIR__) . '/PerformanceHarness.php';
require_once dirname(__DIR__) . '/Performance.php';
require_once dirname(__DIR__, 5) . '/cli/src/HostContracts/ReleaseSelection.php';
require_once dirname(__DIR__, 5) . '/cli/src/ArtifactTrust/ArtifactTrustVerifier.php';
require_once dirname(__DIR__) . '/Release.php';
require_once dirname(__DIR__) . '/CloseGate.php';
require_once dirname(__DIR__) . '/Qualification.php';
require_once dirname(__DIR__) . '/Audit.php';
require_once dirname(__DIR__) . '/Aggregate.php';
require_once dirname(__DIR__) . '/Catalog.php';
require_once dirname(__DIR__) . '/CommandContract.php';
require_once dirname(__DIR__) . '/CommandResult.php';
require_once dirname(__DIR__) . '/HarnessApproval.php';
require_once dirname(__DIR__) . '/Doctor.php';
require_once dirname(__DIR__) . '/Runner.php';
require_once dirname(__DIR__) . '/Reports.php';
require_once dirname(__DIR__) . '/Selection.php';
require_once dirname(__DIR__) . '/ShardPlan.php';
require_once dirname(__DIR__) . '/Toolchain.php';
require_once dirname(__DIR__) . '/Quality.php';
require_once dirname(__DIR__) . '/Hygiene.php';
