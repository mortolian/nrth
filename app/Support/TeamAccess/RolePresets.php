<?php

namespace App\Support\TeamAccess;

final class RolePresets
{
    public const ACCOUNTANT = 'accountant';

    public const VIEWER = 'viewer';

    /**
     * @return list<string>
     */
    public static function viewerPermissions(): array
    {
        return array_values(array_filter(
            PermissionCatalog::keys(),
            fn (string $key): bool => str_ends_with($key, '.view')
        ));
    }

    /**
     * @return list<string>
     */
    public static function accountantPermissions(): array
    {
        return array_values(array_filter(
            PermissionCatalog::keys(),
            function (string $key): bool {
                if (str_starts_with($key, 'settings.')) {
                    return false;
                }

                if (str_ends_with($key, '.delete')) {
                    return false;
                }

                return true;
            }
        ));
    }

    /**
     * @return list<string>
     */
    public static function ownerPermissions(): array
    {
        return PermissionCatalog::keys();
    }

    /**
     * @return list<string>|null
     */
    public static function permissionsFor(string $key): ?array
    {
        return match ($key) {
            self::ACCOUNTANT => self::accountantPermissions(),
            self::VIEWER => self::viewerPermissions(),
            default => null,
        };
    }

    /**
     * @return array<string, array{key: string, name: string, description: string, permissions: list<string>}>
     */
    public static function systemRoles(): array
    {
        return [
            self::ACCOUNTANT => [
                'key' => self::ACCOUNTANT,
                'name' => 'Accountant',
                'description' => 'View and manage data, export reports. Cannot delete records or change settings.',
                'permissions' => self::accountantPermissions(),
            ],
            self::VIEWER => [
                'key' => self::VIEWER,
                'name' => 'Viewer',
                'description' => 'Read-only access to dashboards, records, and reports.',
                'permissions' => self::viewerPermissions(),
            ],
        ];
    }
}
