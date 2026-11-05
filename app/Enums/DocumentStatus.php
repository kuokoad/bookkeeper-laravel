<?php

namespace App\Enums;

/**
 * Status of a posted document. Nothing is deleted; it is voided.
 *
 * The database enforces these values with a CHECK built from this enum.
 */
enum DocumentStatus: string
{
    use HasValues;

    case Posted = 'POSTED';
    case Voided = 'VOIDED';
}
