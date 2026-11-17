<?php

declare(strict_types=1);

namespace App\View;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The menu: a port of the old components/shared/navigation.ts NAV_SECTIONS,
 * same groups, labels and order.
 *
 * Two questions, kept apart as the old layout kept them. The Gate
 * (`module.view`) decides what this PERSON may see; the page's own
 * `permission:` middleware enforces the same answer, so hiding the link is a
 * convenience. A `feature` decides what this SHOP asked to be offered and
 * protects nothing: the address still opens.
 */
final class Navigation
{
    /** @var list<array{heading: string, items: list<array{href: string, label: string, icon: string, module?: string, feature?: string, primary?: bool}>}> */
    public const SECTIONS = [
        ['heading' => 'Daily', 'items' => [
            // No module: every sign-in lands on the dashboard.
            ['href' => '/dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'primary' => true],
            ['href' => '/sales', 'label' => 'Sales', 'icon' => 'sales', 'module' => 'sales', 'primary' => true],
            ['href' => '/quotations', 'label' => 'Quotations', 'icon' => 'quotations', 'module' => 'quotations', 'feature' => 'quotations'],
            ['href' => '/purchases', 'label' => 'Purchases', 'icon' => 'purchases', 'module' => 'purchases'],
            ['href' => '/expenses', 'label' => 'Expenses', 'icon' => 'expenses', 'module' => 'expenses', 'primary' => true],
        ]],
        ['heading' => 'Stock', 'items' => [
            ['href' => '/products', 'label' => 'Stock items', 'icon' => 'products', 'module' => 'products', 'primary' => true],
            ['href' => '/inventory', 'label' => 'Inventory', 'icon' => 'inventory', 'module' => 'inventory'],
        ]],
        ['heading' => 'People', 'items' => [
            ['href' => '/customers', 'label' => 'Customers', 'icon' => 'customers', 'module' => 'customers'],
            ['href' => '/suppliers', 'label' => 'Suppliers', 'icon' => 'suppliers', 'module' => 'suppliers'],
        ]],
        ['heading' => 'Money', 'items' => [
            ['href' => '/accounts', 'label' => 'Accounts', 'icon' => 'bank', 'module' => 'accounts'],
            ['href' => '/reconciliation', 'label' => 'Reconciliation', 'icon' => 'books', 'module' => 'reconciliation'],
            ['href' => '/accounting', 'label' => 'Accounting', 'icon' => 'reports', 'module' => 'accounts'],
            ['href' => '/reports', 'label' => 'Reports', 'icon' => 'reports', 'module' => 'reports'],
        ]],
        ['heading' => 'Admin', 'items' => [
            ['href' => '/users', 'label' => 'Users', 'icon' => 'users', 'module' => 'users'],
            ['href' => '/settings', 'label' => 'Settings', 'icon' => 'settings', 'module' => 'settings'],
        ]],
    ];

    /**
     * The sections this person may see in this shop, empty ones dropped.
     *
     * @param  array<string, bool>  $features  SettingsService::features()
     */
    public static function sectionsFor(User $user, array $features): array
    {
        $gate = Gate::forUser($user);
        $sections = [];
        foreach (self::SECTIONS as $section) {
            $items = array_values(array_filter($section['items'], fn (array $item): bool => (! isset($item['module']) || $gate->allows($item['module'].'.view'))
                && (! isset($item['feature']) || ($features[$item['feature']] ?? true))));
            if ($items !== []) {
                $sections[] = ['heading' => $section['heading'], 'items' => $items];
            }
        }

        return $sections;
    }

    /** The mobile bottom bar: the primary items, space for four. */
    public static function primaryItems(array $sections): array
    {
        $items = array_merge(...array_map(fn (array $s): array => $s['items'], $sections ?: [['items' => []]]));

        return array_slice(array_values(array_filter($items, fn (array $i): bool => $i['primary'] ?? false)), 0, 4);
    }

    /** Where a menu item points: the dashboard goes to the app's own address (APP_URL), whose root lands on it. */
    public static function link(string $href): string
    {
        return $href === '/dashboard' ? (string) config('app.url') : $href;
    }

    public static function isActive(string $path, string $href): bool
    {
        $path = '/'.ltrim($path, '/');

        return $path === $href || str_starts_with($path, $href.'/');
    }
}
