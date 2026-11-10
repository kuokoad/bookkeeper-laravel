<?php

declare(strict_types=1);

/*
 * Port of the old tests/app/motion.test.ts: the rules motion in this app has to
 * keep. They hold when written and quietly stop holding three features later,
 * so they are checked rather than trusted.
 */

function appCss(): string
{
    return (string) file_get_contents(resource_path('css/app.css'));
}

it('has a reduced-motion block', function () {
    expect(appCss())->toMatch('/@media \(prefers-reduced-motion: reduce\)/');
});

it('switches the entrance animations off entirely under reduced motion, not merely shortens them', function () {
    $css = appCss();
    $start = (int) strpos($css, '@media (prefers-reduced-motion: reduce)');
    // Stop at the next @media: the print block below makes the same rule and would hide its loss here.
    $block = substr($css, $start, (int) strpos($css, '@media', $start + 1) - $start);
    // A shorter duration still lets the element jump from its offset start.
    expect($block)->toMatch('/\.motion-page,[\s\S]{0,120}animation: none !important/');
});

it('prints the final state, never mid-entrance', function () {
    $css = appCss();
    $block = substr($css, (int) strpos($css, '@media print'));
    expect($block)->toMatch('/animation: none !important/')
        ->and($block)->toMatch('/opacity: 1 !important/');
});

it('never puts a motion class on a figure', function () {
    // `tabular` marks money and quantities; a number sliding into place reads as uncertain.
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS));
    foreach ([...iterator_to_array($files, false), ...glob(app_path('{,*/,*/*/}*.php'), GLOB_BRACE)] as $file) {
        preg_match_all('/class="([^"]*)"|\'([^\']*)\'/', (string) file_get_contents((string) $file), $m);
        foreach ([...$m[1], ...$m[2]] as $value) {
            if (str_contains($value, 'tabular') && str_contains($value, 'motion-')) {
                $offenders[] = "{$file}: {$value}";
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('defines no looping animation', function () {
    expect(appCss())->not->toMatch('/animation-iteration-count:\s*infinite/')
        ->and(appCss())->not->toMatch('/animation:[^;]*\binfinite\b/');
});

it('runs no entrance longer than a fifth of a second', function () {
    preg_match_all('/animation:\s*\w+\s+(\d+)ms/', appCss(), $m);
    expect($m[1])->not->toBeEmpty();
    // Someone is standing at a till with a queue behind them.
    foreach ($m[1] as $ms) {
        expect((int) $ms)->toBeLessThanOrEqual(200);
    }
});
