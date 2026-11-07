<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The shop-wide look. Labels are the old lib/look.ts LOOK_LABELS; Jinu
 * (DESIGN-jinu.md), Quikbooks and Mamut are new here, so imported shops never carry them.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum Look: string
{
    use HasValues;

    case Default = 'default';
    case Ledger = 'ledger';
    case Classic = 'classic';
    case Jinu = 'jinu';
    case Quikbooks = 'quikbooks';
    case Mamut = 'mamut';

    public function label(): string
    {
        return match ($this) {
            self::Default => 'Standard',
            self::Ledger => 'Ledger',
            self::Classic => 'Classic',
            self::Jinu => 'Jinu',
            self::Quikbooks => 'Quikbooks',
            self::Mamut => 'Mamut',
        };
    }

    public function blurb(): string
    {
        return match ($this) {
            self::Default => 'Crisp and neutral, with a dark menu. Best in a bright shop.',
            self::Ledger => 'Warm and paper-like, the feel of the book this replaced.',
            self::Classic => 'Calm green on cool grey, as NunaBooks looked before the redesign.',
            self::Jinu => 'Pale green surroundings, white pages and a light menu: calm and precise.',
            self::Quikbooks => 'Grey and green, a light menu with a green marker: familiar accounting-software feel.',
            self::Mamut => 'Navy on cool grey, a pale menu and flat bordered sheets: plain, legible business software.',
        };
    }
}
