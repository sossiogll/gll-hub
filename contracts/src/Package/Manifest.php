<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Package;

/**
 * Everything the core knows about a package, declared by the package itself.
 *
 * The core never infers what a package can do from its classes: names, roles,
 * settings and credentials are read from here; what each class can do is read
 * from that class's static definition() (SourceDefinition, ContentTypeDefinition…).
 */
final readonly class Manifest
{
    /**
     * @param string $name Composer-style name, e.g. "acme/books"
     * @param string $contracts the version of gllhub/contracts the package was built on, e.g. "1.0"
     * @param array<value-of<Role>, list<class-string>> $provides the classes for each role
     * @param list<Setting> $settings package-wide settings
     * @param list<Credential> $credentials package-wide secrets
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $version,
        public string $contracts,
        public array $provides,
        public array $settings = [],
        public array $credentials = [],
        public string $description = '',
    ) {
    }

    /** @return list<class-string> */
    public function classesFor(Role $role): array
    {
        return $this->provides[$role->value] ?? [];
    }
}
