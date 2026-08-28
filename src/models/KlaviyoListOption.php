<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\models;

/**
 * A Klaviyo list as exposed to Twig from List / Lists field types.
 */
final class KlaviyoListOption
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $optInProcess = null,
    ) {
    }
}
