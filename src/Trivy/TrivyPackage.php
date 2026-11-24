<?php

declare(strict_types=1);

namespace Rustine\Trivy;

final readonly class TrivyPackage
{
    /**
     * @param list<string> $dependsOn IDs (`Packages[].ID`) of the dependencies
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public Relationship $relationship,
        public array $dependsOn,
    ) {
    }
}
