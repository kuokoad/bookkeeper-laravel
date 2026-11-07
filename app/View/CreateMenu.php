<?php

declare(strict_types=1);

namespace App\View;

use App\Enums\PermissionModule as M;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Gate;

/** The Create Transaction menu: every kind of entry the signed-in person may start. */
final class CreateMenu
{
    /** @return list<array{label: string, href: string}> */
    public static function items(): array
    {
        $can = fn (M $module): bool => Gate::allows($module->value.'.create');
        $quotations = SettingsService::features(SettingsService::get())['quotations'] ?? false;

        $items = [
            ['Sale', '/sales/new', $can(M::Sales)],
            ['Quotation', '/quotations/new', $can(M::Quotations) && $quotations],
            ['Purchase', '/purchases/new', $can(M::Purchases)],
            ['Expense', '/expenses#record-expense', $can(M::Expenses)],
            ['Other income', '/income#record-income', $can(M::Income)],
            ['Customer payment received', '/customers?balance=owing', $can(M::Customers)],
            ['Supplier payment', '/suppliers?balance=owing', $can(M::Suppliers)],
            ['Transfer between accounts', '/accounts', $can(M::Accounts)],
            ['Stock adjustment', '/inventory/adjustments/new', $can(M::Inventory)],
        ];

        return array_values(array_map(
            fn (array $i): array => ['label' => $i[0], 'href' => $i[1]],
            array_filter($items, fn (array $i): bool => $i[2]),
        ));
    }
}
