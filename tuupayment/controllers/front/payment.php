<?php
/**
 * TUU (Haulmer) payment module for PrestaShop.
 *
 * Front controller that creates the TUU payment intent and redirects the
 * customer to the TUU hosted payment page.
 *
 * @author    Felipe Valenzuela
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TuupaymentPaymentModuleFrontController extends ModuleFrontController
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
        $cart = $this->context->cart;

        // Basic validity checks (module active, cart valid, currency allowed).
        if (!$this->isValidPaymentContext($cart, $module)) {
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        $currency = new Currency((int) $cart->id_currency);
        $amount = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $reference = $module->generateReference((int) $cart->id);

        // Persist the attempt before contacting the gateway.
        $module->insertTransaction([
            'reference' => $reference,
            'id_cart' => (int) $cart->id,
            'id_customer' => (int) $customer->id,
            'secure_key' => $customer->secure_key,
            'amount' => $amount,
            'currency' => $currency->iso_code,
            'status' => 'created',
        ]);

        $payload = $this->buildPayload($cart, $customer, $currency, $amount, $reference, $module);

        $client = $module->getApiClient();
        $response = $client->createPaymentIntent($payload);

        // Store the (signed) request payload for auditing (without the secret).
        $module->updateTransaction($reference, [
            'request_payload' => json_encode($response['request'], JSON_UNESCAPED_UNICODE),
            'redirect_url' => $response['redirect_url'],
            'status' => $response['success'] ? 'redirected' : 'intent_failed',
        ]);

        if ($response['success'] && $response['redirect_url']) {
            $module->log('Redirecting cart ' . (int) $cart->id . ' to TUU for reference ' . $reference, 1);
            Tools::redirect($response['redirect_url']);

            return;
        }

        // Failure creating the intent: show an error and go back to checkout.
        $module->log(
            'Failed to create payment intent for reference ' . $reference
            . ' (HTTP ' . (int) $response['http_code'] . '): ' . (string) $response['error'],
            3
        );

        $this->errors[] = $this->module->l('We could not start the payment with TUU. Please try again or choose another payment method.', 'payment');
        $this->redirectWithNotifications('index.php?controller=order&step=1');
    }

    /**
     * @param Cart      $cart
     * @param Tuupayment $module
     *
     * @return bool
     */
    private function isValidPaymentContext($cart, $module)
    {
        if (!$module->active || !$module->isConfigured()) {
            return false;
        }
        if (!Validate::isLoadedObject($cart) || $cart->id_customer == 0
            || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0) {
            return false;
        }
        if (!$cart->getProducts() || count($cart->getProducts()) === 0) {
            return false;
        }

        // The module must be an authorised payment module for this cart.
        $authorized = false;
        foreach (Module::getPaymentModules() as $paymentModule) {
            if ($paymentModule['name'] === $module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            return false;
        }

        return $module->checkCurrency($cart);
    }

    /**
     * Build the payment-intent body.
     *
     * @param Cart      $cart
     * @param Customer  $customer
     * @param Currency  $currency
     * @param float     $amount
     * @param string    $reference
     * @param Tuupayment $module
     *
     * @return array
     */
    private function buildPayload($cart, $customer, $currency, $amount, $reference, $module)
    {
        $link = $this->context->link;

        $phone = $this->resolveCustomerPhone($cart, $customer);

        return [
            'x_amount' => $module->formatAmount($amount, $currency),
            'x_currency' => Tools::strtoupper($currency->iso_code),
            'x_customer_email' => (string) $customer->email,
            'x_customer_first_name' => (string) $customer->firstname,
            'x_customer_last_name' => (string) $customer->lastname,
            'x_customer_phone' => $phone,
            'x_description' => $this->buildDescription($cart, $reference),
            'x_reference' => $reference,
            'x_shop_name' => (string) Configuration::get('PS_SHOP_NAME'),
            'x_url_callback' => $module->getCallbackUrl($reference),
            'x_url_cancel' => $link->getModuleLink($module->name, 'cancel', ['reference' => $reference], true),
            'x_url_complete' => $link->getModuleLink($module->name, 'complete', ['reference' => $reference], true),
        ];
    }

    /**
     * @param Cart     $cart
     * @param Customer $customer
     *
     * @return string
     */
    private function resolveCustomerPhone($cart, $customer)
    {
        $phone = '';
        if ($cart->id_address_invoice) {
            $address = new Address((int) $cart->id_address_invoice);
            if (Validate::isLoadedObject($address)) {
                $phone = $address->phone_mobile ? $address->phone_mobile : $address->phone;
            }
        }

        return (string) $phone;
    }

    /**
     * @param Cart   $cart
     * @param string $reference
     *
     * @return string
     */
    private function buildDescription($cart, $reference)
    {
        $shopName = (string) Configuration::get('PS_SHOP_NAME');
        $description = $shopName . ' - Orden ' . $reference;

        // Keep it short and clean.
        return Tools::substr(trim($description), 0, 250);
    }
}
