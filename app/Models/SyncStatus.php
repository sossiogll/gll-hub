<?php

declare(strict_types=1);

namespace App\Models;

/** Where a source instance stands, as the last run left it. */
enum SyncStatus: string
{
    /** Never ran. */
    case Pending = 'pending';
    case Ok = 'ok';
    /** Ran to the end, but refused some entries; the admin shows why. */
    case Partial = 'partial';
    /** The service was unavailable or asked to slow down; a new attempt is scheduled. */
    case Retrying = 'retrying';
    /** Needs the admin: a credential or a setting is wrong. Nothing retries until it is fixed. */
    case Failing = 'failing';
    /** The run broke on something nobody expected, or gave up retrying. */
    case Failed = 'failed';
}
