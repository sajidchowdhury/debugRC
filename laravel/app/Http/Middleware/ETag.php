<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * MEDIUM-WAVE-2-C (G-197 / api-conventions.md G8) — ETag + conditional-GET
 * middleware for API read endpoints.
 *
 * WHY THIS EXISTS:
 *   Mobile clients poll `GET /dashboard`, `GET /lookups/*`, and other read
 *   endpoints every few seconds. Without ETag support, every poll re-downloads
 *   the full response body even when nothing has changed. On a typical mobile
 *   dashboard poll cycle (12 req/min × ~8 KB body = ~96 KB/min per client),
 *   this is wasteful bandwidth + battery + DB-CPU (the controller still runs
 *   to produce the body that gets discarded).
 *
 *   This middleware adds RFC 7232 compliant ETag + If-None-Match handling to
 *   every API response. The flow:
 *
 *     1. Client sends `GET /api/v1/dashboard` (first poll — no If-None-Match).
 *     2. Controller runs, returns 200 + body.
 *     3. This middleware computes `ETag = "md5(body)"` (quoted, strong tag),
 *        sets the `ETag` response header, and returns the response.
 *     4. Client stores the ETag + body.
 *     5. On the next poll, client sends `If-None-Match: "<md5>"`.
 *     6. Controller still runs (the DB is hit — ETag is a bandwidth/cache
 *        optimization, not a server-side cache). The middleware recomputes
 *        the ETag from the new body and compares it to the request header.
 *     7. If the ETags match (body unchanged), the middleware returns
 *        `304 Not Modified` with an empty body + the ETag header. The client
 *        reuses its cached body.
 *
 *   Note: this is a STRONG ETag (no `W/` prefix). The body hash is byte-exact,
 *   so any whitespace or key-order change produces a different ETag. This is
 *   intentional — JSON payloads in this API are deterministic (Resources
 *   serialize in declaration order; casts are explicit).
 *
 * WHAT IT APPLIES TO:
 *   - GET + HEAD requests only (no body to hash on POST/PUT/DELETE).
 *   - 200 OK responses only (no point ETagging a 4xx/5xx error).
 *   - Non-streaming responses only — `StreamedResponse` + `BinaryFileResponse`
 *     don't expose a `getContent()` (the body hasn't been materialized when
 *     the middleware runs). They are skipped; clients polling those endpoints
 *     continue to receive the full body.
 *
 * REGISTRATION:
 *   Registered globally in the `api` middleware stack via `bootstrap/app.php`:
 *
 *     ->withMiddleware(function (Middleware $middleware) {
 *         $middleware->api([
 *             \App\Http\Middleware\ETag::class,
 *         ]);
 *     });
 *
 *   It runs AFTER the controller (it's a "post" middleware — it calls
 *   `$next($request)` first, then inspects/modifies the response). It is
 *   therefore positioned at the outer edge of the api stack, after
 *   `ApiRateLimit` headers have been attached.
 *
 * CLIENT USAGE:
 *   Clients SHOULD send `If-None-Match: "<etag>"` on every poll of a read
 *   endpoint (the value from the previous response's `ETag` header). They MUST
 *   handle a 304 response by reusing their cached body. Clients MUST NOT send
 *   a body on a 304 follow-up request (the body has no effect on the ETag).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7232#section-2.3 RFC 7232 §2.3 (ETag)
 * @see https://datatracker.ietf.org/doc/html/rfc7232#section-3.2 RFC 7232 §3.2 (If-None-Match)
 * @see api-conventions.md §11.5 ETag / Conditional-GET (canonical doc)
 */
class ETag
{
    /**
     * Handle an incoming request: pass to next, then attach ETag + honor
     * If-None-Match.
     *
     * @param  Request  $request
     * @param  Closure  $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        // Only ETag cacheable responses: GET/HEAD + 200 + non-streaming.
        // - GET/HEAD: no request body to factor in; POST/PUT/DELETE responses
        //   are not cacheable.
        // - 200: a 4xx/5xx is not stable (validation errors can be transient)
        //   and clients shouldn't cache them.
        // - StreamedResponse/BinaryFileResponse: the body isn't materialized
        //   when this middleware runs (getContent() returns empty string), so
        //   hashing it would produce a constant ETag that has no relation to
        //   the actual streamed content. Skip — clients polling these endpoints
        //   continue to receive the full body.
        if (! $this->isCacheable($request, $response)) {
            return $response;
        }

        $body = $response->getContent();

        // Empty body — no point ETagging (the controller returned nothing).
        // Skip rather than produce an ETag for an empty payload, which would
        // collide across distinct empty endpoints.
        if ($body === '' || $body === false) {
            return $response;
        }

        // Strong ETag per RFC 7232 §2.3: the hash is wrapped in double quotes
        // and has no `W/` weak-validator prefix. md5 is sufficient for cache
        // validation (it is NOT a security hash — it just needs collisions to
        // be practically impossible for distinct response bodies, which md5
        // delivers at 128 bits). SHA-256 would be overkill here.
        $etag = '"' . md5($body) . '"';

        $response->headers->set('ETag', $etag);

        // Honor If-None-Match: if the client's stored ETag matches the freshly
        // computed one, the body is unchanged. Return 304 Not Modified with
        // the ETag header so the client knows its cached copy is still valid.
        // We preserve any Cache-Control header the controller may have set
        // (e.g. `Cache-Control: private, max-age=30`) so the client respects
        // the same freshness window on both 200 and 304.
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($this->matchesIfNoneMatch($ifNoneMatch, $etag)) {
            $notModified = new Response(null, 304);

            // Carry forward the ETag + any Cache-Control directive.
            $notModified->headers->set('ETag', $etag);

            $cacheControl = $response->headers->get('Cache-Control');
            if ($cacheControl !== null && $cacheControl !== '') {
                $notModified->headers->set('Cache-Control', $cacheControl);
            }

            return $notModified;
        }

        return $response;
    }

    /**
     * Determine whether the request/response pair is cacheable: GET or HEAD
     * method, 200 status, and a response type whose body is fully materialized
     * (not a stream or file download).
     */
    private function isCacheable(Request $request, SymfonyResponse $response): bool
    {
        $method = strtoupper($request->getMethod());

        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        // $response->isSuccessful() returns true for 2xx status codes. We
        // intentionally narrow to 200 only — a 201 Created carries the new
        // resource's representation but is not a pollable cache entry (the
        // resource didn't exist before). 204 has no body. 206 Partial Content
        // is for range requests (not used by this API).
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        // Skip streaming + binary-file responses — their body is not available
        // via getContent() at middleware time.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        return true;
    }

    /**
     * Compare the request's If-None-Match header against the computed ETag.
     *
     * Per RFC 7232 §3.2, If-None-Match may carry:
     *   - `*` — matches any existing resource (always returns 304 for cache
     *     validation; we treat it as a match if the response produced a body).
     *   - A single ETag value: `"abc123"`.
     *   - A comma-separated list of ETags: `"abc", "def"`.
     *
     * We do a simple string comparison after normalizing whitespace — strong
     * vs weak (W/) comparison is intentionally NOT implemented (this API does
     * not emit weak ETags, and weak validation semantics don't apply to a
     * byte-exact md5 hash).
     */
    private function matchesIfNoneMatch(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || $ifNoneMatch === '') {
            return false;
        }

        $trimmed = trim($ifNoneMatch);

        // Wildcard — client asserts "I have some cached representation of this
        // resource, return 304 if any exists". Since we've already produced a
        // 200 response, the resource exists → 304.
        if ($trimmed === '*') {
            return true;
        }

        // Split on commas + trim each candidate. Compare against the computed
        // ETag (both should already be quoted per RFC 7232 §2.3).
        $candidates = array_map(static fn (string $v): string => trim($v), explode(',', $trimmed));

        return in_array($etag, $candidates, true);
    }
}
