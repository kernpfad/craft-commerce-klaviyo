<?php

namespace kernpfad\commerceklaviyo\controllers\cp;

use craft\web\Controller;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use yii\web\Response;

class TestController extends Controller
{
    public function actionRun(): Response
    {
        $this->requireAdmin(false);

        $result = CommerceKlaviyo::getInstance()?->testConnection()
            ?? ['success' => false, 'message' => 'Plugin is not available.'];

        return $this->asJson($result);
    }
}
