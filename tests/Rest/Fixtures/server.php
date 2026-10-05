<?php
// Router for PHP's built-in web server, used by CurlTransportTest.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/sleep') {
    usleep((int) $_GET['ms'] * 1000);
    echo 'slept';
    return;
}

if (preg_match('#^/status/(\d+)$#', $path, $m)) {
    header('Location: https://example.test/record/v1/customer/42');
    // After Location, which would otherwise force a 302.
    http_response_code((int) $m[1]);
    header('X-Multi: a', false);
    header('X-Multi: b', false);
    return;
}

header('Content-Type: application/json');
echo json_encode([
    'method'  => $_SERVER['REQUEST_METHOD'],
    'uri'     => $_SERVER['REQUEST_URI'],
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body'    => file_get_contents('php://input'),
]);
