<?php
/**
 * Installation identity tests.
 *
 * Covers validate_url() and canonicalize_site_url(): which site URLs the API
 * accepts, and which of them describe the same WordPress installation.
 *
 * Run: php tests/canonical_identity_test.php
 */

require_once __DIR__ . '/../includes/security.php';

$passed = 0;
$failed = 0;

function check(string $label, $actual, $expected): void
{
    global $passed, $failed;

    if ($actual === $expected) {
        $passed++;
        return;
    }

    $failed++;
    printf(
        "FAIL  %s\n        expected: %s\n        actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

// --- Accepted installation URLs -------------------------------------------
$accepted = [
    'example.com'                    => 'example.com',
    'https://example.com'            => 'example.com',
    'https://example.com/'           => 'example.com',
    'http://example.com'             => 'example.com',
    'HTTPS://EXAMPLE.COM/'           => 'example.com',
    'Https://Example.Com'            => 'example.com',
    'https://demo.example.com'       => 'demo.example.com',
    'https://demo.example.com/'      => 'demo.example.com',
    'https://wedding.example.com'    => 'wedding.example.com',
    'https://www.example.com'        => 'www.example.com',
    'https://example.com/wedding'    => 'example.com/wedding',
    'https://example.com/wedding/'   => 'example.com/wedding',
    'HTTP://EXAMPLE.COM/Wedding/'    => 'example.com/Wedding',
    'https://example.com/client-a'   => 'example.com/client-a',
    'https://example.com:8443'       => 'example.com:8443',
    'https://example.com:8443/'      => 'example.com:8443',
    'https://example.com:443/'       => 'example.com',
    'http://example.com:80/'         => 'example.com',
    'https://example.com?to=Rudi'    => 'example.com',
    'https://example.com/wedding/#rsvp' => 'example.com/wedding',
];
foreach ($accepted as $input => $expected) {
    check("canonical of {$input}", canonicalize_site_url($input), $expected);
}

// --- Distinct installations must stay distinct ----------------------------
$distinct = [
    ['https://example.com', 'https://demo.example.com'],
    ['https://example.com', 'https://www.example.com'],
    ['https://example.com', 'https://example.com/wedding'],
    ['https://example.com/wedding', 'https://example.com/client-a'],
    ['https://example.com/demo', 'https://example.com/demo-2'],
    ['https://demo.example.com', 'https://wedding.example.com'],
    ['https://example.com', 'https://example.com:8443'],
];
foreach ($distinct as [$a, $b]) {
    check(
        "{$a} !== {$b}",
        canonicalize_site_url($a) === canonicalize_site_url($b),
        false
    );
}

// --- Same installation across transports ----------------------------------
$same = [
    ['https://example.com', 'http://example.com'],
    ['https://example.com', 'https://example.com/'],
    ['https://example.com', 'HTTPS://EXAMPLE.COM/'],
    ['https://example.com/wedding', 'http://example.com/wedding/'],
];
foreach ($same as [$a, $b]) {
    check(
        "{$a} === {$b}",
        canonicalize_site_url($a) === canonicalize_site_url($b),
        true
    );
}

// --- Rejected input -------------------------------------------------------
$rejected = [
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'file:///etc/passwd',
    'ftp://example.com',
    'https://user:pass@example.com',
    "https://example.com/\r\nInjected: 1",
    'https://example.com/%0d%0aInjected:',
    "https://example.com/\x00",
    'https://example.com/%00',
    'https://[::1]',
    'https://[::1',
    'https://127.0.0.1',
    'https://example.com:0',
    'https://example.com:99999',
    'https://',
    'https://.com',
    'https://exa mple.com',
    'https://example.com/../../etc/passwd',
    'https://*.example.com',
    'not a url at all',
    '',
];
foreach ($rejected as $input) {
    check('reject ' . str_replace(["\r", "\n", "\x00"], ['\r', '\n', '\0'], $input), validate_url($input), false);
    check('reject canonical ' . str_replace(["\r", "\n", "\x00"], ['\r', '\n', '\0'], $input), canonicalize_site_url($input), false);
}

// --- Determinism ----------------------------------------------------------
foreach (array_keys($accepted) as $input) {
    check(
        "deterministic {$input}",
        canonicalize_site_url($input) === canonicalize_site_url((string) canonicalize_site_url($input)),
        true
    );
}

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
