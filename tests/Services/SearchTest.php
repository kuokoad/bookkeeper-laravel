<?php

declare(strict_types=1);

/*
| Port of the old tests/services/search.test.ts, plus a batch, receipt and
| journal hit and "reads never write".
*/

use App\Auth\Principal;
use App\Enums\UserRole;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\SearchService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shop;

beforeEach(function () {
    $this->actor = Shop::owner();
    $this->owner = new Principal($this->actor->id, 'kwame', 'Kwame Owusu', UserRole::Owner);
    $this->productsOnly = new Principal(2, 'ama', 'Ama', UserRole::Staff, ['products' => ['canView' => true, 'canCreate' => false, 'canEdit' => false, 'canVoid' => false]]);

    CatalogService::createProduct(['name' => 'Milo Tin 400g', 'sku' => 'MILO-400', 'costPrice' => 1_000, 'sellingPrice' => 1_400, 'unit' => 'tin'], $this->actor);
    CatalogService::createProduct(['name' => 'Rice 5kg', 'sku' => 'RICE-5', 'costPrice' => 5_000, 'sellingPrice' => 6_000, 'unit' => 'bag'], $this->actor);
    CustomerService::createCustomer(['name' => 'Ama Serwaa', 'phone' => '024 111 2222'], $this->actor);
    SupplierService::createSupplier(['name' => 'Madina Market Wholesale', 'phone' => '030 999 8888'], $this->actor);
});

function titles(array $results): array
{
    return array_merge([], ...array_map(fn (array $g): array => array_column($g['hits'], 'title'), $results['groups']));
}

function labels(array $results): array
{
    return array_column($results['groups'], 'label');
}

describe('finding things', function () {
    it('matches a product by name and by its code, ignoring case', function () {
        expect(titles(SearchService::search('Milo', $this->owner)))->toContain('Milo Tin 400g')
            ->and(titles(SearchService::search('RICE-5', $this->owner)))->toContain('Rice 5kg')
            ->and(titles(SearchService::search('milo', $this->owner)))->toContain('Milo Tin 400g')
            ->and(titles(SearchService::search('MILO', $this->owner)))->toContain('Milo Tin 400g');
    });

    it('matches on part of a word, and a customer by phone number', function () {
        expect(titles(SearchService::search('adina', $this->owner)))->toContain('Madina Market Wholesale')
            ->and(titles(SearchService::search('111 2222', $this->owner)))->toContain('Ama Serwaa');
    });

    it('groups results by what they are', function () {
        expect(labels(SearchService::search('milo', $this->owner)))->toBe(['Products'])
            // "ma" is in the customer and the supplier, in neither product.
            ->and(labels(SearchService::search('ma', $this->owner)))->toBe(['Customers', 'Suppliers']);
    });

    it('finds a batch, a receipt and a journal entry, each with its figures', function () {
        $milk = Shop::product($this->actor, 'Fresh Milk 1L', 900, 1_200);
        Shop::openingStock($this->actor, $milk, 10_000, 9_000);
        Shop::sell($this->actor, [[$milk, 2_000]], [['paymentAccountId' => Shop::paymentAccount(), 'amount' => 2_400]]);

        $batch = SearchService::search('fresh milk', $this->owner)['groups'][1];
        $receiptNo = DB::table('sales')->orderByDesc('id')->value('receipt_no');
        $receipt = SearchService::search($receiptNo, $this->owner);
        expect($batch['label'])->toBe('Batches')
            ->and($batch['hits'][0]['detail'])->toBe('Fresh Milk 1L · 8 pcs left · no date')
            ->and(labels($receipt))->toContain('Receipts', 'Journal entries')
            ->and($receipt['groups'][0]['hits'][0]['amount'])->toBe(2_400);
    });
});

describe('refusing to be useless', function () {
    it('says nothing for a single character, an empty search, or no match', function () {
        expect(SearchService::search('a', $this->owner))->toBe(['query' => 'a', 'groups' => [], 'total' => 0, 'truncated' => false])
            ->and(SearchService::search('', $this->owner)['total'])->toBe(0)
            ->and(SearchService::search('   ', $this->owner)['total'])->toBe(0)
            ->and(SearchService::search('zzzznothing', $this->owner)['total'])->toBe(0);
    });

    it('caps each group and says so', function () {
        foreach (range(1, 10) as $n) {
            CatalogService::createProduct(['name' => "Soap {$n}", 'costPrice' => 100, 'sellingPrice' => 150], $this->actor);
        }
        $results = SearchService::search('soap', $this->owner);
        expect($results['total'])->toBe(8)->and($results['truncated'])->toBeTrue();
    });
});

describe('the wildcards in a search term', function () {
    beforeEach(function () {
        foreach (['Cloth 50% cotton', 'Cloth 5000 thread', 'Tape A_B joiner', 'Tape AXB joiner', 'Pipe 1\\2 inch'] as $name) {
            CatalogService::createProduct(['name' => $name, 'costPrice' => 500, 'sellingPrice' => 800], $this->actor);
        }
    });

    it('treats % and _ as literals, not "match everything"', function () {
        expect(SearchService::search('%', $this->owner)['total'])->toBe(0)
            ->and(SearchService::search('%%', $this->owner)['total'])->toBe(0)
            ->and(SearchService::search('__', $this->owner)['total'])->toBe(0);
    });

    it('finds the one with a literal percent sign, underscore or backslash', function () {
        expect(titles(SearchService::search('50%', $this->owner)))->toBe(['Cloth 50% cotton'])
            ->and(titles(SearchService::search('A_B', $this->owner)))->toBe(['Tape A_B joiner'])
            ->and(titles(SearchService::search('1\\2', $this->owner)))->toBe(['Pipe 1\\2 inch'])
            ->and(titles(SearchService::search('Cloth', $this->owner)))->toContain('Cloth 50% cotton', 'Cloth 5000 thread');
    });
});

describe('permission', function () {
    it('searches only the record types the person may see', function () {
        $results = SearchService::search('ma', $this->productsOnly);
        expect($results['total'])->toBe(0)
            ->and(labels($results))->not->toContain('Customers')
            ->and(titles($results))->not->toContain('Ama Serwaa');
    });

    it('still finds what that person IS allowed to see, and nothing for nobody', function () {
        $nobody = new Principal(3, 'new', 'New Person', UserRole::Staff);
        expect(labels(SearchService::search('milo', $this->productsOnly)))->toBe(['Products'])
            ->and(SearchService::search('ma', $nobody)['total'])->toBe(0);
    });

    it('never queries a type the person cannot see', function () {
        DB::enableQueryLog();
        SearchService::search('ma', $this->productsOnly);
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        expect($sql)->not->toContain('customers')->and($sql)->not->toContain('suppliers')->and($sql)->not->toContain('journal_entries');
    });
});

it('reads and never writes', function () {
    DB::enableQueryLog();
    SearchService::search('milo', $this->owner);
    foreach (DB::getQueryLog() as $q) {
        expect(strtolower(ltrim($q['query'])))->toStartWith('select');
    }
});
