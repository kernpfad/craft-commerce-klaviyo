<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\controllers\cp;

use Craft;
use craft\web\Controller;
use DateTimeImmutable;
use kernpfad\commerceklaviyo\CommerceKlaviyo;
use yii\web\Response;

class HistoricalOrdersController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin();

        return true;
    }

    public function actionSync(): ?Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $plugin = CommerceKlaviyo::getInstance();
        if ($plugin === null || $plugin->getKlaviyoClient() === null) {
            return $this->asFailure(Craft::t('commerce-klaviyo', 'Klaviyo is not configured.'));
        }

        $fromRaw = $this->request->getRequiredBodyParam('from');
        $toRaw = $this->request->getRequiredBodyParam('to');

        try {
            $from = $this->parseDateParam($fromRaw, '00:00:00');
            $to = $this->parseDateParam($toRaw, '23:59:59');
        } catch (\InvalidArgumentException) {
            return $this->asFailure(Craft::t('commerce-klaviyo', 'Please provide valid From and To dates.'));
        }

        if ($from > $to) {
            return $this->asFailure(Craft::t('commerce-klaviyo', 'From must be on or before To.'));
        }

        $count = $plugin->historicalOrderSync->enqueueDateRange($from, $to);

        return $this->asSuccess(Craft::t(
            'commerce-klaviyo',
            'Queued {count} order(s) for historical sync. Run the queue to send them to Klaviyo.',
            ['count' => $count],
        ));
    }

    private function parseDateParam(mixed $raw, string $time): DateTimeImmutable
    {
        if (is_array($raw)) {
            $date = $raw['date'] ?? null;
            if (!is_string($date) || $date === '') {
                throw new \InvalidArgumentException('Missing date');
            }
            $raw = $date;
        }

        if (!is_string($raw) || $raw === '') {
            throw new \InvalidArgumentException('Missing date');
        }

        return new DateTimeImmutable($raw . ' ' . $time);
    }
}
