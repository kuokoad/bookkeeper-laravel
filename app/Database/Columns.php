<?php

namespace App\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Column groups that recur across the schema, so every table gets them the
 * same way.
 *
 * Money, quantity and rates are BIGINT minor units. Timestamps are DATETIME(3)
 * in UTC (the old app stored integer milliseconds). Trading dates are DATE.
 */
final class Columns
{
    /** created_at and updated_at, DATETIME(3) UTC, set by the database. */
    public static function timestamps(Blueprint $table): void
    {
        $table->dateTime('created_at', 3)->useCurrent();
        $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
    }

    public static function createdAt(Blueprint $table): void
    {
        $table->dateTime('created_at', 3)->useCurrent();
    }

    /**
     * created_by → users, nulled if the user row ever goes (it never should:
     * users are switched off, not deleted).
     */
    public static function createdBy(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    }

    public static function isDemo(Blueprint $table): void
    {
        $table->boolean('is_demo')->default(false);
    }

    /**
     * The block every posting document shares: trading date, the moment it
     * was entered, status, its journal entry and how it was voided.
     */
    public static function postedDocument(Blueprint $table): void
    {
        $table->date('business_date');
        $table->dateTime('occurred_at', 3);
        $table->string('status', 16)->default('POSTED');
        $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
        $table->dateTime('voided_at', 3)->nullable();
        $table->text('void_reason')->nullable();
        self::createdBy($table);
        self::isDemo($table);
    }

    /** What was typed on a line: the unit, the quantity, and the carton size shown. */
    public static function entryUnit(Blueprint $table): void
    {
        $table->string('entry_unit', 768)->nullable();
        $table->bigInteger('entry_qty_milli')->nullable();
        $table->bigInteger('entry_factor_milli')->nullable();
    }
}
