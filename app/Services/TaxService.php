<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Tx;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Errors\ConflictError;
use App\Domain\Errors\InvariantViolatedError;
use App\Domain\Errors\NotFoundError;
use App\Domain\Errors\ValidationError;
use App\Domain\Money;
use App\Domain\Tax\TaxBreakdown;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxComponents;
use App\Domain\Tax\TaxLine;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\TaxBasis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shop's taxes as data: NHIL, GETFund, VAT and whatever the next budget
 * adds, each held in its own liability account. A port of the old
 * tax.service.ts.
 */
final class TaxService
{
    private const CODE_PATTERN = '/^[A-Z0-9_]{1,20}$/D';

    /**
     * What the till charges right now: nothing unless tax is switched on,
     * then every active component with a rate, in order.
     *
     * @return array{components: list<TaxComponent>, accountIdByCode: array<string, int>, componentIdByCode: array<string, int>, totalRateBp: int, enabled: bool, inclusive: bool}
     */
    public static function profile(): array
    {
        $settings = DB::table('business_settings')->where('id', 1)->first();
        $enabled = (bool) ($settings->tax_enabled ?? false);
        $rows = $enabled ? self::activeRows() : collect();
        $components = $rows->map(self::component(...))->all();

        return [
            'components' => $components,
            'accountIdByCode' => $rows->mapWithKeys(fn (object $r): array => [$r->code => (int) $r->gl_account_id])->all(),
            'componentIdByCode' => $rows->mapWithKeys(fn (object $r): array => [$r->code => (int) $r->id])->all(),
            'totalRateBp' => TaxComponents::totalRateBp($components),
            'enabled' => $enabled,
            'inclusive' => (bool) ($settings->tax_inclusive ?? false),
        ];
    }

    public static function apply(array $profile, int $amount): TaxBreakdown
    {
        return $profile['inclusive']
            ? TaxComponents::withinGross($amount, $profile['components'])
            : TaxComponents::onNet($amount, $profile['components']);
    }

    /** @param  iterable<TaxLine>  $lines */
    public static function recoverableTotal(iterable $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total = Money::add($total, $line->component->isRecoverable ? $line->amount : 0);
        }

        return $total;
    }

    /** @param  iterable<TaxLine>  $lines */
    public static function nonRecoverableTotal(iterable $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total = Money::add($total, $line->component->isRecoverable ? 0 : $line->amount);
        }

        return $total;
    }

    /**
     * The liability account a tax line posts to: its component's, found by id,
     * then by code, then VAT Payable as the last resort.
     */
    public static function accountFor(?int $componentId, string $code): int
    {
        if ($componentId !== null) {
            $byId = DB::table('tax_components')->where('id', $componentId)->value('gl_account_id');
            if ($byId !== null) {
                return (int) $byId;
            }
        }
        $byCode = DB::table('tax_components')->where('code', $code)->value('gl_account_id');
        if ($byCode !== null) {
            return (int) $byCode;
        }
        $fallback = DB::table('accounts')->where('code', ChartOfAccounts::TAX_PAYABLE)->value('id');
        if ($fallback === null) {
            throw new InvariantViolatedError("No account to hold {$code} tax in.", ['code' => $code]);
        }

        return (int) $fallback;
    }

    /**
     * Snapshot a sale's tax lines: rate, basis and name as charged, so a later
     * rate change cannot rewrite what a receipt said. Zero lines are skipped.
     *
     * @param  iterable<TaxLine>  $lines
     * @param  array<string, int>  $componentIdByCode
     */
    public static function writeSaleTaxes(int $saleId, iterable $lines, array $componentIdByCode, Carbon $at): void
    {
        foreach ($lines as $line) {
            if ($line->amount === 0) {
                continue;
            }
            DB::table('sale_taxes')->insert(self::snapshot($line, $componentIdByCode, $at) + ['sale_id' => $saleId]);
        }
    }

    /**
     * As writeSaleTaxes, plus whether the tax was reclaimable when the
     * purchase was made: recoverability is frozen at the time of the document.
     *
     * @param  iterable<TaxLine>  $lines
     * @param  array<string, int>  $componentIdByCode
     */
    public static function writePurchaseTaxes(int $purchaseId, iterable $lines, array $componentIdByCode, Carbon $at): void
    {
        foreach ($lines as $line) {
            if ($line->amount === 0) {
                continue;
            }
            DB::table('purchase_taxes')->insert(self::snapshot($line, $componentIdByCode, $at) + [
                'purchase_id' => $purchaseId,
                'is_recoverable' => $line->component->isRecoverable,
            ]);
        }
    }

    public static function readSaleTaxes(int $saleId): Collection
    {
        return DB::table('sale_taxes')->where('sale_id', $saleId)->orderBy('id')->get();
    }

    public static function readPurchaseTaxes(int $purchaseId): Collection
    {
        return DB::table('purchase_taxes')->where('purchase_id', $purchaseId)->orderBy('id')->get();
    }

    /**
     * Keep the old single-rate settings columns (tax_rate_bp, tax_label) in
     * step with the components, so anything still reading them sees the
     * all-in rate and, when only one tax is active, its name.
     */
    public static function syncDerivedSettings(?Carbon $at = null): void
    {
        // Lock the row being written first, then read the components with a
        // locking read: that sees the latest committed rows, not this
        // transaction's snapshot, so two tax edits cannot leave a stale total.
        DB::table('business_settings')->where('id', 1)->lockForUpdate()->value('id');
        $components = self::activeRows(locking: true)->map(self::component(...))->all();

        DB::table('business_settings')->where('id', 1)->update([
            'tax_rate_bp' => TaxComponents::totalRateBp($components),
            'tax_label' => count($components) === 1 ? mb_substr($components[0]->name, 0, 20) : 'Tax',
            'updated_at' => ($at ?? Carbon::now())->format('Y-m-d H:i:s.v'),
        ]);
    }

    /** The all-in rate of the active components, whether or not tax is switched on. */
    public static function allInRateBp(): int
    {
        return TaxComponents::totalRateBp(self::activeRows()->map(self::component(...))->all());
    }

    /** Active liability accounts that are not headings: where a tax may be held. */
    public static function holdingAccounts(): Collection
    {
        $headings = DB::table('accounts')->whereNotNull('parent_id')->distinct()->pluck('parent_id')->map(fn ($id): int => (int) $id)->all();

        return DB::table('accounts')
            ->where('type', AccountType::Liability->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->reject(fn (object $a): bool => in_array((int) $a->id, $headings, true))
            ->values();
    }

    public static function list(bool $includeInactive = true): Collection
    {
        $query = DB::table('tax_components');
        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return $query->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @param  array{code: string, name: string, rateBp: int, basis: TaxBasis, isRecoverable: bool, glAccountId: int, sortOrder?: int, isActive?: bool}  $input */
    public static function create(array $input, Actor $actor): int
    {
        $clean = self::clean($input);

        return Tx::run(function () use ($clean, $actor): int {
            self::serialiseTaxEdits();
            self::assertHoldingAccount($clean['gl_account_id']);
            if (DB::table('tax_components')->where('code', $clean['code'])->exists()) {
                throw new ConflictError("A tax with the code \"{$clean['code']}\" already exists.");
            }

            $now = Carbon::now();
            $id = (int) DB::table('tax_components')->insertGetId($clean + [
                'created_at' => $now->format('Y-m-d H:i:s.v'),
                'updated_at' => $now->format('Y-m-d H:i:s.v'),
            ]);
            AuditService::write(
                AuditAction::Create,
                'tax_component',
                "Added {$clean['name']} at ".self::percent($clean['rate_bp']).'%',
                entityId: $id,
                actor: $actor,
                metadata: self::camel($clean),
                at: $now,
            );
            self::syncDerivedSettings($now);

            return $id;
        });
    }

    /** @param  array{code: string, name: string, rateBp: int, basis: TaxBasis, isRecoverable: bool, glAccountId: int, sortOrder?: int, isActive?: bool}  $input */
    public static function update(int $id, array $input, Actor $actor): void
    {
        $clean = self::clean($input);

        Tx::run(function () use ($id, $clean, $actor): void {
            self::serialiseTaxEdits();
            $existing = DB::table('tax_components')->where('id', $id)->lockForUpdate()->first();
            if ($existing === null) {
                throw new NotFoundError('Tax', $id);
            }
            self::assertHoldingAccount($clean['gl_account_id']);
            if (DB::table('tax_components')->where('code', $clean['code'])->where('id', '<>', $id)->exists()) {
                throw new ConflictError("A tax with the code \"{$clean['code']}\" already exists.");
            }

            $now = Carbon::now();
            DB::table('tax_components')->where('id', $id)->update($clean + ['updated_at' => $now->format('Y-m-d H:i:s.v')]);
            AuditService::write(
                AuditAction::Update,
                'tax_component',
                self::describeChange($existing, $clean),
                entityId: $id,
                actor: $actor,
                metadata: ['before' => self::camelRow($existing), 'after' => self::camel($clean)],
                at: $now,
            );
            self::syncDerivedSettings($now);
        });
    }

    /** How many sale and purchase tax lines name this component. */
    public static function usage(int $id): int
    {
        return DB::table('sale_taxes')->where('component_id', $id)->count()
            + DB::table('purchase_taxes')->where('component_id', $id)->count();
    }

    /** Switched off, never deleted: past documents name it. */
    public static function setActive(int $id, bool $isActive, Actor $actor): void
    {
        Tx::run(function () use ($id, $isActive, $actor): void {
            self::serialiseTaxEdits();
            $existing = DB::table('tax_components')->where('id', $id)->lockForUpdate()->first();
            if ($existing === null) {
                throw new NotFoundError('Tax', $id);
            }
            if ((bool) $existing->is_active === $isActive) {
                return;
            }

            $now = Carbon::now();
            DB::table('tax_components')->where('id', $id)->update(['is_active' => $isActive, 'updated_at' => $now->format('Y-m-d H:i:s.v')]);
            AuditService::write(AuditAction::Update, 'tax_component', $existing->name.' '.($isActive ? 'switched on' : 'switched off'), entityId: $id, actor: $actor, at: $now);
            self::syncDerivedSettings($now);
        });
    }

    /**
     * One tax edit at a time: each one rewrites the derived settings, and the
     * duplicate-code check must see the edit before it. Taken first, so the
     * settings row is the only lock order a tax edit uses.
     */
    private static function serialiseTaxEdits(): void
    {
        DB::table('business_settings')->where('id', 1)->lockForUpdate()->value('id');
    }

    private static function activeRows(bool $locking = false): Collection
    {
        $query = DB::table('tax_components');
        if ($locking) {
            $query->sharedLock();
        }

        return $query
            ->where('is_active', true)
            ->where('rate_bp', '>', 0)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * D34: an edit re-prices the whole document with today's tax setup. Refuse it
     * when that setup differs from the one the document was posted under, or
     * fixing a note would change its tax, its total and what is owed.
     * shortcut: a component that came to 0 on the document is not stored, so a
     * document where any tax rounded to 0 (a line of a few pesewas, a free one)
     * is refused too. That errs towards refusing; compare against what each
     * component would charge on the original net if it ever gets in the way.
     */
    public static function assertUnchangedSince(string $taxTable, string $foreignKey, int $documentId, ?bool $wasInclusive, string $documentNo): void
    {
        $withRecovery = $taxTable === 'purchase_taxes';
        // In posting order, not by code: a NET_PLUS_LEVIES component's amount depends on what comes before it.
        $then = DB::table($taxTable)->where($foreignKey, $documentId)->orderBy('id')->get()
            ->map(fn (object $r): string => "{$r->code}:{$r->rate_bp}:{$r->basis}".($withRecovery ? ':'.(int) $r->is_recoverable : ''))->all();
        $profile = self::profile();
        $now = collect($profile['components'])->filter(fn (TaxComponent $c): bool => $c->rateBp > 0)
            ->map(fn (TaxComponent $c): string => "{$c->code}:{$c->rateBp}:{$c->basis->value}".($withRecovery ? ':'.(int) $c->isRecoverable : ''))->values()->all();
        $inclusiveChanged = $wasInclusive !== null && $then !== [] && $wasInclusive !== $profile['inclusive'];
        if ($then !== $now || $inclusiveChanged) {
            throw new ConflictError("The tax settings have changed since {$documentNo} was saved, so editing it would re-price its tax. Void it and enter it again instead.");
        }
    }

    private static function component(object $row): TaxComponent
    {
        return new TaxComponent($row->code, $row->name, (int) $row->rate_bp, TaxBasis::from($row->basis), (bool) $row->is_recoverable);
    }

    private static function snapshot(TaxLine $line, array $componentIdByCode, Carbon $at): array
    {
        return [
            'component_id' => $componentIdByCode[$line->component->code] ?? null,
            'code' => $line->component->code,
            'name' => $line->component->name,
            'rate_bp' => $line->component->rateBp,
            'basis' => $line->component->basis->value,
            'amount_minor' => $line->amount,
            'created_at' => $at->format('Y-m-d H:i:s.v'),
        ];
    }

    /** @return array{code: string, name: string, rate_bp: int, basis: string, is_recoverable: bool, gl_account_id: int, sort_order: int, is_active: bool} */
    private static function clean(array $input): array
    {
        $code = strtoupper(trim($input['code']));
        $name = trim($input['name']);
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new ValidationError('A tax code is up to 20 letters, digits or underscores, like VAT or NHIL.', ['code' => $input['code']]);
        }
        if ($name === '') {
            throw new ValidationError('Enter a name for the tax.');
        }
        if ($input['rateBp'] < 0 || $input['rateBp'] > 100_000) {
            throw new ValidationError('A tax rate must be between 0% and 1000%.', ['rateBp' => $input['rateBp']]);
        }

        return [
            'code' => $code,
            'name' => $name,
            'rate_bp' => $input['rateBp'],
            'basis' => $input['basis']->value,
            'is_recoverable' => $input['isRecoverable'],
            'gl_account_id' => $input['glAccountId'],
            'sort_order' => $input['sortOrder'] ?? 0,
            'is_active' => $input['isActive'] ?? true,
        ];
    }

    private static function assertHoldingAccount(int $glAccountId): void
    {
        $account = DB::table('accounts')->where('id', $glAccountId)->first(['type', 'is_active', 'name']);
        if ($account === null) {
            throw new NotFoundError('Account', $glAccountId);
        }
        if ($account->type !== AccountType::Liability->value) {
            throw new ValidationError("Tax collected has to be held in a liability account. \"{$account->name}\" is ".strtolower($account->type).'.', ['glAccountId' => $glAccountId]);
        }
        if (! $account->is_active) {
            throw new ValidationError("\"{$account->name}\" is archived, so tax cannot be held in it.", ['glAccountId' => $glAccountId]);
        }
    }

    private static function describeChange(object $before, array $after): string
    {
        $changes = [];
        if ((int) $before->rate_bp !== $after['rate_bp']) {
            $changes[] = 'rate '.self::percent((int) $before->rate_bp).'% to '.self::percent($after['rate_bp']).'%';
        }
        if ($before->basis !== $after['basis']) {
            $changes[] = $after['basis'] === TaxBasis::NetPlusLevies->value ? 'now charged on the value plus the levies before it' : 'now charged on the value alone';
        }
        if ($before->name !== $after['name']) {
            $changes[] = "renamed to {$after['name']}";
        }
        if ($before->code !== $after['code']) {
            $changes[] = "code {$before->code} to {$after['code']}";
        }
        if ((bool) $before->is_active !== $after['is_active']) {
            $changes[] = $after['is_active'] ? 'switched on' : 'switched off';
        }
        if ((bool) $before->is_recoverable !== $after['is_recoverable']) {
            $changes[] = $after['is_recoverable'] ? 'reclaimable on purchases' : 'no longer reclaimable';
        }
        if ((int) $before->gl_account_id !== $after['gl_account_id']) {
            $changes[] = 'held in a different account';
        }

        return $changes === [] ? "{$before->name}: saved with nothing changed" : "{$before->name}: ".implode(', ', $changes);
    }

    /** Basis points as the old app printed them in the log: 250 → "2.5", 1500 → "15". */
    private static function percent(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
    }

    /** A tax_components row as the old app's JSON.stringify wrote it: camelCase, booleans, ISO times. */
    private static function camelRow(object $row): array
    {
        $iso = fn (?string $t): ?string => $t === null ? null : Carbon::parse($t, 'UTC')->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name, 'rateBp' => (int) $row->rate_bp,
            'basis' => $row->basis, 'isRecoverable' => (bool) $row->is_recoverable, 'glAccountId' => (int) $row->gl_account_id,
            'sortOrder' => (int) $row->sort_order, 'isActive' => (bool) $row->is_active,
            'createdAt' => $iso($row->created_at), 'updatedAt' => $iso($row->updated_at),
        ];
    }

    /** The old metadata used the camelCase field names. */
    private static function camel(array $clean): array
    {
        return [
            'code' => $clean['code'], 'name' => $clean['name'], 'rateBp' => $clean['rate_bp'], 'basis' => $clean['basis'],
            'isRecoverable' => $clean['is_recoverable'], 'glAccountId' => $clean['gl_account_id'],
            'sortOrder' => $clean['sort_order'], 'isActive' => $clean['is_active'],
        ];
    }
}
