{*
 * TUU (Haulmer) payment module for PrestaShop.
 * Shown when the customer returns before the server-to-server callback has
 * confirmed the payment.
 *}
{extends file='page.tpl'}

{block name='page_content'}
  <section class="tuu-pending">
    <h1 class="h1">{l s='We are confirming your payment' mod='tuupayment'}</h1>
    <p>
      {l s='Thank you. We have received your payment request and are waiting for TUU to confirm the result.' mod='tuupayment'}
    </p>
    <p>
      {l s='This usually takes only a few moments. Your order reference is:' mod='tuupayment'}
      <strong>{$reference|escape:'html':'UTF-8'}</strong>
    </p>
    <p>
      {l s='Once confirmed, your order will appear in your order history. You do not need to pay again.' mod='tuupayment'}
    </p>
    <p>
      <a class="btn btn-primary" href="{$history_url|escape:'html':'UTF-8'}">
        {l s='View my orders' mod='tuupayment'}
      </a>
    </p>
  </section>
{/block}
