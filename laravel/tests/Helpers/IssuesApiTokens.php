<?php

namespace Tests\Helpers;

use App\Models\User;

/**
 * Phase 13 API testing helper.
 *
 * Provides convenience methods for issuing bearer tokens on test users so
 * that Feature/Api tests can hit /api/v1/* endpoints without re-implementing
 * the User::generateApiToken() dance in every test.
 */
trait IssuesApiTokens
{
    /**
     * Generate a plain-text API token for the given user and return it.
     *
     * The caller passes the token as `Authorization: Bearer {token}` to
     * authenticate API requests in the test.
     */
    protected function apiTokenForUser(User $user): string
    {
        return $user->generateApiToken();
    }

    /**
     * Build the Authorization header value for the given plain token.
     *
     * Use as:  ->withHeaders(['Authorization' => $this->bearerHeader($token)])
     */
    protected function bearerHeader(string $plainToken): string
    {
        return 'Bearer ' . $plainToken;
    }
}
