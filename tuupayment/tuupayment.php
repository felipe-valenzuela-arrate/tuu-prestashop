<?php
/**
 * TUU (Haulmer) payment module for PrestaShop 9.x.
 *
 * Accept online card payments through the TUU / Haulmer payment gateway.
 *
 * @author    Felipe Valenzuela - BOOKGES SPA
 * @copyright 2026
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/TuuSignature.php';
require_once dirname(__FILE__) . '/classes/TuuApiClient.php';

class Tuupayment extends PaymentModule
{
    /** @var array Configuration keys used by this module. */
    const CONFIG_KEYS = [
        'TUU_ACCOUNT_ID',
        'TUU_SECRET_KEY',
        'TUU_LIVE_MODE',
        'TUU_PAYMENT_TITLE',
        'TUU_PAYMENT_DESCRIPTION',
        'TUU_DEBUG_LOG',
    ];

    public function __construct()
    {
        $this->name = 'tuupayment';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'BOOKGES SpA';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
        $this->controllers = ['payment', 'callback', 'complete', 'cancel'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('TUU by Haulmer');
        $this->description = $this->l('Accept online payments (credit/debit cards) through the TUU by Haulmer payment gateway.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the TUU payment module? Transaction history stored by this module will be removed.');

        if (!Configuration::get('TUU_ACCOUNT_ID') || !Configuration::get('TUU_SECRET_KEY')) {
            $this->warning = $this->l('The TUU Account ID and Secret Key must be configured before you can accept payments.');
        }
    }

    /**
     * @return bool
     */
    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('The PHP cURL extension must be enabled to use this module.');

            return false;
        }

        return parent::install()
            && $this->installDatabase()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('displayHeader')
            && $this->installDefaultConfig();
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        $ok = true;
        foreach (self::CONFIG_KEYS as $key) {
            $ok = Configuration::deleteByName($key) && $ok;
        }

        return $this->uninstallDatabase() && parent::uninstall() && $ok;
    }

    /**
     * @return bool
     */
    private function installDatabase()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'tuu_transaction` (
            `id_tuu_transaction` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference` VARCHAR(191) NOT NULL,
            `id_cart` INT UNSIGNED NOT NULL DEFAULT 0,
            `id_order` INT UNSIGNED NULL DEFAULT NULL,
            `id_customer` INT UNSIGNED NOT NULL DEFAULT 0,
            `secure_key` VARCHAR(255) NOT NULL DEFAULT \'\',
            `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `currency` VARCHAR(10) NOT NULL DEFAULT \'CLP\',
            `status` VARCHAR(20) NOT NULL DEFAULT \'created\',
            `result` VARCHAR(20) NULL DEFAULT NULL,
            `message` VARCHAR(500) NULL DEFAULT NULL,
            `redirect_url` TEXT NULL,
            `request_payload` TEXT NULL,
            `callback_payload` TEXT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_tuu_transaction`),
            UNIQUE KEY `reference` (`reference`),
            KEY `id_cart` (`id_cart`),
            KEY `id_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return (bool) Db::getInstance()->execute($sql);
    }

    /**
     * @return bool
     */
    private function uninstallDatabase()
    {
        return (bool) Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'tuu_transaction`;'
        );
    }

    /**
     * @return bool
     */
    private function installDefaultConfig()
    {
        Configuration::updateValue('TUU_ACCOUNT_ID', '');
        Configuration::updateValue('TUU_SECRET_KEY', '');
        Configuration::updateValue('TUU_LIVE_MODE', 0);
        Configuration::updateValue('TUU_DEBUG_LOG', 0);
        Configuration::updateValue('TUU_PAYMENT_TITLE', 'Pago con tarjeta (TUU)', true);
        Configuration::updateValue(
            'TUU_PAYMENT_DESCRIPTION',
            'Paga de forma segura con tarjeta de crédito o débito a través de TUU by Haulmer.',
            true
        );

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Configuration screen                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @return string
     */
    public function getContent()
    {
        $output = '';

        // Widen the (long) Secret Key field so it does not look like a short
        // text input. The class is set on the field; also target it by name/id
        // as a fallback across HelperForm template variations.
        $output .= '<style>
            #TUU_SECRET_KEY,
            input[name="TUU_SECRET_KEY"],
            .tuu-secret-key-input {
                width: 100%;
                max-width: 720px;
                min-width: 320px;
                font-family: monospace;
            }
        </style>';

        if (Tools::isSubmit('submitTuuPayment')) {
            $output .= $this->postProcessConfig();
        }

        return $output . $this->renderConfigForm();
    }

    /**
     * @return string HTML of confirmation / error messages.
     */
    private function postProcessConfig()
    {
        $accountId = trim((string) Tools::getValue('TUU_ACCOUNT_ID'));
        $secretKey = trim((string) Tools::getValue('TUU_SECRET_KEY'));
        $title = trim((string) Tools::getValue('TUU_PAYMENT_TITLE'));

        // The Secret Key field is a password input and is intentionally never
        // pre-filled on render. If it is submitted empty but a key is already
        // stored, keep the stored one instead of wiping it.
        $storedSecret = (string) Configuration::get('TUU_SECRET_KEY');
        if ($secretKey === '' && $storedSecret !== '') {
            $secretKey = $storedSecret;
        }

        $errors = [];
        if ($accountId === '') {
            $errors[] = $this->l('The Account ID is required.');
        }
        if ($secretKey === '') {
            $errors[] = $this->l('The Secret Key is required.');
        }
        if ($title === '') {
            $errors[] = $this->l('The payment title shown to customers is required.');
        }

        if (!empty($errors)) {
            return $this->displayError(implode('<br />', array_map('htmlspecialchars', $errors)));
        }

        Configuration::updateValue('TUU_ACCOUNT_ID', $accountId);
        Configuration::updateValue('TUU_SECRET_KEY', $secretKey);
        Configuration::updateValue('TUU_LIVE_MODE', (int) Tools::getValue('TUU_LIVE_MODE'));
        Configuration::updateValue('TUU_DEBUG_LOG', (int) Tools::getValue('TUU_DEBUG_LOG'));
        Configuration::updateValue('TUU_PAYMENT_TITLE', $title, true);
        Configuration::updateValue(
            'TUU_PAYMENT_DESCRIPTION',
            (string) Tools::getValue('TUU_PAYMENT_DESCRIPTION'),
            true
        );

        return $this->displayConfirmation($this->l('Settings updated successfully.'));
    }

    /**
     * @return string
     */
    private function renderConfigForm()
    {
        $callbackUrl = $this->getCallbackUrl();

        $infoHtml = '<div class="alert alert-info">'
            . '<p><strong>' . $this->l('Server-to-server callback URL (x_url_callback):') . '</strong></p>'
            . '<p><code>' . htmlspecialchars($callbackUrl) . '</code></p>'
            . '<p>' . $this->l('This URL is sent automatically to TUU on each payment. It must be reachable over HTTPS in production.') . '</p>'
            . '</div>';

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('TUU by Haulmer settings'),
                    'icon' => 'icon-credit-card',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Production mode'),
                        'name' => 'TUU_LIVE_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Off = integration/sandbox environment. On = live production environment.'),
                        'values' => [
                            ['id' => 'live_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'live_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Account ID (x_account_id)'),
                        'name' => 'TUU_ACCOUNT_ID',
                        'required' => true,
                        'desc' => $this->l('Account identifier provided by TUU.'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('Secret Key'),
                        'name' => 'TUU_SECRET_KEY',
                        'class' => 'tuu-secret-key-input',
                        'required' => !Configuration::get('TUU_SECRET_KEY'),
                        'desc' => Configuration::get('TUU_SECRET_KEY')
                            ? $this->l('A Secret Key is already saved (hidden for security). Leave this field blank to keep it, or type a new one to replace it.')
                            : $this->l('Secret key used to sign requests and verify callbacks. Never share it.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Payment title'),
                        'name' => 'TUU_PAYMENT_TITLE',
                        'required' => true,
                        'desc' => $this->l('Text shown to the customer on the checkout payment step.'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Payment description'),
                        'name' => 'TUU_PAYMENT_DESCRIPTION',
                        'desc' => $this->l('Optional additional text shown under the payment title.'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Debug log'),
                        'name' => 'TUU_DEBUG_LOG',
                        'is_bool' => true,
                        'desc' => $this->l('Write detailed entries to the PrestaShop log (Advanced Parameters > Logs). Disable in production.'),
                        'values' => [
                            ['id' => 'debug_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'debug_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTuuPayment';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $infoHtml . $helper->generateForm([$fields_form]);
    }

    /**
     * @return array
     */
    private function getConfigFieldsValues()
    {
        return [
            'TUU_ACCOUNT_ID' => Configuration::get('TUU_ACCOUNT_ID'),
            'TUU_SECRET_KEY' => Configuration::get('TUU_SECRET_KEY'),
            'TUU_LIVE_MODE' => (int) Configuration::get('TUU_LIVE_MODE'),
            'TUU_DEBUG_LOG' => (int) Configuration::get('TUU_DEBUG_LOG'),
            'TUU_PAYMENT_TITLE' => Configuration::get('TUU_PAYMENT_TITLE'),
            'TUU_PAYMENT_DESCRIPTION' => Configuration::get('TUU_PAYMENT_DESCRIPTION'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Hooks                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Load module CSS on the front office.
     *
     * @return void
     */
    public function hookDisplayHeader()
    {
        if ($this->context->controller instanceof FrontController) {
            $this->context->controller->registerStylesheet(
                'module-tuupayment-front',
                'modules/' . $this->name . '/views/css/front.css',
                ['media' => 'all', 'priority' => 150]
            );
        }
    }

    /**
     * Provide the TUU payment option at checkout.
     *
     * @param array $params
     *
     * @return array|null
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return null;
        }

        if (!$this->isConfigured()) {
            return null;
        }

        /** @var Cart $cart */
        $cart = $params['cart'];
        if (!$this->checkCurrency($cart)) {
            return null;
        }

        $title = Configuration::get('TUU_PAYMENT_TITLE');
        if (!$title) {
            $title = $this->l('Pay by card (TUU)');
        }

        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($title)
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true));

        $description = Configuration::get('TUU_PAYMENT_DESCRIPTION');
        if ($description) {
            $option->setAdditionalInformation('<p class="tuu-payment-desc">' . nl2br(Tools::htmlentitiesUTF8($description)) . '</p>');
        }

        $logo = _MODULE_DIR_ . $this->name . '/views/img/logo.png';
        if (file_exists(dirname(__FILE__) . '/views/img/logo.png')) {
            $option->setLogo($logo);
        }

        return [$option];
    }

    /**
     * Order confirmation page content.
     *
     * @param array $params
     *
     * @return string
     */
    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }

        $order = isset($params['order']) ? $params['order'] : null;
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $this->context->smarty->assign([
            'shop_name' => $this->context->shop->name,
            'reference' => $order->reference,
            'contact_url' => $this->context->link->getPageLink('contact', true),
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/payment_return.tpl');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers used by the front controllers                              */
    /* ------------------------------------------------------------------ */

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return (bool) Configuration::get('TUU_ACCOUNT_ID')
            && (bool) Configuration::get('TUU_SECRET_KEY');
    }

    /**
     * @return bool
     */
    public function isLiveMode()
    {
        return (bool) Configuration::get('TUU_LIVE_MODE');
    }

    /**
     * @return TuuApiClient
     */
    public function getApiClient()
    {
        return new TuuApiClient(
            Configuration::get('TUU_ACCOUNT_ID'),
            Configuration::get('TUU_SECRET_KEY'),
            $this->isLiveMode()
        );
    }

    /**
     * @return string
     */
    public function getSecretKey()
    {
        return (string) Configuration::get('TUU_SECRET_KEY');
    }

    /**
     * Ensure the cart currency is enabled for this payment module.
     *
     * @param Cart $cart
     *
     * @return bool
     */
    public function checkCurrency($cart)
    {
        $currencyOrder = new Currency((int) $cart->id_currency);
        $currenciesModule = $this->getCurrency((int) $cart->id_currency);

        if (is_array($currenciesModule)) {
            foreach ($currenciesModule as $currencyModule) {
                if ($currencyOrder->id == $currencyModule['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build the absolute HTTPS server-to-server callback URL.
     *
     * @param string $reference Optional reference to embed in the query string.
     *
     * @return string
     */
    public function getCallbackUrl($reference = '')
    {
        $params = [];
        if ($reference !== '') {
            $params['reference'] = $reference;
        }

        return $this->context->link->getModuleLink($this->name, 'callback', $params, true);
    }

    /**
     * Generate a unique payment reference for a cart attempt.
     *
     * @param int $idCart
     *
     * @return string
     */
    public function generateReference($idCart)
    {
        // Unique per attempt; idempotency for order creation is keyed on the cart.
        $suffix = strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8));

        return 'TUU-' . (int) $idCart . '-' . $suffix;
    }

    /**
     * Format an amount for the x_amount field respecting the currency decimals.
     * TUU currently only supports CLP (0 decimals), but this keeps the module
     * correct if that changes.
     *
     * @param float    $amount
     * @param Currency $currency
     *
     * @return string
     */
    public function formatAmount($amount, Currency $currency)
    {
        // Currency->precision holds the number of decimal digits in PS 1.7+.
        if (isset($currency->precision) && $currency->precision !== null) {
            $precision = (int) $currency->precision;
        } elseif (isset($currency->decimals)) {
            $precision = ((int) $currency->decimals) ? 2 : 0;
        } else {
            $precision = 2;
        }

        // TUU currently only settles CLP, which has no decimals.
        if (strtoupper($currency->iso_code) === 'CLP') {
            $precision = 0;
        }

        $rounded = round((float) $amount, $precision);

        if ($precision === 0) {
            return (string) (int) $rounded;
        }

        return number_format($rounded, $precision, '.', '');
    }

    /* ------------------------------------------------------------------ */
    /* Transaction persistence                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array $data
     *
     * @return int Insert id.
     */
    public function insertTransaction(array $data)
    {
        $now = date('Y-m-d H:i:s');
        $row = [
            'reference' => pSQL($data['reference']),
            'id_cart' => (int) $data['id_cart'],
            'id_order' => isset($data['id_order']) && $data['id_order'] ? (int) $data['id_order'] : null,
            'id_customer' => (int) $data['id_customer'],
            'secure_key' => pSQL($data['secure_key']),
            'amount' => (float) $data['amount'],
            'currency' => pSQL($data['currency']),
            'status' => pSQL(isset($data['status']) ? $data['status'] : 'created'),
            'redirect_url' => isset($data['redirect_url']) ? pSQL($data['redirect_url'], true) : null,
            'request_payload' => isset($data['request_payload']) ? pSQL($data['request_payload'], true) : null,
            'date_add' => $now,
            'date_upd' => $now,
        ];

        // null_values = true so that NULL columns (id_order, ...) are stored as
        // real NULLs; INSERT IGNORE avoids duplicates on the unique reference.
        Db::getInstance()->insert('tuu_transaction', $row, true, true, Db::INSERT_IGNORE);

        return (int) Db::getInstance()->Insert_ID();
    }

    /**
     * @param string $reference
     *
     * @return array|false
     */
    public function getTransactionByReference($reference)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tuu_transaction`
             WHERE `reference` = \'' . pSQL($reference) . '\''
        );
    }

    /**
     * Latest transaction for a customer that has already been converted into an
     * order. Used as a fallback on the return page when the gateway does not
     * echo the reference back.
     *
     * @param int $idCustomer
     *
     * @return array|false
     */
    public function getLatestPaidTransactionByCustomer($idCustomer)
    {
        if ((int) $idCustomer <= 0) {
            return false;
        }

        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tuu_transaction`
             WHERE `id_customer` = ' . (int) $idCustomer . '
             AND `id_order` IS NOT NULL AND `id_order` > 0
             ORDER BY `id_tuu_transaction` DESC'
        );
    }

    /**
     * @param int $idCart
     *
     * @return array|false
     */
    public function getPaidTransactionByCart($idCart)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'tuu_transaction`
             WHERE `id_cart` = ' . (int) $idCart . '
             AND `id_order` IS NOT NULL AND `id_order` > 0
             ORDER BY `id_tuu_transaction` DESC'
        );
    }

    /**
     * @param string $reference
     * @param array  $fields
     *
     * @return bool
     */
    public function updateTransaction($reference, array $fields)
    {
        $fields['date_upd'] = date('Y-m-d H:i:s');
        $set = [];
        foreach ($fields as $key => $value) {
            if ($value === null) {
                $set[] = '`' . bqSQL($key) . '` = NULL';
            } else {
                $set[] = '`' . bqSQL($key) . '` = \'' . pSQL($value, true) . '\'';
            }
        }

        return (bool) Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'tuu_transaction`
             SET ' . implode(', ', $set) . '
             WHERE `reference` = \'' . pSQL($reference) . '\''
        );
    }

    /* ------------------------------------------------------------------ */
    /* Notification / result processing (shared by callback + complete)   */
    /* ------------------------------------------------------------------ */

    /**
     * Process an incoming TUU notification (callback POST or complete/cancel GET).
     * The signature is verified, the transaction updated and, on a successful
     * result, the PrestaShop order created idempotently.
     *
     * @param array  $params Raw x_* parameters received.
     * @param string $source 'callback' | 'complete' | 'cancel'
     *
     * @return array{ok:bool, reason:string, id_order:int|null, status:string}
     */
    public function processNotification(array $params, $source)
    {
        $reference = isset($params['x_reference']) ? (string) $params['x_reference'] : '';
        $out = ['ok' => false, 'reason' => '', 'id_order' => null, 'status' => 'unknown'];

        if ($reference === '') {
            $out['reason'] = 'missing_reference';
            $this->log('Notification without x_reference (source=' . $source . ')', 3);

            return $out;
        }

        $transaction = $this->getTransactionByReference($reference);
        if (!$transaction) {
            $out['reason'] = 'unknown_reference';
            $this->log('Notification for unknown reference ' . $reference . ' (source=' . $source . ')', 3);

            return $out;
        }

        // Verify the signature against the received parameters.
        if (!TuuSignature::verify($params, $this->getSecretKey())) {
            $out['reason'] = 'invalid_signature';
            $this->log('Invalid signature for reference ' . $reference . ' (source=' . $source . ')', 3);

            return $out;
        }

        $result = isset($params['x_result']) ? strtolower(trim((string) $params['x_result'])) : '';
        $message = isset($params['x_message']) ? (string) $params['x_message'] : '';

        // Persist the raw notification (only for the authoritative callback, or
        // if none was stored yet).
        $updateFields = [
            'result' => $result !== '' ? $result : null,
            'message' => $message !== '' ? Tools::substr($message, 0, 500) : null,
        ];
        if ($source === 'callback' || empty($transaction['callback_payload'])) {
            $updateFields['callback_payload'] = json_encode($params, JSON_UNESCAPED_UNICODE);
        }
        $this->updateTransaction($reference, $updateFields);

        // Already converted into an order? Idempotent success.
        if (!empty($transaction['id_order'])) {
            $out['ok'] = true;
            $out['reason'] = 'already_processed';
            $out['id_order'] = (int) $transaction['id_order'];
            $out['status'] = $result;

            return $out;
        }

        // Guard: another notification for the same cart may have already created
        // the order (multiple attempts / references).
        $paid = $this->getPaidTransactionByCart((int) $transaction['id_cart']);
        if ($paid && !empty($paid['id_order'])) {
            $this->updateTransaction($reference, ['id_order' => (int) $paid['id_order'], 'status' => 'duplicate']);
            $out['ok'] = true;
            $out['reason'] = 'already_processed_other_reference';
            $out['id_order'] = (int) $paid['id_order'];
            $out['status'] = $result;

            return $out;
        }

        if ($result === 'completed') {
            return $this->createOrderFromTransaction($transaction, $params, $out);
        }

        if ($result === 'failed') {
            $this->updateTransaction($reference, ['status' => 'failed']);
            $out['ok'] = true; // Correctly processed a (failed) notification.
            $out['reason'] = 'payment_failed';
            $out['status'] = 'failed';

            return $out;
        }

        // pending / unknown: acknowledge but do nothing yet.
        $this->updateTransaction($reference, ['status' => $result !== '' ? $result : 'pending']);
        $out['ok'] = true;
        $out['reason'] = 'pending';
        $out['status'] = $result !== '' ? $result : 'pending';

        return $out;
    }

    /**
     * Create the PrestaShop order for a completed transaction.
     *
     * @param array $transaction
     * @param array $params
     * @param array $out
     *
     * @return array
     */
    private function createOrderFromTransaction(array $transaction, array $params, array $out)
    {
        $reference = (string) $transaction['reference'];
        $cart = new Cart((int) $transaction['id_cart']);

        if (!Validate::isLoadedObject($cart)) {
            $out['reason'] = 'cart_not_found';
            $this->log('Cart ' . (int) $transaction['id_cart'] . ' not found for reference ' . $reference, 3);

            return $out;
        }

        // If an order already exists for this cart (created elsewhere), link it.
        $existingOrderId = (int) Order::getIdByCartId((int) $cart->id);
        if ($existingOrderId) {
            $this->updateTransaction($reference, ['id_order' => $existingOrderId, 'status' => 'completed']);
            $out['ok'] = true;
            $out['reason'] = 'order_already_exists';
            $out['id_order'] = $existingOrderId;
            $out['status'] = 'completed';

            return $out;
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $out['reason'] = 'customer_not_found';
            $this->log('Customer not found for cart ' . (int) $cart->id, 3);

            return $out;
        }

        // Verify the amount matches the cart total to protect against tampering.
        $currency = new Currency((int) $cart->id_currency);
        $cartTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $expected = $this->formatAmount($cartTotal, $currency);
        $received = isset($params['x_amount']) ? (string) $params['x_amount'] : '';

        if ($this->normalizeAmount($received) !== $this->normalizeAmount($expected)) {
            $this->log(
                'Amount mismatch for reference ' . $reference
                . ' expected=' . $expected . ' received=' . $received,
                3
            );
            $this->updateTransaction($reference, ['status' => 'amount_mismatch']);
            $out['reason'] = 'amount_mismatch';

            return $out;
        }

        $secureKey = !empty($transaction['secure_key']) ? $transaction['secure_key'] : $customer->secure_key;

        try {
            $this->validateOrder(
                (int) $cart->id,
                (int) Configuration::get('PS_OS_PAYMENT'),
                $cartTotal,
                $this->displayName,
                $this->l('TUU payment reference: ') . $reference,
                ['transaction_id' => $reference],
                (int) $cart->id_currency,
                false,
                $secureKey
            );
        } catch (Exception $e) {
            $this->log('validateOrder failed for reference ' . $reference . ': ' . $e->getMessage(), 3);
            $out['reason'] = 'order_creation_failed';

            return $out;
        }

        $idOrder = (int) $this->currentOrder;
        $this->updateTransaction($reference, ['id_order' => $idOrder, 'status' => 'completed']);

        $out['ok'] = true;
        $out['reason'] = 'order_created';
        $out['id_order'] = $idOrder;
        $out['status'] = 'completed';

        $this->log('Order ' . $idOrder . ' created for reference ' . $reference, 1);

        return $out;
    }

    /**
     * @param string $amount
     *
     * @return string
     */
    private function normalizeAmount($amount)
    {
        $amount = str_replace(',', '.', trim((string) $amount));
        if (!is_numeric($amount)) {
            return $amount;
        }

        // Compare as float rounded to 2 decimals to avoid formatting noise.
        return (string) round((float) $amount, 2);
    }

    /**
     * Write to the PrestaShop log when debugging is enabled (errors always log).
     *
     * @param string $message
     * @param int    $severity 1 = info, 2 = warning, 3 = error
     *
     * @return void
     */
    public function log($message, $severity = 1)
    {
        if ($severity >= 3 || (int) Configuration::get('TUU_DEBUG_LOG') === 1) {
            PrestaShopLogger::addLog('[TUU] ' . $message, (int) $severity, null, 'Tuupayment');
        }
    }
}
