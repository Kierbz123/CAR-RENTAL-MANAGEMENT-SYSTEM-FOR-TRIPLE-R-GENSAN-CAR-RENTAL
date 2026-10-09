<?php
declare(strict_types=1);

namespace TripleR\Support;

use TripleR\Security\Access;

/**
 * The single role-to-menu map for the staff workspace. The sidebar layout and
 * the /api/staff/navigation endpoint both read it, so they cannot drift apart.
 * This only decides what is shown; controllers still enforce access.
 */
final class Navigation
{
    private const GROUPS = [
        'Operations' => [
            ['label' => 'Workspace', 'href' => '/staff', 'icon' => 'home', 'roles' => Access::STAFF],
            ['label' => 'Agreements', 'href' => '/rentals', 'icon' => 'document', 'roles' => Access::STAFF],
            ['label' => 'Payments', 'href' => '/payments', 'icon' => 'cash', 'roles' => Access::PAYMENTS],
            ['label' => 'Customers', 'href' => '/customers', 'icon' => 'users', 'roles' => Access::CUSTOMERS],
        ],
        'Fleet' => [
            ['label' => 'Vehicles', 'href' => '/fleet/vehicles', 'icon' => 'car', 'roles' => Access::FLEET_VIEW],
            ['label' => 'Locations', 'href' => '/fleet/locations', 'icon' => 'pin', 'roles' => Access::FLEET_VIEW],
            ['label' => 'Drivers', 'href' => '/fleet/drivers', 'icon' => 'id', 'roles' => Access::DRIVERS_VIEW],
        ],
        'Administration' => [
            ['label' => 'Notifications', 'href' => '/staff/notifications', 'icon' => 'bell', 'roles' => Access::STAFF],
            ['label' => 'Staff accounts', 'href' => '/admin/users', 'icon' => 'shield', 'roles' => Access::ADMIN, 'also' => ['/admin/sessions']],
        ],
        'Driver' => [
            ['label' => 'My trips', 'href' => '/driver', 'icon' => 'car', 'roles' => Access::DRIVER],
        ],
    ];

    /** @return array<string, list<array{label:string,href:string,icon:string,active:bool}>> */
    public static function groupsFor(string $role, string $currentPath): array
    {
        $groups = [];
        foreach (self::GROUPS as $heading => $items) {
            foreach ($items as $item) {
                if (!in_array($role, $item['roles'], true)) {
                    continue;
                }
                $groups[$heading][] = [
                    'label' => $item['label'],
                    'href' => $item['href'],
                    'icon' => $item['icon'],
                    'active' => self::isActive($item, $currentPath),
                ];
            }
        }
        return $groups;
    }

    /** @return list<array{label:string,href:string}> */
    public static function flatFor(string $role): array
    {
        $flat = [];
        foreach (self::groupsFor($role, '') as $items) {
            foreach ($items as $item) {
                $flat[] = ['label' => $item['label'], 'href' => $item['href']];
            }
        }
        return $flat;
    }

    public static function allows(string $role, string $href): bool
    {
        foreach (self::flatFor($role) as $item) {
            if ($item['href'] === $href) {
                return true;
            }
        }
        return false;
    }

    private static function isActive(array $item, string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($item['href'] === '/staff') {
            return $path === '/staff';
        }
        foreach (array_merge([$item['href']], $item['also'] ?? []) as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }
}
