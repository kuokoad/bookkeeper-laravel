{{-- Where a refused page sends a person (RequirePermission): the old (app)/no-access page. --}}
@php
    $labels = ['sales' => 'Sales', 'purchases' => 'Purchases', 'products' => 'Products', 'inventory' => 'Inventory', 'customers' => 'Customers', 'suppliers' => 'Suppliers', 'expenses' => 'Expenses', 'income' => 'Other income', 'accounts' => 'Accounts', 'reconciliation' => 'Reconciliation', 'quotations' => 'Quotations', 'reports' => 'Reports', 'users' => 'Users', 'settings' => 'Settings'];
    $label = $labels[(string) request()->query('area')] ?? null;
@endphp
<x-layouts::app title="No access">
    <div class="mx-auto max-w-xl">
        <x-page-header title="You do not have access"
            :description="$label ? 'Your account is not set up to open '.strtolower($label).'.' : 'Your account is not set up to open that part of the shop.'" />
        <div class="rounded-xl border border-line bg-surface-raised p-5">
            <p class="text-sm text-content">You are signed in as <span class="font-medium">{{ auth()->user()?->display_name ?? 'someone' }}</span>. If you need this, ask the shop owner to give your account access.</p>
            <p class="mt-2 text-sm text-content-muted">Nothing has gone wrong, and nothing was changed.</p>
            <div class="mt-5 flex flex-wrap gap-2">
                <x-button size="sm" href="/dashboard">Back to the dashboard</x-button>
                <x-button size="sm" variant="secondary" href="/sales/new">New sale</x-button>
            </div>
        </div>
    </div>
</x-layouts::app>
