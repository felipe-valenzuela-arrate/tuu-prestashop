<?php
/**
 * TUU (Haulmer) payment module for PrestaShop.
 *
 * Helper to generate and verify the x_signature (HMAC-SHA256) used by TUU
 * to guarantee integrity and authenticity of requests and callbacks.
 *
 * @author    Felipe Valenzuela
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TuuSignature
{
    /**
     * Build the HMAC-SHA256 signature for a set of parameters, following the
     * TUU specification:
     *   1. Keep only keys that start with "x_".
     *   2. Exclude "x_signature" (a signature never signs itself).
     *   3. Sort keys with strict alphabetical (ASCII, case-sensitive) order.
     *   4. Concatenate key+value pairs directly, with no separators.
     *      Empty values contribute just the key.
     *   5. HMAC-SHA256 with the secret key, lowercase hexadecimal output.
     *
     * @param array  $data      Parameters (associative array).
     * @param string $secretKey Merchant secret key.
     *
     * @return string 64-char lowercase hexadecimal signature.
     */
    public static function generate(array $data, $secretKey)
    {
        // 1 & 2. Keep only x_* keys, excluding x_signature.
        $toSign = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (strpos($key, 'x_') !== 0) {
                continue;
            }
            if ($key === 'x_signature') {
                continue;
            }
            $toSign[$key] = $value;
        }

        // 3. Strict alphabetical order by key (ASCII, case-sensitive).
        ksort($toSign, SORT_STRING);

        // 4. Concatenate key + value without separators.
        $chain = '';
        foreach ($toSign as $key => $value) {
            $chain .= $key . self::stringifyValue($value);
        }

        // 5. HMAC-SHA256, lowercase hex.
        return hash_hmac('sha256', $chain, (string) $secretKey);
    }

    /**
     * Verify that a received signature matches the expected one.
     *
     * @param array  $data              Received parameters (must include the
     *                                   received x_signature or it can be passed
     *                                   separately via $receivedSignature).
     * @param string $secretKey         Merchant secret key.
     * @param string|null $receivedSignature Optional explicit signature to check.
     *
     * @return bool
     */
    public static function verify(array $data, $secretKey, $receivedSignature = null)
    {
        if ($receivedSignature === null) {
            $receivedSignature = isset($data['x_signature']) ? $data['x_signature'] : '';
        }

        if (!is_string($receivedSignature) || $receivedSignature === '') {
            return false;
        }

        $expected = self::generate($data, $secretKey);

        // Constant-time comparison to avoid timing attacks.
        return hash_equals($expected, strtolower(trim($receivedSignature)));
    }

    /**
     * Normalise a value to its string representation as expected by the
     * signature algorithm: values are taken "as is", numbers as plain strings
     * without additional formatting, booleans as their scalar text.
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function stringifyValue($value)
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        // Arrays / objects are not expected in the signed payload; ignore them.
        return '';
    }
}
