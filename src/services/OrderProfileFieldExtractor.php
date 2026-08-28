<?php

namespace kernpfad\commerceklaviyo\services;

use craft\elements\Address;
use craft\elements\User;

/**
 * Collects the flat `craftFieldHandle => value` array that
 * {@see ProfileMapper} expects, from either the order's associated Craft
 * user (logged-in checkout) or its billing/shipping address (guest
 * checkout). Kept separate from {@see OrderTrackingService} so the address
 * fallback rules are unit-testable without booting Craft.
 */
class OrderProfileFieldExtractor
{
    /**
     * @param string[] $fieldHandles
     * @return array<string, mixed>
     */
    public function extract(array $fieldHandles, ?User $customer, ?Address $billingAddress, ?Address $shippingAddress): array
    {
        if ($fieldHandles === []) {
            return [];
        }

        if ($customer !== null) {
            return $this->extractFromUser($fieldHandles, $customer);
        }

        $address = $billingAddress ?? $shippingAddress;

        if ($address === null) {
            return [];
        }

        return $this->extractFromAddress($fieldHandles, $address);
    }

    /**
     * @param string[] $fieldHandles
     * @return array<string, mixed>
     */
    private function extractFromUser(array $fieldHandles, User $customer): array
    {
        $fieldValues = [];

        foreach ($fieldHandles as $fieldHandle) {
            $fieldValues[$fieldHandle] = $customer->getFieldValue($fieldHandle);
        }

        return $fieldValues;
    }

    /**
     * @param string[] $fieldHandles
     * @return array<string, mixed>
     */
    private function extractFromAddress(array $fieldHandles, Address $address): array
    {
        $fieldValues = [];

        foreach ($fieldHandles as $fieldHandle) {
            $fieldValues[$fieldHandle] = $this->resolveAddressValue($address, $fieldHandle);
        }

        return $fieldValues;
    }

    private function resolveAddressValue(Address $address, string $fieldHandle): mixed
    {
        if ($fieldHandle === '') {
            return null;
        }

        if ($address->canGetProperty($fieldHandle, checkVars: true)) {
            $value = $address->$fieldHandle;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        try {
            $value = $address->getFieldValue($fieldHandle);

            if ($value !== null && $value !== '') {
                return $value;
            }
        } catch (\Throwable) {
            // Field not on this address layout.
        }

        return null;
    }
}
