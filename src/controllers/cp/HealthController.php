<?php

namespace kernpfad\commerceklaviyo\controllers\cp;

use craft\web\Controller;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\services\CatalogLookupService;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class HealthController extends Controller
{
    /**
     * Looks up a catalog item or variant in Klaviyo by Craft element ID
     * (the same value used as Klaviyo `external_id`). Requires the
     * `catalogs:read` scope on the configured API key.
     */
    public function actionLookup(): Response
    {
        $this->requireAdmin(false);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $type = (string)$this->request->getRequiredBodyParam('type');
        $externalId = trim((string)$this->request->getRequiredBodyParam('externalId'));

        if (!in_array($type, ['item', 'variant'], true)) {
            throw new BadRequestHttpException('Type must be item or variant.');
        }

        $client = CommerceKlaviyo::getInstance()?->getKlaviyoClient();

        if ($client === null) {
            return $this->asJson(['success' => false, 'message' => 'No API key configured.']);
        }

        $lookup = new CatalogLookupService($client);
        $result = $type === 'item'
            ? $lookup->lookupItem($externalId)
            : $lookup->lookupVariant($externalId);

        if (!$result['found']) {
            return $this->asJson([
                'success' => true,
                'found' => false,
                'message' => $result['message'] ?? 'Not found.',
            ]);
        }

        $resource = $result['resource'] ?? [];
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];

        return $this->asJson([
            'success' => true,
            'found' => true,
            'id' => $resource['id'] ?? null,
            'title' => $attributes['title'] ?? null,
            'sku' => $attributes['sku'] ?? null,
            'inventoryQuantity' => $attributes['inventory_quantity'] ?? null,
            'published' => $attributes['published'] ?? null,
        ]);
    }
}
