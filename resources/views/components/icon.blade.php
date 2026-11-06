{{-- Decorative glyphs from the locally bundled Material Symbols font: the old ui/icon.tsx vocabulary. --}}
@props(['name', 'class' => 'h-5 w-5'])
@php
    $symbols = [
        'dashboard' => 'space_dashboard', 'sales' => 'shopping_cart', 'purchases' => 'receipt_long',
        'products' => 'inventory_2', 'inventory' => 'warehouse', 'customers' => 'group',
        'suppliers' => 'local_shipping', 'expenses' => 'payments', 'income' => 'add_task',
        'accounts' => 'account_balance_wallet', 'reports' => 'analytics', 'quotations' => 'request_quote',
        'users' => 'group', 'settings' => 'settings', 'search' => 'search', 'help' => 'help',
        'cashflow' => 'payments', 'profit' => 'trending_up', 'owed' => 'assignment', 'owes' => 'assignment',
        'discount' => 'sell', 'warning' => 'warning', 'check' => 'check_circle', 'books' => 'balance',
        'add' => 'add', 'receipt' => 'receipt', 'shoppingBag' => 'shopping_bag', 'payment' => 'credit_score',
        'bank' => 'account_balance', 'stock' => 'inventory_2', 'notifications' => 'notifications',
        'password' => 'lock_reset',
    ];
@endphp
<span aria-hidden="true" class="stitch-symbol {{ $class }}">{{ $symbols[$name] ?? throw new InvalidArgumentException("No icon named {$name}") }}</span>
