<?php
declare(strict_types=1);

namespace Duo\Cloud;

/**
 * Executes one command inside the already isolated workload generation.
 *
 * ControlAuthority invokes this interface only while the exact tenant/site,
 * resource lease, and held mutation fence remain locked and authoritative.
 * Implementations must not dispatch the command elsewhere after run() returns.
 */
interface CommandRunner {
    /**
     * @param array{
     *   action:string,
     *   command_index:int,
     *   command_phase:string,
     *   environment:string,
     *   format:string,
     *   input:array<string,mixed>,
     *   operation_id:string,
     *   request_id:string,
     *   site_id:string,
     *   target:array<string,mixed>,
     *   tenant_id:string
     * } $request
     * @return array{exit:int,stderr:string,stdout:string}
     */
    public function run(array $request): array;
}
