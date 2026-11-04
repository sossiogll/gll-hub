<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Auth;

use Gll\Hub\Contracts\Package\Credential;
use Gll\Hub\Contracts\Package\Setting;

/** A way to sign in, shown as a button on the sign-in page. */
final readonly class AuthDefinition
{
    /**
     * @param list<Setting> $settings e.g. extra scopes
     * @param list<Credential> $credentials e.g. the OAuth client id and secret
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $settings = [],
        public array $credentials = [],
    ) {
    }
}
