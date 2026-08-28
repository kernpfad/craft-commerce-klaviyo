<?php

namespace kernpfad\commerceklaviyo\controllers\cp;

use craft\web\Controller;
use kernpfad\commerceklaviyo\services\KlaviyoStatusService;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class StatusController extends Controller
{
    /**
     * Dismisses a stored catalog-sync or event-track error notice — see
     * {@see KlaviyoStatusService::clearCatalogError()}.
     */
    public function actionDismiss(): Response
    {
        $this->requireAdmin(false);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $category = $this->request->getRequiredBodyParam('category');
        $status = new KlaviyoStatusService();

        match ($category) {
            KlaviyoStatusService::CATEGORY_CATALOG => $status->clearCatalogError(),
            KlaviyoStatusService::CATEGORY_TRACK => $status->clearTrackError(),
            default => throw new BadRequestHttpException("Unknown status category: {$category}"),
        };

        return $this->asJson(['success' => true]);
    }
}
