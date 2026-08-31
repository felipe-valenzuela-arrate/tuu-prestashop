<?php
/**
 * TUU (Haulmer) payment module for PrestaShop.
 *
 * Server-to-server callback endpoint (x_url_callback). This is the
 * authoritative source of truth for the payment result.
 *
 * Content-Type of the request: application/x-www-form-urlencoded
 * Must respond 200 OK once processed (even for a rejected payment); any 4xx/5xx
 * makes TUU retry (up to 10 times with exponential backoff).
 *
 * @author    Felipe Valenzuela
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TuupaymentCallbackModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool Do not render the theme. */
    public $ajax = true;

    /**
     * @return void
     */
    public function postProcess()
    {
        /** @var Tuupayment $module */
        $module = $this->module;

        $params = $this->collectParams();
        $module->log('Callback received: ' . json_encode($params, JSON_UNESCAPED_UNICODE), 1);

        if (empty($params)) {
            $this->respond(400, 'ERROR: empty payload');

            return;
        }

        $result = $module->processNotification($params, 'callback');

        switch ($result['reason']) {
            case 'order_created':
            case 'already_processed':
            case 'already_processed_other_reference':
            case 'order_already_exists':
            case 'payment_failed':
            case 'pending':
                // Successfully processed the notification.
                $this->respond(200, 'OK');
                break;

            case 'invalid_signature':
            case 'missing_reference':
            case 'unknown_reference':
            case 'amount_mismatch':
                // Permanent client-side problem: no point retrying.
                $this->respond(400, 'ERROR: ' . $result['reason']);
                break;

            default:
                // Transient error (order creation failed, etc.): allow retries.
                $this->respond(500, 'ERROR: ' . $result['reason']);
                break;
        }
    }

    /**
     * Collect the x_* parameters from POST (urlencoded), falling back to the raw
     * body and GET.
     *
     * @return array
     */
    private function collectParams()
    {
        $params = [];

        if (!empty($_POST)) {
            foreach ($_POST as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = is_array($value) ? $value : (string) $value;
                }
            }
        }

        // Some gateways send urlencoded bodies that PHP does not auto-populate
        // (e.g. non-standard content types). Parse the raw body as a fallback.
        if (empty($params)) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $params = $decoded;
                } else {
                    parse_str($raw, $parsed);
                    if (is_array($parsed)) {
                        $params = $parsed;
                    }
                }
            }
        }

        if (empty($params) && !empty($_GET)) {
            foreach ($_GET as $key => $value) {
                if (is_string($key) && $key !== 'fc' && $key !== 'module' && $key !== 'controller') {
                    $params[$key] = is_array($value) ? $value : (string) $value;
                }
            }
        }

        return $params;
    }

    /**
     * Emit a plain-text HTTP response and stop execution.
     *
     * @param int    $code
     * @param string $body
     *
     * @return void
     */
    private function respond($code, $body)
    {
        header('Content-Type: text/plain; charset=utf-8', true, (int) $code);
        echo $body;
        exit;
    }
}
