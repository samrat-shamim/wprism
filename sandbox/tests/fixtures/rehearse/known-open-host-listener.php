<?php
/** Known-open host socket for the live containment route-denial probe. */
declare(strict_types=1);

$portPath = (string) ($_SERVER['argv'][1] ?? '');
if ($portPath === '') {
    fwrite(STDERR, "usage: php known-open-host-listener.php <port-file>\n");
    exit(2);
}
$server = stream_socket_server('tcp://0.0.0.0:0', $errorCode, $errorMessage);
if (!is_resource($server)) {
    throw new RuntimeException("could not open host listener: $errorCode $errorMessage");
}
$address = stream_socket_get_name($server, false);
$separator = is_string($address) ? strrpos($address, ':') : false;
$port = $separator === false ? 0 : (int) substr((string) $address, $separator + 1);
if ($port < 1 || $port > 65535) throw new RuntimeException('host listener received no kernel-selected port');
if (file_put_contents($portPath, $port . "\n", LOCK_EX) === false) {
    throw new RuntimeException('host listener could not publish its selected port');
}
chmod($portPath, 0600);
while (true) {
    $client = stream_socket_accept($server, -1);
    if (!is_resource($client)) continue;
    fwrite($client, "HTTP/1.0 204 No Content\r\nConnection: close\r\n\r\n");
    fclose($client);
}
