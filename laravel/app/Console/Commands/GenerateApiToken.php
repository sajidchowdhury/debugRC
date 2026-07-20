<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Phase 18 — Artisan command to generate (or regenerate) an API token
 * for a user.
 *
 * Usage:
 *   php artisan api:token {username} {--role=admin}
 *
 * Behaviour:
 *   1. Finds the user by their (case-insensitive) username.
 *   2. Generates a 64-character random plain-text token.
 *   3. Hashes it with SHA-256 and stores the hash in users.api_token.
 *   4. Optionally updates the user's role via the linked Employee record
 *      when --role is provided.
 *   5. Prints the plain-text token to stdout (shown only once — the hash
 *      is what's stored in the DB).
 *
 * The token is used as `Authorization: Bearer {token}` on /api/v1/*
 * requests. See app/Http/Middleware/ApiAuth.php.
 *
 * Exit codes:
 *   0 = success
 *   1 = user not found
 */
class GenerateApiToken extends Command
{
    /**
     * Command signature.
     *
     * {username}         The username (case-insensitive lookup).
     * {--role= : Optional role to assign to the user's Employee record
     *            (one of config/roles.php). If omitted, the role is unchanged.
     */
    protected $signature = 'api:token
                            {username : The username to issue a token for}
                            {--role= : Optional role to assign (e.g. admin, manager, salesman)}';

    /**
     * Command description.
     */
    protected $description = 'Generate a Bearer API token for a user (Phase 18)';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        $roleOption = trim((string) $this->option('role'));

        if ($username === '') {
            $this->error('Username is required.');
            return self::FAILURE;
        }

        // Case-insensitive username lookup — the application lowercases
        // usernames on store/update, so a direct match would work, but
        // we want to be defensive against manually-seeded rows.
        $user = User::withTrashed()
            ->whereRaw('LOWER(username) = ?', [strtolower($username)])
            ->first();

        if ($user === null) {
            $this->error("User '{$username}' not found.");
            return self::FAILURE;
        }

        // Optionally update the role on the linked Employee.
        if ($roleOption !== '') {
            $validRoles = array_keys(config('roles', []));
            if (!in_array($roleOption, $validRoles, true)) {
                $this->error("Invalid role '{$roleOption}'. Valid roles: " . implode(', ', $validRoles));
                return self::FAILURE;
            }

            $employee = $user->employee;
            if ($employee === null) {
                $this->error("User '{$username}' has no linked Employee record — cannot set role.");
                return self::FAILURE;
            }

            // Only update if different.
            if ($employee->role !== $roleOption) {
                $previousRole = $employee->role;
                $employee->role = $roleOption;
                $employee->save();
                $this->info("Role updated: {$previousRole} → {$roleOption}");
            } else {
                $this->info("Role unchanged: {$roleOption}");
            }
        }

        // Always re-activate the user when issuing a token — a disabled
        // user can't use the API even with a valid token (ApiAuth rejects
        // is_active=false users).
        if (!$user->is_active) {
            $user->is_active = true;
            $this->warn('User was inactive — re-activated so the new token works.');
        }

        // Generate the plain token (60 chars by default, but the task spec
        // asks for 64 — we override the length here).
        $plain = Str::random(64);

        // Hash + store.
        $user->api_token = hash('sha256', $plain);
        $user->save();

        // Pretty-print the result so it's easy to copy out of the terminal.
        $currentRole = $user->employee?->role ?? '(none)';
        $this->newLine();
        $this->info('=== API Token Issued ===');
        $this->line("  User:      <info>{$user->username}</info>");
        $this->line("  Role:      <info>{$currentRole}</info>");
        $this->line("  API Token: <comment>{$plain}</comment>");
        $this->newLine();
        $this->warn('  ⚠️  This token is shown only ONCE. Store it securely.');
        $this->line('  Use as: Authorization: Bearer ' . $plain);
        $this->newLine();

        return self::SUCCESS;
    }
}
