<?php

namespace kernpfad\commerceklaviyo\console\controllers;

use craft\console\Controller;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use yii\console\ExitCode;

/**
 * `php craft commerce-klaviyo/reindex` — the console entry point for
 * {@see \kernpfad\commerceklaviyo\services\CatalogSyncService::reindexAll()},
 * which does the actual work (and is shared with the CP's "Sync now"
 * button). This controller is just argument/output plumbing: the
 * API-key precondition check, and translating a busy mutex or the
 * progress callback into console exit codes and stdout lines.
 */
class ReindexController extends Controller
{
    public function actionIndex(): int
    {
        $plugin = CommerceKlaviyo::getInstance();

        if ($plugin === null) {
            $this->stderr("Commerce Klaviyo is not installed.\n");

            return ExitCode::UNAVAILABLE;
        }

        if ($plugin->getKlaviyoClient() === null) {
            $this->stderr("Commerce Klaviyo is not configured (API key) - aborting.\n");

            return ExitCode::CONFIG;
        }

        $result = $plugin->catalogSync->reindexAll(
            onProgress: function(int $productCount) {
                if ($productCount % 50 === 0) {
                    $this->stdout("  {$productCount} products queued so far...\n");
                }
            },
        );

        if ($result === null) {
            $this->stderr("Another reindex is already running.\n");

            return ExitCode::TEMPFAIL;
        }

        $this->stdout("Queued {$result['productCount']} product(s) and {$result['variantCount']} variant(s) in {$result['itemJobCount']} bulk item job(s) and {$result['variantJobCount']} bulk variant job(s).\n");
        $this->stdout("Run `php craft queue/run` (or wait for a worker) to actually push them to Klaviyo.\n");

        return ExitCode::OK;
    }
}
