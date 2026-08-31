<?php
/**
 * TUU (Haulmer) payment module for PrestaShop.
 *
 * Thin HTTP client to create payment intents against the TUU payment API.
 *
 * @author    Felipe Valenzuela
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/TuuSignature.php';

class TuuApiClient
{
    /** Integration (sandbox) endpoint. */
    const ENDPOINT_TEST = 'https://frontend-api.payment.haulmer.dev/v1/payment';

    /** Production endpoint. */
    const ENDPOINT_LIVE = 'https://core.payment.haulmer.com/api/v1/payment';

    /** @var string */
    private $accountId;

    /** @var string */
    private $secretKey;

    /** @var bool */
    private $liveMode;

    /** @var int */
    private $timeout;

    public function __construct($accountId, $secretKey, $liveMode = false, $timeout = 30)
    {
        $this->accountId = (string) $accountId;
        $this->secretKey = (string) $secretKey;
        $this->liveMode = (bool) $liveMode;
        $this->timeout = (int) $timeout;
    }

    /**
     * @return string The API endpoint for the active environment.
     */
    public function getEndpoint()
    {
        return $this->liveMode ? self::ENDPOINT_LIVE : self::ENDPOINT_TEST;
    }

    /**
     * Create a payment intent.
     *
     * @param array $payload Body fields (x_amount, x_currency, x_customer_email, ...).
     *                       x_account_id and x_signature are injected automatically.
     *
     * @return array{
     *   success: bool,
     *   http_code: int,
     *   redirect_url: string|null,
     *   raw: string,
     *   data: array,
     *   error: string|null,
     *   request: array
     * }
     */
    public function createPaymentIntent(array $payload)
    {
        // Ensure the account id is always present and correct.
        $payload['x_account_id'] = $this->accountId;

        // Remove any pre-existing signature and (re)compute it over the body.
        // The signature is computed over the string representation of the
        // values (as TUU documents it), so it is stable regardless of the JSON
        // type used for x_amount below.
        unset($payload['x_signature']);
        $payload['x_signature'] = TuuSignature::generate($payload, $this->secretKey);

        // The API documents x_amount as a Number. Send it as a real JSON number
        // (e.g. 19990, not "19990") when it holds an integer value, so strict
        // validators accept it. Values with decimals are left as-is to avoid
        // changing their textual form.
        $encodePayload = $payload;
        if (isset($encodePayload['x_amount'])
            && is_string($encodePayload['x_amount'])
            && preg_match('/^\d+$/', $encodePayload['x_amount']) === 1) {
            $encodePayload['x_amount'] = (int) $encodePayload['x_amount'];
        }

        $body = json_encode($encodePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = [
            'success' => false,
            'http_code' => 0,
            'redirect_url' => null,
            'raw' => '',
            'data' => [],
            'error' => null,
            'request' => $payload,
        ];

        if ($body === false) {
            $result['error'] = 'Unable to JSON-encode the request payload.';

            return $result;
        }

        $headers = [
            'X-REDIRECT: false',
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $response = $this->httpPost($this->getEndpoint(), $body, $headers);

        $result['http_code'] = $response['http_code'];
        $result['raw'] = $response['body'];

        if ($response['error'] !== null) {
            $result['error'] = $response['error'];

            return $result;
        }

        $decoded = json_decode($response['body'], true);
        if (is_array($decoded)) {
            $result['data'] = $decoded;
            $result['redirect_url'] = $this->extractRedirectUrl($result['data']);
        } elseif (is_string($decoded) && $this->looksLikeUrl(trim($decoded))) {
            // Response was a JSON-encoded string, e.g. "https://...".
            $result['redirect_url'] = trim($decoded);
        }

        // Fallback: TUU returns the payment URL as a plain-text body (not JSON),
        // e.g. https://payment.haulmer.dev/secure/payment-intent/xxxx
        if (!$result['redirect_url']) {
            $bare = trim($response['body']);
            $bare = trim($bare, "\"'");
            if ($this->looksLikeUrl($bare)) {
                $result['redirect_url'] = $bare;
            }
        }

        // The intent is considered created when the API returns a 2xx status
        // and provides a URL to send the customer to.
        if ($response['http_code'] >= 200 && $response['http_code'] < 300 && $result['redirect_url']) {
            $result['success'] = true;
        } else {
            $result['error'] = $this->extractError($result['data'], $response);
        }

        return $result;
    }

    /**
     * Try to locate the redirect URL inside the API response, tolerating
     * several field names / nesting layouts.
     *
     * @param array $data
     *
     * @return string|null
     */
    private function extractRedirectUrl(array $data)
    {
        $candidateKeys = [
            'url', 'redirect_url', 'redirectUrl', 'payment_url', 'paymentUrl',
            'checkout_url', 'checkoutUrl', 'x_url', 'x_url_payment', 'link',
        ];

        // Direct hits.
        foreach ($candidateKeys as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $this->looksLikeUrl($data[$key])) {
                return $data[$key];
            }
        }

        // One level of nesting (data / result / payment ...).
        $nestingKeys = ['data', 'result', 'payment', 'response', 'intent'];
        foreach ($nestingKeys as $nest) {
            if (isset($data[$nest]) && is_array($data[$nest])) {
                foreach ($candidateKeys as $key) {
                    if (isset($data[$nest][$key])
                        && is_string($data[$nest][$key])
                        && $this->looksLikeUrl($data[$nest][$key])) {
                        return $data[$nest][$key];
                    }
                }
            }
        }

        // Last resort: any string value that looks like an http(s) URL.
        $found = $this->findUrlRecursive($data, 0);
        if ($found !== null) {
            return $found;
        }

        return null;
    }

    /**
     * @param mixed $data
     * @param int   $depth
     *
     * @return string|null
     */
    private function findUrlRecursive($data, $depth)
    {
        if ($depth > 4 || !is_array($data)) {
            return null;
        }
        foreach ($data as $value) {
            if (is_string($value) && $this->looksLikeUrl($value)) {
                return $value;
            }
            if (is_array($value)) {
                $found = $this->findUrlRecursive($value, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param string $value
     *
     * @return bool
     */
    private function looksLikeUrl($value)
    {
        return is_string($value) && preg_match('#^https?://#i', $value) === 1;
    }

    /**
     * Build a human-readable error message from an unsuccessful response.
     *
     * @param array $data
     * @param array $response
     *
     * @return string
     */
    private function extractError(array $data, array $response)
    {
        foreach (['message', 'error', 'error_message', 'detail', 'x_message'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        if (isset($data['errors']) && is_array($data['errors'])) {
            $flat = [];
            array_walk_recursive($data['errors'], function ($v) use (&$flat) {
                if (is_scalar($v)) {
                    $flat[] = (string) $v;
                }
            });
            if (!empty($flat)) {
                return implode(' ', $flat);
            }
        }

        return 'The payment gateway returned HTTP ' . (int) $response['http_code']
            . ' without a redirect URL.';
    }

    /**
     * Perform a POST request using cURL (falls back to stream context).
     *
     * @param string $url
     * @param string $body
     * @param array  $headers
     *
     * @return array{http_code:int, body:string, error:string|null}
     */
    private function httpPost($url, $body, array $headers)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $responseBody = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($responseBody === false) {
                return [
                    'http_code' => $httpCode,
                    'body' => '',
                    'error' => 'cURL error: ' . ($curlError !== '' ? $curlError : 'unknown'),
                ];
            }

            return [
                'http_code' => $httpCode,
                'body' => (string) $responseBody,
                'error' => null,
            ];
        }

        // Fallback: stream context.
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', $headerLine, $m)) {
                    $httpCode = (int) $m[1];
                }
            }
        }

        if ($responseBody === false) {
            return [
                'http_code' => $httpCode,
                'body' => '',
                'error' => 'HTTP request failed (stream context).',
            ];
        }

        return [
            'http_code' => $httpCode,
            'body' => (string) $responseBody,
            'error' => null,
        ];
    }
}
