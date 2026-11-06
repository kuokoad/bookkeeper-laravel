<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Auth\Principal;
use App\Domain\Errors\DomainError;
use App\Services\ProductImportService;
use App\Support\Clock;
use App\Support\Spreadsheet;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The old products/import page (import-form.tsx, productImportAction): read
 * a file, check which column feeds each field, preview, then import. Only
 * import writes, and ProductImportService re-runs the preview from the same
 * cells first.
 */
#[Title('Import products')]
final class Import extends Component
{
    use WithFileUploads;

    /** Same limits on upload and on every later step, so a sheet that previews can always be imported. */
    private const MAX_CELL_LENGTH = 1_000;

    private const MAX_COLUMNS = 200;

    private const MAX_FILE_BYTES = 2 * 1024 * 1024;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    /** The raw cells, carried unchanged so the import re-reads exactly what was previewed. */
    #[Locked]
    public ?array $cells = null;

    #[Locked]
    public string $fileName = '';

    /** @var array<string, string> field => column index as text, '' for "Not imported" */
    public array $map = [];

    public string $packUnit = '';

    /** What the preview on screen was built from; null when there is none. */
    #[Locked]
    public ?array $previewedMap = null;

    #[Locked]
    public string $previewedPackUnit = '';

    public function read(): void
    {
        $this->authorize('products.create');
        $this->resetErrorBag();
        // A failed upload must not leave the last file's columns on screen under the new error.
        $this->reset('cells', 'fileName', 'map', 'packUnit', 'previewedMap', 'previewedPackUnit');
        if (! $this->file instanceof TemporaryUploadedFile || $this->file->getSize() === 0) {
            $this->addError('form', 'Choose a file to import.');

            return;
        }
        if ($this->file->getSize() > self::MAX_FILE_BYTES) {
            $this->addError('form', 'That file is over 2 MB. Split it into smaller files.');

            return;
        }
        try {
            $cells = Spreadsheet::read($this->file->getClientOriginalName(), (string) file_get_contents($this->file->getRealPath()));
        } catch (DomainError $e) {
            $this->addError('form', $e->userMessage);

            return;
        }
        if (count($cells) < 2) {
            $this->addError('form', 'That sheet has no rows under the headings.');

            return;
        }
        $problem = self::cellsProblem($cells);
        if ($problem !== null) {
            $this->addError('form', $problem);

            return;
        }

        $this->cells = $cells;
        $this->fileName = mb_substr($this->file->getClientOriginalName(), 0, 200);
        $this->packUnit = 'pack';
        $guess = ProductImportService::guessColumns($cells[0]);
        $this->map = array_map(fn (string $key): string => isset($guess[$key]) ? (string) $guess[$key] : '', array_combine(array_keys(ProductImportService::IMPORT_FIELDS), array_keys(ProductImportService::IMPORT_FIELDS)));
        $this->file = null;
        if (! isset($guess['name'])) {
            $this->addError('form', 'Choose the column that holds the product name.');

            return;
        }
        $this->showPreview();
    }

    public function preview(): void
    {
        $this->authorize('products.create');
        $this->resetErrorBag();
        $this->showPreview();
    }

    public function import(): void
    {
        $this->authorize('products.create');
        $this->resetErrorBag();
        if ($this->cells === null) {
            $this->addError('form', 'The uploaded sheet was lost. Choose the file again.');

            return;
        }
        $map = $this->columnMap();
        $packUnit = $this->packUnitText();
        // Import only what was on screen: a column or pack unit changed after the preview would save figures nobody saw.
        if ($this->previewedMap === null || $map !== $this->previewedMap || $packUnit !== $this->previewedPackUnit) {
            if ($this->showPreview()) {
                $this->addError('form', 'The columns changed since the preview. Check the preview below, then import.');
            }

            return;
        }
        // Opening stock is a stock adjustment, so it needs the right to record one.
        if (isset($map['openingQty']) && Gate::denies('inventory.create')) {
            $this->previewedMap = null;
            $this->addError('form', 'You can add products but not record stock. Set Opening quantity to "Not imported" to import the products alone.');

            return;
        }
        try {
            $result = ProductImportService::importProducts($this->cells, $map, $packUnit, Clock::today(), Principal::load((int) auth()->id())->actor());
        } catch (DomainError $e) {
            $this->previewedMap = null;
            $this->addError('form', $e->userMessage);

            return;
        }

        $count = $result['created'];
        session()->flash('status', "Imported {$count} product".($count === 1 ? '' : 's').'. '.($result['adjustmentNo'] !== null
            ? 'Their opening stock is recorded as one stock adjustment, under Inventory.'
            : 'They start with no stock — record a stock adjustment to enter opening stock.'));
        $this->redirect('/products');
    }

    /** Re-preview with the current choices; false (and the error shown) when the service refuses them. */
    private function showPreview(): bool
    {
        if ($this->cells === null) {
            $this->addError('form', 'The uploaded sheet was lost. Choose the file again.');

            return false;
        }
        $map = $this->columnMap();
        $packUnit = $this->packUnitText();
        try {
            ProductImportService::previewProductImport($this->cells, $map, $packUnit);
        } catch (DomainError $e) {
            $this->previewedMap = null;
            $this->addError('form', $e->userMessage);

            return false;
        }
        $this->packUnit = $packUnit;
        $this->previewedMap = $map;
        $this->previewedPackUnit = $packUnit;

        return true;
    }

    /** Column choices; anything not a real column index means "not imported". @return array<string, int> */
    private function columnMap(): array
    {
        $columns = max(array_map('count', $this->cells ?? [[]]));
        $map = [];
        foreach (array_keys(ProductImportService::IMPORT_FIELDS) as $key) {
            $text = $this->map[$key] ?? '';
            if (is_string($text) && ctype_digit($text) && (int) $text < $columns) {
                $map[$key] = (int) $text;
            }
        }

        return $map;
    }

    private function packUnitText(): string
    {
        return mb_substr(trim($this->packUnit), 0, 20);
    }

    /** Why these cells cannot be imported, in words, or null when they can. */
    private static function cellsProblem(array $cells): ?string
    {
        if (count($cells) > ProductImportService::MAX_IMPORT_ROWS + 1) {
            return 'That sheet has more than '.ProductImportService::MAX_IMPORT_ROWS.' rows. Split it into smaller files.';
        }
        foreach ($cells as $row) {
            if (count($row) > self::MAX_COLUMNS) {
                return 'That sheet uses more than '.self::MAX_COLUMNS.' columns. Delete the unused columns and try again.';
            }
        }
        foreach ($cells as $i => $row) {
            foreach ($row as $cell) {
                if (mb_strlen((string) $cell) > self::MAX_CELL_LENGTH) {
                    return 'Row '.($i + 1).' has a cell longer than '.self::MAX_CELL_LENGTH.' characters.';
                }
            }
        }

        return null;
    }

    /** "C — Price", as Excel names the column. */
    public static function columnName(int $index, string $heading): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + (($n - 1) % 26)).$letters;
        }

        return trim($heading) !== '' ? "{$letters} — ".trim($heading) : "{$letters} (no heading)";
    }

    public function render(): View
    {
        $preview = null;
        if ($this->cells !== null && $this->previewedMap !== null) {
            try {
                $preview = ProductImportService::previewProductImport($this->cells, $this->previewedMap, $this->previewedPackUnit);
            } catch (DomainError) {
                $preview = null;
            }
        }

        return view('livewire.products.import', [
            'preview' => $preview,
            'header' => $this->cells[0] ?? [],
            'columns' => $this->cells === null ? 0 : max(array_map('count', $this->cells)),
        ]);
    }
}
