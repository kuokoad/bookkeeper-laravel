<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domain\Errors\NotFoundError;
use App\Services\Reporting\LedgerService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The old (app)/accounting/journal/[id]/page.tsx: one entry and its lines. */
#[Title('Journal entry')]
final class Entry extends Component
{
    #[Locked]
    public int $entryId;

    public function mount(string $id): void
    {
        $value = filter_var($id, FILTER_VALIDATE_INT);
        abort_if($value === false || $value <= 0, 404);
        $this->entryId = $value;
    }

    /** Where the transaction behind this entry lives. */
    public static function sourceHref(string $sourceType, ?int $sourceId): ?string
    {
        if ($sourceId === null) {
            return null;
        }

        return match ($sourceType) {
            'SALE', 'SALE_RETURN' => "/sales/{$sourceId}",
            'PURCHASE', 'PURCHASE_RETURN' => "/purchases/{$sourceId}",
            'STOCK_ADJUSTMENT' => "/inventory/adjustments/{$sourceId}",
            'TRANSFER' => '/accounts/transfers',
            default => null,
        };
    }

    public function render(): View
    {
        try {
            $data = LedgerService::getJournalEntry($this->entryId);
        } catch (NotFoundError) {
            abort(404);
        }
        $entry = $data['entry'];

        return view('livewire.accounting.entry', [
            ...$data,
            'href' => self::sourceHref($entry['sourceType'], $entry['sourceId'] === null ? null : (int) $entry['sourceId']),
            'kind' => Journal::SOURCE_LABELS[$entry['sourceType']] ?? $entry['sourceType'],
        ]);
    }
}
