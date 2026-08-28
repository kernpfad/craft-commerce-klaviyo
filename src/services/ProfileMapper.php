<?php

namespace kernpfad\commerceklaviyo\services;

/**
 * Pure mapping of a flat array of Craft user attribute/field values onto a
 * Klaviyo profile `properties` object, given a merchant-configured mapping
 * of `craftFieldHandle => klaviyoPropertyKey`. Kept as a standalone class
 * (rather than reaching into a real `craft\elements\User` directly) so it's
 * unit-testable without booting Craft, and so it's the one place this
 * plugin's custom-field-mapping feature actually lives — the competitor
 * plugin hard-codes exactly four profile fields (external_id, email,
 * first_name, last_name) with no way to map anything else.
 */
class ProfileMapper
{
    /**
     * @param array<string, string> $mapping craftFieldHandle => klaviyoPropertyKey
     * @param array<string, mixed> $fieldValues craftFieldHandle => value, as already extracted from the user
     * @return array<string, mixed>
     */
    public function mapProperties(array $mapping, array $fieldValues): array
    {
        $properties = [];

        foreach ($mapping as $fieldHandle => $klaviyoProperty) {
            if ($klaviyoProperty === '' || !array_key_exists($fieldHandle, $fieldValues)) {
                continue;
            }

            $value = $fieldValues[$fieldHandle];

            if ($value === null || $value === '') {
                continue;
            }

            $properties[$klaviyoProperty] = $value;
        }

        return $properties;
    }
}
