<?php

declare(strict_types=1);

namespace kernpfad\commerceklaviyo\controllers;

use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use kernpfad\commerceklaviyo\services\CartRestoreService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Public restore entry point for abandoned-cart emails. Redirects to
 * Commerce's own load-cart URL — no parallel session cart logic.
 *
 * Usage in a Klaviyo template:
 * `actions/commerce-klaviyo/cart/restore?number={{ event.OrderId }}`
 * (prefer the Commerce order `number` your flow stores as OrderNumber).
 */
class CartController extends Controller
{
    protected int|bool|array $allowAnonymous = true;

    public function actionRestore(): Response
    {
        $number = (string)$this->request->getRequiredQueryParam('number');

        $commerce = Commerce::getInstance();
        if ($commerce === null) {
            throw new NotFoundHttpException();
        }

        $order = $commerce->getOrders()->getOrderByNumber($number);
        if ($order === null) {
            throw new NotFoundHttpException();
        }

        $url = (new CartRestoreService())->resolveLoadCartUrl($order);
        if ($url === null) {
            throw new NotFoundHttpException();
        }

        return $this->redirect($url);
    }
}
