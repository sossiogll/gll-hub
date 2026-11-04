<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Package;

/** What a class of a package is for. A package may provide several roles. */
enum Role: string
{
    /** Defines a kind of content and its schema (a book, an event…). */
    case ContentType = 'content_type';

    /** Brings content in from somewhere else (a repository, a feed…). */
    case Source = 'source';

    /** Receives calls from an outside service and says what to sync again. */
    case Webhook = 'webhook';

    /** Signs people in through an outside account. */
    case Auth = 'auth';
}
