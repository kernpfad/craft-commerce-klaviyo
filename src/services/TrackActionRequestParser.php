<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\services;

use kernpfad\commerceklaviyo\models\TrackActionRequest;

/**
 * Pure request parsing for public identify/track form actions — framework-free
 * so Twig POST shapes stay unit-testable without Craft.
 */
class TrackActionRequestParser
{
    private const EVENT_RESERVED_KEYS = [
        'name',
        'unique_id',
        'value',
        'value_currency',
        'timestamp',
        'trackOrder',
        'orderId',
    ];

    /**
     * @param array<string, mixed> $params
     */
    public function parse(array $params): TrackActionRequest
    {
        $profileParam = $params['profile'] ?? [];
        $profile = is_array($profileParam) ? $profileParam : [];

        $email = null;
        if (isset($profile['email']) && is_string($profile['email']) && $profile['email'] !== '') {
            $email = $profile['email'];
        } elseif (isset($params['email']) && is_string($params['email']) && $params['email'] !== '') {
            $email = $params['email'];
        }

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = null;
        }

        if ($email !== null) {
            $profile['email'] = $email;
        } else {
            unset($profile['email']);
        }

        $eventParam = $params['event'] ?? null;
        $event = is_array($eventParam) ? $eventParam : [];

        $eventName = isset($event['name']) && is_string($event['name']) && $event['name'] !== ''
            ? $event['name']
            : null;

        $eventUniqueId = isset($event['unique_id']) && is_scalar($event['unique_id'])
            ? (string)$event['unique_id']
            : null;

        $eventValue = null;
        if (isset($event['value']) && is_numeric($event['value'])) {
            $eventValue = (float)$event['value'];
        }

        $eventValueCurrency = isset($event['value_currency']) && is_string($event['value_currency'])
            ? $event['value_currency']
            : null;

        $eventProperties = [];
        foreach ($event as $key => $value) {
            if (!is_string($key) || in_array($key, self::EVENT_RESERVED_KEYS, true)) {
                continue;
            }
            $eventProperties[$key] = $value;
        }

        if ($eventValueCurrency !== null) {
            $eventProperties['$value_currency'] = $eventValueCurrency;
        }

        $listIds = [];
        if (isset($params['list']) && is_string($params['list']) && $params['list'] !== '') {
            $listIds[] = $params['list'];
        } elseif (isset($params['lists']) && is_array($params['lists'])) {
            foreach ($params['lists'] as $listId) {
                if (is_string($listId) && $listId !== '') {
                    $listIds[] = $listId;
                }
            }
        }

        $orderId = null;
        if (isset($event['orderId']) && is_numeric($event['orderId'])) {
            $orderId = (int)$event['orderId'];
        }

        $forward = isset($params['forward']) && is_string($params['forward']) && $params['forward'] !== ''
            ? $params['forward']
            : null;

        return new TrackActionRequest(
            email: $email,
            profile: $profile,
            eventName: $eventName,
            eventUniqueId: $eventUniqueId,
            eventValue: $eventValue,
            eventValueCurrency: $eventValueCurrency,
            eventProperties: $eventProperties,
            listIds: $listIds,
            subscribe: !empty($params['subscribe']),
            trackOrder: !empty($event['trackOrder']),
            orderId: $orderId,
            forward: $forward,
        );
    }
}
