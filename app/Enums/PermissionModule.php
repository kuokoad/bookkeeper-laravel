<?php

namespace App\Enums;

/**
 * Areas a staff member can be given rights in.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum PermissionModule: string
{
    use HasValues;

    case Sales = 'sales';
    case Purchases = 'purchases';
    case Inventory = 'inventory';
    case Products = 'products';
    case Customers = 'customers';
    case Suppliers = 'suppliers';
    case Expenses = 'expenses';
    case Income = 'income';
    case Accounts = 'accounts';
    case Reports = 'reports';
    case Reconciliation = 'reconciliation';
    case Quotations = 'quotations';
    case Users = 'users';
    case Settings = 'settings';
}
