<?php
/**
 * TUU (Haulmer) payment module for PrestaShop.
 *
 * Customer-facing return endpoint (x_url_complete). The customer is redirected
 * here after finishing the payment flow. The authoritative result comes from
 * the server-to-server callback, but we also process the GET parameters here
 * (verifying the signature) so the confirmation page works even if the callback
 * is delayed.
 *
 * @author    Felipe Valenzuela
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TuupaymentCompleteModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /**
     * @return void
     */
    public function postProcess()
    {
        /** @var Tuupayment $module */
        $module = $this->module;

        $params = $this->collectSignedParams();
        $reference = $this->resolveReference();

        $module->log(
            'Complete return: reference="' . $reference . '"'
            . ' params=' . json_encode($params, JSON_UNESCAPED_UNICODE)
            . ' customer=' . (int) $this->context->customer->id,
            1
        );

        $transaction = $reference !== '' ? $module->getTransactionByReference($reference) : false;

        // The gateway did not echo a reference we can match: fall back to the
        // most recent paid order of the logged-in customer so a successful
        // payment still lands on its confirmation page instead of the history.
        if (!$transaction) {
            if ($reference !== '') {
                $module->log('Complete: unknown reference ' . $reference, 3);
            }

            $fallback = $module->getLatestPaidTransactionByCustomer((int) $this->context->customer->id);
            if ($fallback && !empty($fallback['id_order'])) {
                $module->log(
                    'Complete: reference missing/unknown, recovered latest paid order '
                    . (int) $fallback['id_order'] . ' for customer ' . (int) $this->context->customer->id,
                    1
                );
                $this->redirectToConfirmation((int) $fallback['id_order'], (int) $fallback['id_cart']);

                return;
            }

            $module->log('Complete: no matchable reference and no recoverable order; sending to history', 3);
            $this->redirectToOrderHistory();

            return;
        }

        // If the GET carries a valid signed result, process it (idempotent).
        if (!empty($params) && isset($params['x_signature'])) {
            $module->processNotification($params, 'complete');
            // Reload after potential order creation.
            $transaction = $module->getTransactionByReference($reference);
        }

        // Order already created (by callback or by the step above): confirmation.
        if (!empty($transaction['id_order'])) {
            $this->redirectToConfirmation((int) $transaction['id_order'], (int) $transaction['id_cart']);

            return;
        }

        // The reference matched a transaction but no order exists yet. The
        // callback may still be in flight; before showing the pending page, try
        // once more to recover a paid order for this customer (e.g. the callback
        // created it under a different attempt/reference).
        $fallback = $module->getLatestPaidTransactionByCustomer((int) $this->context->customer->id);
        if ($fallback && !empty($fallback['id_order'])) {
            $this->redirectToConfirmation((int) $fallback['id_order'], (int) $fallback['id_cart']);

            return;
        }

        $result = isset($transaction['result']) ? strtolower((string) $transaction['result']) : '';
        $getResult = isset($params['x_result']) ? strtolower((string) $params['x_result']) : '';
        $effective = $result !== '' ? $result : $getResult;

        if ($effective === 'failed') {
            $this->setFailureAndRedirect();

            return;
        }

        // No order yet and not explicitly failed: the callback may still be in
        // flight. Show a "processing" page instead of a hard error.
        $this->showPendingPage($module, $reference);
    }

    /**
     * @return string
     */
    private function resolveReference()
    {
        foreach (['reference', 'x_reference', 'X_REFERENCE', 'ref'] as $key) {
            $value = trim((string) Tools::getValue($key));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array
     */
    private function collectSignedParams()
    {
        $params = [];
        $source = !empty($_POST) ? $_POST : $_GET;
        foreach ($source as $key => $value) {
            if (is_string($key) && strpos($key, 'x_') === 0) {
                $params[$key] = is_array($value) ? $value : (string) $value;
            }
        }

        return $params;
    }

    /**
     * @param int $idOrder
     * @param int $idCart
     *
     * @return void
     */
    private function redirectToConfirmation($idOrder, $idCart)
    {
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order)) {
            $this->redirectToOrderHistory();

            return;
        }

        $customer = new Customer((int) $order->id_customer);

        Tools::redirect(
            'index.php?controller=order-confirmation'
            . '&id_cart=' . (int) $idCart
            . '&id_module=' . (int) $this->module->id
            . '&id_order=' . (int) $idOrder
            . '&key=' . urlencode($customer->secure_key)
        );
    }

    /**
     * @return void
     */
    private function setFailureAndRedirect()
    {
        $this->errors[] = $this->module->l('The payment was not completed. Your card was not charged. Please try again.', 'complete');
        $this->redirectWithNotifications('index.php?controller=order&step=1');
    }

    /**
     * @return void
     */
    private function redirectToOrderHistory()
    {
        if ($this->context->customer->isLogged()) {
            Tools::redirect('index.php?controller=history');
        } else {
            Tools::redirect('index.php?controller=order&step=1');
        }
    }

    /**
     * Render a lightweight "we are confirming your payment" page.
     *
     * @param Tuupayment $module
     * @param string     $reference
     *
     * @return void
     */
    private function showPendingPage($module, $reference)
    {
        $this->context->smarty->assign([
            'reference' => $reference,
            'history_url' => $this->context->link->getPageLink('history', true),
            'shop_name' => $this->context->shop->name,
        ]);

        $this->setTemplate('module:tuupayment/views/templates/front/pending.tpl');
    }
}
