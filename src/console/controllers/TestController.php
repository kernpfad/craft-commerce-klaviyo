<?php

namespace kernpfad\commerceklaviyo\console\controllers;

use craft\console\Controller;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use yii\console\ExitCode;

/**
 * `php craft commerce-klaviyo/test` — verifies the configured API key
 * works by making a single lightweight, read-only call (GET /api/accounts/),
 * without any side effects on the Klaviyo account.
 */
class TestController extends Controller
{
    public function actionIndex(): int
    {
        $plugin = CommerceKlaviyo::getInstance();

        if ($plugin === null) {
            $this->stderr("Commerce Klaviyo is not installed.\n");

            return ExitCode::UNAVAILABLE;
        }

        $result = $plugin->testConnection();

        if ($result['success']) {
            $this->stdout($result['message'] . "\n");

            return ExitCode::OK;
        }

        $this->stderr($result['message'] . "\n");

        return ExitCode::UNAVAILABLE;
    }
}
