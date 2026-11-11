<?php

declare(strict_types=1);

/*
| The race tests' own check (run.php expectRoundsRaced), on made-up timings:
| one round lost to a busy PC must not fail a correct test, and a run that
| never raced must still fail, naming the rounds that did not.
*/

use PHPUnit\Framework\ExpectationFailedException;

require_once __DIR__.'/run.php';

/** Four workers per round; $serial rounds have one worker start only after another finished. */
function racedRows(int $rounds, array $serial): array
{
    $rows = [];
    for ($round = 1; $round <= $rounds; $round++) {
        for ($w = 1; $w <= 4; $w++) {
            $late = in_array($round, $serial, true) && $w === 4;
            $rows[] = ['round' => $round, 'start' => $round * 10 + ($late ? 0.05 : 0.0), 'end' => $round * 10 + ($late ? 0.06 : 0.01 * $w)];
        }
    }

    return $rows;
}

it('passes a run where a busy PC lost rounds, even more than half of them', function () {
    expectRoundsRaced(racedRows(12, [7]), 4);
    expectRoundsRaced(racedRows(12, [1, 2, 3, 4, 5, 6, 7, 8, 9]), 4);
});

it('fails a run that hardly raced at all, naming the rounds', function () {
    expect(fn () => expectRoundsRaced(racedRows(12, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]), 4))
        ->toThrow(ExpectationFailedException::class, 'only 2 of 12 rounds ran at once; rounds 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 ran one after another');
});

it('still fails a round that a worker never reported', function () {
    $rows = racedRows(12, []);
    array_pop($rows);

    expect(fn () => expectRoundsRaced($rows, 4))->toThrow(ExpectationFailedException::class, 'round 12: not every worker reported');
});
