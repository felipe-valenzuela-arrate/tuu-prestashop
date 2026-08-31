{*
 * TUU (Haulmer) payment module for PrestaShop.
 * Order confirmation message.
 *}
<div class="tuu-payment-return">
  <p>
    {l s='Your payment through TUU by Haulmer has been processed successfully.' mod='tuupayment'}
  </p>
  <p>
    {l s='Your order reference is:' mod='tuupayment'} <strong>{$reference|escape:'html':'UTF-8'}</strong>
  </p>
  <p>
    {l s='If you have any questions, please' mod='tuupayment'}
    <a href="{$contact_url|escape:'html':'UTF-8'}">{l s='contact us' mod='tuupayment'}</a>.
  </p>
</div>
