<?php

namespace App\Support\TeamAccess;

final class PermissionCatalog
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, array{label: string, group: string}>
     */
    public static function definitions(): array
    {
        return [
            'invoices.view' => ['label' => 'View invoices', 'group' => 'Money In'],
            'invoices.manage' => ['label' => 'Create and edit invoices', 'group' => 'Money In'],
            'invoices.delete' => ['label' => 'Delete invoices', 'group' => 'Money In'],
            'estimates.view' => ['label' => 'View estimates', 'group' => 'Money In'],
            'estimates.manage' => ['label' => 'Create and edit estimates', 'group' => 'Money In'],
            'estimates.delete' => ['label' => 'Delete estimates', 'group' => 'Money In'],
            'clients.view' => ['label' => 'View clients', 'group' => 'Money In'],
            'clients.manage' => ['label' => 'Create and edit clients', 'group' => 'Money In'],
            'clients.delete' => ['label' => 'Delete clients', 'group' => 'Money In'],
            'items.view' => ['label' => 'View items', 'group' => 'Money In'],
            'items.manage' => ['label' => 'Create and edit items', 'group' => 'Money In'],
            'items.delete' => ['label' => 'Delete items', 'group' => 'Money In'],
            'expenses.view' => ['label' => 'View expenses', 'group' => 'Money Out'],
            'expenses.manage' => ['label' => 'Create and edit expenses', 'group' => 'Money Out'],
            'expenses.delete' => ['label' => 'Delete expenses', 'group' => 'Money Out'],
            'suppliers.view' => ['label' => 'View suppliers', 'group' => 'Money Out'],
            'suppliers.manage' => ['label' => 'Create and edit suppliers', 'group' => 'Money Out'],
            'suppliers.delete' => ['label' => 'Delete suppliers', 'group' => 'Money Out'],
            'banking.view' => ['label' => 'View banking', 'group' => 'Banking'],
            'banking.manage' => ['label' => 'Manage banking', 'group' => 'Banking'],
            'accounting.view' => ['label' => 'View accounting', 'group' => 'Accounting'],
            'accounting.manage' => ['label' => 'Create and edit accounting', 'group' => 'Accounting'],
            'accounting.delete' => ['label' => 'Delete accounting entries', 'group' => 'Accounting'],
            'budgets.view' => ['label' => 'View budgets', 'group' => 'Planning'],
            'budgets.manage' => ['label' => 'Manage budgets', 'group' => 'Planning'],
            'contracts.view' => ['label' => 'View contracts', 'group' => 'Contracting'],
            'contracts.manage' => ['label' => 'Manage contracts', 'group' => 'Contracting'],
            'tax.view' => ['label' => 'View tax', 'group' => 'Tax'],
            'tax.manage' => ['label' => 'Manage tax', 'group' => 'Tax'],
            'reports.view' => ['label' => 'View reports', 'group' => 'Reports'],
            'reports.export' => ['label' => 'Export reports', 'group' => 'Reports'],
            'vehicles.view' => ['label' => 'View vehicles and trip log', 'group' => 'Travel'],
            'vehicles.manage' => ['label' => 'Create and edit vehicles and trips', 'group' => 'Travel'],
            'vehicles.delete' => ['label' => 'Delete vehicles and trips', 'group' => 'Travel'],
            'settings.business' => ['label' => 'Manage business settings', 'group' => 'Settings'],
            'settings.team' => ['label' => 'Manage team members and roles', 'group' => 'Settings'],
        ];
    }

    /**
     * @return list<array{name: string, permissions: list<array{key: string, label: string}>}>
     */
    public static function groupsForUi(): array
    {
        $groups = [];

        foreach (self::definitions() as $key => $meta) {
            $group = $meta['group'];
            if (! isset($groups[$group])) {
                $groups[$group] = [
                    'name' => $group,
                    'permissions' => [],
                ];
            }
            $groups[$group]['permissions'][] = [
                'key' => $key,
                'label' => $meta['label'],
            ];
        }

        return array_values($groups);
    }

    public static function isValid(string $permission): bool
    {
        return array_key_exists($permission, self::definitions());
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function sanitize(array $permissions): array
    {
        $valid = self::keys();

        return array_values(array_unique(array_filter(
            $permissions,
            fn (mixed $key): bool => is_string($key) && in_array($key, $valid, true)
        )));
    }
}
