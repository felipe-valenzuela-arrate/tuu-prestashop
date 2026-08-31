# tuu-prestashop

Módulo para recibir pagos a través de **TUU by Haulmer** desde PrestaShop
(desarrollado y probado contra **PrestaShop 9.1.4**).

El código del módulo está en la carpeta [`tuupayment/`](./tuupayment). Consulta
[`tuupayment/README.md`](./tuupayment/README.md) para las instrucciones
completas de instalación, configuración, credenciales de prueba y detalles del
flujo de integración (intento de pago, callback server-to-server y firma
`x_signature`).

## Instalación rápida

1. Genera el `.zip` del módulo (la carpeta `tuupayment/` debe quedar en la raíz
   del archivo):

   ```bash
   zip -r tuupayment.zip tuupayment -x '*/.git/*' -x '*.DS_Store'
   ```

2. En el back office: **Módulos → Gestor de módulos → Subir un módulo** y sube
   `tuupayment.zip`.

3. Pulsa **Configurar** e ingresa tu **Account ID** y **Secret Key** de TUU.

## Características

- Opción de pago en el checkout que redirige a la pasarela alojada de TUU.
- Confirmación del pago mediante **callback server-to-server** (fuente de
  verdad), con verificación de firma **HMAC-SHA256** y validación de monto.
- Creación de la orden idempotente (sin órdenes duplicadas ante reintentos).
- Ambientes de **integración** y **producción** conmutables.
- Registro de transacciones y log de depuración opcional.
