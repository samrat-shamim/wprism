<?php
declare(strict_types=1);

$addressFile = $argv[1] ?? '';
if (!is_string($addressFile) || $addressFile === '') {
    fwrite(STDERR, "usage: journey-responder.php <address-file>\n");
    exit(2);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "journey responder could not bind: $errorNumber $errorMessage\n");
    exit(2);
}
$address = stream_socket_get_name($server, false);
if (!is_string($address) || file_put_contents($addressFile, $address) === false) {
    fwrite(STDERR, "journey responder could not publish its address\n");
    exit(2);
}

while (($connection = stream_socket_accept($server, 60)) !== false) {
    while (($line = fgets($connection)) !== false && rtrim($line, "\r\n") !== '') {
        // Consume the bounded request headers before answering.
    }
    $body = "release journey ready\n";
    fwrite(
        $connection,
        "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n" . $body
    );
    fclose($connection);
}
