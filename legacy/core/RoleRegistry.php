<?php
// core/RoleRegistry.php — centralized role definitions (Phase 6).

require_once __DIR__ . '/Auth.php';

class RoleRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        if (self::$definitions === null) {
            $path = dirname(__DIR__) . '/app/config/roles.php';
            $loaded = is_readable($path) ? require $path : [];
            self::$definitions = is_array($loaded) ? $loaded : [];
        }

        return self::$definitions;
    }

    public static function normalize(string $role): string
    {
        return strtolower(trim($role));
    }

    public static function isValid(string $role): bool
    {
        $role = self::normalize($role);
        return $role !== '' && isset(self::definitions()[$role]);
    }

    public static function label(string $role): string
    {
        $role = self::normalize($role);
        return (string)(self::definitions()[$role]['label'] ?? ucfirst($role));
    }

    /**
     * @return string[]
     */
    public static function allKeys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Operational roles (excludes superadmin/admin tiers).
     *
     * @return string[]
     */
    public static function operationalKeys(): array
    {
        $keys = [];
        foreach (self::definitions() as $key => $meta) {
            if (($meta['tier'] ?? '') === 'operational') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Roles the current actor may assign in employee forms.
     *
     * @return string[]
     */
    public static function assignableForActor(?string $currentRole = ''): array
    {
        $actor = Auth::getRole() ?? '';
        $actor = self::normalize($actor);
        $currentRole = self::normalize((string)$currentRole);

        $roles = [];
        foreach (self::definitions() as $key => $meta) {
            $allowedBy = $meta['assignable_by'] ?? [];
            if (in_array($actor, $allowedBy, true)) {
                $roles[] = $key;
            }
        }

        if ($currentRole !== '' && !in_array($currentRole, $roles, true)) {
            $roles[] = $currentRole;
        }

        return $roles;
    }

    /**
     * Filter dropdown list used on employee index filters.
     *
     * @return string[]
     */
    public static function filterList(): array
    {
        return self::allKeys();
    }

    public static function canActorAssign(string $role): bool
    {
        $role = self::normalize($role);
        if ($role === '') {
            return false;
        }

        $actor = self::normalize(Auth::getRole() ?? '');
        $allowedBy = self::definitions()[$role]['assignable_by'] ?? [];

        return in_array($actor, $allowedBy, true);
    }

    public static function tier(string $role): string
    {
        $role = self::normalize($role);
        return (string)(self::definitions()[$role]['tier'] ?? 'operational');
    }
}
