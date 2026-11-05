<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The shop-wide look. Labels are the old lib/look.ts LOOK_LABELS.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum Look: string
{
    use HasValues;

    case Default = 'default';
    case Ledger = 'ledger';
    case Classic = 'classic';

    public function label(): string
    {
        return match ($this) {
            self::Default => 'Standard',
            self::Ledger => 'Ledger',
            self::Classic => 'Classic',
        };
    }

    public function blurb(): string
    {
        return match ($this) {
            self::Default => 'Crisp and neutral, with a dark menu. Best in a bright shop.',
            self::Ledger => 'Warm and paper-like, the feel of the book this replaced.',
            self::Classic => 'Calm green on cool grey, as NunaBooks looked before the redesign.',
        };
    }
}
