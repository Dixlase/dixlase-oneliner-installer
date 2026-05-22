<?php

/**
 * Router for the `php -S` mock GitHub server.
 *
 * When MOCK_EXPECTED_TOKEN is set in the environment, every request must carry
 * a matching "Authorization: Bearer <token>" header — otherwise the server
 * answers 401. With the env var unset the router is transparent and the
 * built-in server serves the requested static file as usual.
 */

$expected = getenv('MOCK_EXPECTED_TOKEN');

if ($expected !== false && $expected !== '') {
    $auth = '';

    foreach (getallheaders() as $name => $value) {
        if (strtolower($name) === 'authorization') {
            $auth = $value;
            break;
        }
    }

    if ($auth !== 'Bearer ' . $expected) {
        http_response_code(401);
        header('Content-Type: text/plain');
        echo "unauthorized: missing or invalid token\n";
        return true;
    }
}

// Returning false lets the built-in server serve the static file.
return false;
