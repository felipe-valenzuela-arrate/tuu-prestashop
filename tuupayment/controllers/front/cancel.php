<?php
/**
 * TUU (Haulmer) payment module for PrestaShop.
 *
 * Customer-facing cancel endpoint (x_url_cancel). The customer is redirected
 * here when they explicitly cancel the payment.
 *
 * @author    Felipe Valenzuela
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TuupaymentCancelModuleFrontController extends ModuleFrontController
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

        $reference = trim((string) Tools::getValue('reference'));
        if ($reference === '') {
            $reference = trim((string) Tools::getValue('x_reference'));
        }

        if ($reference !== '') {
            $transaction = $module->getTransactionByReference($reference);
            // Only mark as cancelled if it has not already succeeded.
            if ($transaction && empty($transaction['id_order'])) {
                $module->updateTransaction($reference, ['status' => 'cancelled']);
                $module->log('Payment cancelled by customer for reference ' . $reference, 1);
            }
        }

        $this->warning[] = $this->module->l('You cancelled the payment. Your order was not placed and your card was not charged.', 'cancel');
        $this->redirectWithNotifications('index.php?controller=order&step=1');
    }
}
