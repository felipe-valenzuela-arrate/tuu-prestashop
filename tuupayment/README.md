# TUU by Haulmer — PrestaShop payment module

Accept online card payments (credit/debit) in your PrestaShop **9.1.x** store
through the [TUU by Haulmer](https://developers.tuu.cl/) payment gateway.

The module creates a **payment intent** against the TUU API, redirects the
customer to the TUU hosted payment page, and confirms the order from the
**server-to-server callback** (the source of truth), with the browser redirect
used only for user experience.

---

## Requirements

- PrestaShop **1.7.6+ / 8.x / 9.x** (developed and tested against 9.1.4).
- PHP **8.1+** with the **cURL** and **OpenSSL** extensions enabled.
- An HTTPS store URL (required by TUU for the production callback).
- A TUU account with an **Account ID** and a **Secret Key**.

## Installation

1. Zip the `tuupayment/` folder so the archive contains `tuupayment/tuupayment.php`
   at its root:

   ```bash
   cd /path/to/repo
   zip -r tuupayment.zip tuupayment \
     -x '*.DS_Store' -x '*/.git/*'
   ```

2. In the PrestaShop back office go to **Modules → Module Manager → Upload a
   module** and upload `tuupayment.zip` (or copy the `tuupayment/` folder into
   your store's `modules/` directory and install it from the list).

3. Click **Configure**.

## Configuration

| Setting | Description |
| ------- | ----------- |
| **Production mode** | Off = integration/sandbox endpoint. On = live production endpoint. |
| **Account ID** | `x_account_id` provided by TUU. |
| **Secret Key** | Used to sign requests and verify callbacks (HMAC-SHA256). Never shared with the browser. |
| **Payment title** | Text shown to the customer on the checkout payment step. |
| **Payment description** | Optional text shown under the title. |
| **Debug log** | Writes detailed entries to *Advanced Parameters → Logs*. Disable in production. |

### Endpoints used

| Environment | URL |
| ----------- | --- |
| Integration (sandbox) | `https://frontend-api.payment.haulmer.dev/v1/payment` |
| Production | `https://core.payment.haulmer.com/api/v1/payment` |

### Callback URL

The server-to-server callback URL (`x_url_callback`) is generated and sent
automatically on every payment. The configuration screen displays it for
reference, e.g.:

```
https://your-store.cl/module/tuupayment/callback
```

It must be reachable over HTTPS from the internet in production.

## Test credentials & cards (integration environment)

Sandbox credentials from the TUU documentation:

- **Account ID:** `62224230`
- **Secret Key:** `yAk0dXTJLQzkeEWODsQWVpPX0bn7ND50qwoQrXgqqNiUyEpgxIPxPtoCgKeLNeh1upTw72JZx5O9x5IaAtPIGUAVcMNcsUSg3M0M8tgWdUb4F8qkS8I7rHpOUmZqzvfS`

Test cards (integration only — any future expiry date; CVV 123 / 1234):

| Card | Number | Result |
| ---- | ------ | ------ |
| VISA | `4051 8856 0044 6623` | Approved |
| AMEX | `3700 0000 0002 032` | Approved |
| MASTERCARD | `5186 0595 5959 0568` | Rejected |

## How it works

1. **Checkout** — the customer selects the TUU payment option. The
   `payment` front controller builds a signed payment-intent payload
   (`x_amount`, `x_currency`, customer fields, and the callback / complete /
   cancel URLs), stores the attempt in `PREFIX_tuu_transaction`, and POSTs it
   to the TUU API with `X-REDIRECT: false`.
2. **Redirect** — the customer is sent to the TUU hosted payment page.
3. **Callback** (`x_url_callback`, POST `x-www-form-urlencoded`) — the
   authoritative result. The signature is verified, the amount is checked
   against the cart total, and on `x_result=completed` the PrestaShop order is
   created (state *Payment accepted*). Idempotent: repeated callbacks never
   create duplicate orders. Responds `200 OK` when processed; `4xx/5xx`
   otherwise so TUU retries (up to 10 times with exponential backoff).
4. **Return** (`x_url_complete`, GET) — user-facing. If the order already
   exists the customer sees the order confirmation; if the callback is still in
   flight a "confirming your payment" page is shown; on a failed result they are
   returned to checkout with an error.
5. **Cancel** (`x_url_cancel`, GET) — the attempt is marked cancelled and the
   customer returns to checkout.

## Signature (`x_signature`)

`x_signature` is an **HMAC-SHA256** over every parameter whose name starts with
`x_` (excluding `x_signature`), sorted alphabetically (ASCII, case-sensitive),
with `key`+`value` concatenated without separators, hex lowercase, UTF-8. It is
computed for outgoing requests and verified on every incoming notification.
See `classes/TuuSignature.php`.

## Security notes

- The Secret Key is only ever used server-side; it is never exposed to the
  browser or JavaScript.
- Incoming callbacks are rejected unless the signature is valid.
- The charged amount is validated against the cart total before the order is
  created, protecting against tampered redirects.

## License

MIT.
