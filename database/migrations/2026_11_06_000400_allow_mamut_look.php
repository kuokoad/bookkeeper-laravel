<?php

declare(strict_types=1);

use App\Database\Constraints as C;
use App\Enums\Look;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Mamut look: business_settings.look's CHECK is built from the Look enum,
 * so a new case needs the constraint rebuilt. New here: the old app had no
 * such look, so imported shops never carry it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `business_settings` DROP CHECK `ck_business_settings_look`');
        C::check('business_settings', ['ck_business_settings_look' => C::in('look', Look::class)]);
    }

    public function down(): void
    {
        DB::table('business_settings')->where('business_settings.look', 'mamut')->update(['look' => Look::Default->value]);
        DB::statement('ALTER TABLE `business_settings` DROP CHECK `ck_business_settings_look`');
        C::check('business_settings', ['ck_business_settings_look' => C::inList('look', ['default', 'ledger', 'classic', 'jinu', 'quikbooks'])]);
    }
};
