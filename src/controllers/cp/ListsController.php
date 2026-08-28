<?php

namespace kernpfad\commerceklaviyo\controllers\cp;

use craft\web\Controller;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use kernpfad\commerceklaviyo\services\KlaviyoListsService;
use yii\web\Response;

class ListsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin(false);

        $plugin = CommerceKlaviyo::getInstance();
        $client = $plugin?->getKlaviyoClient();

        if ($client === null) {
            return $this->asJson([
                'success' => false,
                'message' => 'No API key configured.',
                'lists' => [],
            ]);
        }

        try {
            $lists = (new KlaviyoListsService($client))->getLists();
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
                'lists' => [],
            ]);
        }

        return $this->asJson([
            'success' => true,
            'lists' => $lists,
        ]);
    }
}
