<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\console\controllers;

use craft\console\Controller;
use DateTimeImmutable;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use yii\console\ExitCode;

/**
 * `php craft commerce-klaviyo/sync-orders --from=YYYY-MM-DD --to=YYYY-MM-DD`
 */
class SyncOrdersController extends Controller
{
    public string $from = '';

    public string $to = '';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['from', 'to']);
    }

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

        if ($this->from === '' || $this->to === '') {
            $this->stderr("Both --from=YYYY-MM-DD and --to=YYYY-MM-DD are required.\n");

            return ExitCode::USAGE;
        }

        try {
            $from = new DateTimeImmutable($this->from . ' 00:00:00');
            $to = new DateTimeImmutable($this->to . ' 23:59:59');
        } catch (\Exception) {
            $this->stderr("Could not parse --from / --to dates.\n");

            return ExitCode::USAGE;
        }

        if ($from > $to) {
            $this->stderr("--from must be on or before --to.\n");

            return ExitCode::USAGE;
        }

        $count = $plugin->historicalOrderSync->enqueueDateRange($from, $to);
        $this->stdout("Queued {$count} completed order(s) for historical Placed Order sync.\n");
        $this->stdout("Run `php craft queue/run` (or wait for a worker) to send them to Klaviyo.\n");

        return ExitCode::OK;
    }
}
