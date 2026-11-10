<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The insert-only audit log: who did what, when. There is no update or
 * delete here, and the database refuses both. A port of the old
 * audit.service.ts.
 */
final class AuditService
{
    /** Keys whose values never reach the log, compared without case, "_" or "-". */
    private const REDACTED_KEYS = ['password', 'newpassword', 'currentpassword', 'confirmpassword', 'pin', 'passwordhash', 'pinhash', 'token', 'sessiontoken', 'secret'];

    /** @param  array<string, mixed>|null  $metadata */
    public static function write(
        AuditAction $action,
        string $entityType,
        string $summary,
        string|int|null $entityId = null,
        ?Actor $actor = null,
        ?array $metadata = null,
        ?Carbon $at = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $username = null,
    ): void {
        DB::table('audit_logs')->insert([
            'user_id' => $actor?->id,
            // A failed sign-in has no actor, only the name that was typed (cut to the column).
            'username' => $actor?->username ?? ($username === null ? null : mb_substr($username, 0, 40)),
            'action' => $action->value,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'summary' => $summary,
            // An empty object is "{}", as JSON.stringify wrote it, not PHP's "[]".
            'metadata' => $metadata === null
                ? null
                : json_encode($metadata === [] ? new \stdClass : self::redact($metadata), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => ($at ?? Carbon::now())->format('Y-m-d H:i:s.v'),
        ]);
    }

    /** Replace secrets with "[redacted]", however deeply nested (to six levels, as the old app). */
    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if (is_object($value) && ! $value instanceof \UnitEnum) {
            // Any object, as the old app's typeof === 'object' did: its public data, then redacted.
            $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        }
        if ($depth > 6 || ! is_array($value)) {
            return $value;
        }

        $output = [];
        foreach ($value as $key => $item) {
            $normalised = str_replace(['_', '-'], '', strtolower((string) $key));
            $output[$key] = in_array($normalised, self::REDACTED_KEYS, true) ? '[redacted]' : self::redact($item, $depth + 1);
        }

        return $output;
    }

    /**
     * @param  array{userId?: int, action?: AuditAction, entityType?: string, entityId?: string, search?: string, from?: Carbon, to?: Carbon, limit?: int, offset?: int}  $query
     */
    public static function list(array $query = []): Collection
    {
        return self::filtered($query)
            ->orderByDesc('audit_logs.created_at')
            ->orderByDesc('audit_logs.id')
            ->limit(min($query['limit'] ?? 50, 500))
            ->offset($query['offset'] ?? 0)
            ->get();
    }

    /** Counted with the same conditions as list(), so a page and its total always agree. */
    public static function count(array $query = []): int
    {
        return self::filtered($query)->count();
    }

    /**
     * Every edit of one document, newest first: who, when, why, and what it
     * said before and after (D34).
     *
     * @return list<array{at: string, username: ?string, reason: string, before: array, after: array}>
     */
    public static function editHistory(string $entityType, int $entityId): array
    {
        return DB::table('audit_logs')
            ->where('audit_logs.entity_type', $entityType)
            ->where('audit_logs.entity_id', (string) $entityId)
            ->where('audit_logs.action', AuditAction::Update->value)
            ->whereRaw('JSON_EXTRACT(`audit_logs`.`metadata`, ?) = true', ['$.edit'])
            ->orderByDesc('audit_logs.created_at')
            ->orderByDesc('audit_logs.id')
            ->get(['audit_logs.created_at', 'audit_logs.username', 'audit_logs.metadata'])
            ->map(function (object $row): array {
                $meta = json_decode((string) $row->metadata, true, flags: JSON_THROW_ON_ERROR);

                return ['at' => (string) $row->created_at, 'username' => $row->username, 'reason' => (string) $meta['reason'], 'before' => $meta['before'], 'after' => $meta['after']];
            })
            ->all();
    }

    /** @return list<string> */
    public static function entityTypes(): array
    {
        return DB::table('audit_logs')->distinct()->orderBy('entity_type')->pluck('entity_type')->all();
    }

    private static function filtered(array $query): Builder
    {
        $builder = DB::table('audit_logs');
        if (isset($query['userId'])) {
            $builder->where('audit_logs.user_id', $query['userId']);
        }
        if (isset($query['action'])) {
            $builder->where('audit_logs.action', $query['action']->value);
        }
        if (isset($query['entityType'])) {
            $builder->where('audit_logs.entity_type', $query['entityType']);
        }
        if (isset($query['entityId'])) {
            $builder->where('audit_logs.entity_id', $query['entityId']);
        }
        if (isset($query['from'])) {
            $builder->where('audit_logs.created_at', '>=', $query['from']->format('Y-m-d H:i:s.v'));
        }
        if (isset($query['to'])) {
            $builder->where('audit_logs.created_at', '<=', $query['to']->format('Y-m-d H:i:s.v'));
        }

        $search = trim($query['search'] ?? '');
        if ($search !== '') {
            // Wildcards in the term are literal, so "%" does not mean "every row".
            $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
            $builder->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(`audit_logs`.`summary`) LIKE ?', [$term])
                ->orWhereRaw("LOWER(COALESCE(`audit_logs`.`username`, '')) LIKE ?", [$term]));
        }

        return $builder;
    }
}
