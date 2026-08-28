<?php

namespace kernpfad\commerceklaviyo\controllers\cp;

use craft\web\Controller;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use yii\web\Response;

class SyncController extends Controller
{
    /**
     * Queues a full catalog reindex and returns immediately — the actual
     * Klaviyo API calls happen on the queue, not in this request. The
     * settings page's "Sync now" button polls `queue/get-job-info` for
     * progress after calling this.
     */
    public function actionRun(): Response
    {
        $this->requireAdmin(false);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = CommerceKlaviyo::getInstance();

        if ($plugin === null) {
            return $this->asJson(['success' => false, 'message' => 'Commerce Klaviyo is not installed.']);
        }

        if ($plugin->getKlaviyoClient() === null) {
            return $this->asJson(['success' => false, 'message' => 'No API key configured.']);
        }

        $result = $plugin->catalogSync->reindexAll();

        if ($result === null) {
            return $this->asJson(['success' => false, 'message' => 'Another sync is already running.']);
        }

        return $this->asJson([
            'success' => true,
            'productCount' => $result['productCount'],
            'variantCount' => $result['variantCount'],
            'itemJobCount' => $result['itemJobCount'],
            'variantJobCount' => $result['variantJobCount'],
        ]);
    }
}
