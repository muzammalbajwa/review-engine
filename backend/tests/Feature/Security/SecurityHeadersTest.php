<?php

/**
 * .claude/SECURITY.md #6: "Security headers: X-Content-Type-Options,
 * X-Frame-Options DENY, Content-Security-Policy, Referrer-Policy" + "HTTPS
 * everywhere. HSTS on." This had no test coverage at all — SecurityHeaders
 * being registered in bootstrap/app.php was previously only verified by
 * reading the code, never by an actual response.
 */
test('every API response carries the baseline security headers', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
});

test('HSTS is sent when the request arrives over HTTPS (via the trusted proxy header)', function () {
    // bootstrap/app.php: trustProxies(at: '*') — the app sits behind a
    // TLS-terminating proxy in every real environment, so this is how a
    // real HTTPS request actually reaches Laravel, not a raw TLS socket.
    $response = $this->withHeader('X-Forwarded-Proto', 'https')->getJson('/api/v1/health');

    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('HSTS is never sent on a plain HTTP request', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertHeaderMissing('Strict-Transport-Security');
});
