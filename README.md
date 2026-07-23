# Albaranes — módulo para Invoice Ninja (self-hosted)

Añade **albaranes** a Invoice Ninja v5 y permite **agruparlos en una factura** detallada línea por línea. Se instala como módulo `nwidart/laravel-modules` activable, sin tocar el frontend de Invoice Ninja.

## Cómo funciona

- Un **albarán es un presupuesto (Quote) marcado** con un campo personalizado (configurable, por defecto `custom_value4 = "albaran"`). Así conviven presupuestos normales y albaranes usando la misma interfaz de presupuestos que ya conoces.
- El **PDF del albarán** es el PDF del presupuesto con el rótulo cambiado a "Albarán": se sirve desde el propio módulo (`GET /api/v1/albaranes/quotes/{id}/pdf`), inyectando traducciones sólo durante ese render. No se altera el diseño ni el PDF de los presupuestos normales.
- El albarán se puede **enviar por correo al cliente** desde el propio módulo, eligiendo a qué contactos. El correo sale con el **PDF, el asunto y el cuerpo rotulados "Albarán"**: se envía de forma síncrona dentro del mismo bloque de rótulos, porque en cola el mensaje se construiría después de restaurarlos. La mini-página muestra **si cada albarán se ha enviado y cuándo**.
- Una **mini-página propia del plugin** (`/albaranes`) busca un cliente, deja **marcar/desmarcar** sus presupuestos como albarán, ver el **PDF** de cada uno, **enviarlos** y **generar una factura** con los seleccionados. La factura incluye, por cada albarán, una **línea de cabecera** (`Albarán Nº · fecha`) seguida de **sus líneas detalladas**, y marca esos albaranes como facturados (enlazados a la factura).

Reutiliza la maquinaria del core (`CloneQuoteToInvoiceFactory`, servicio de facturas, `Quote::STATUS_CONVERTED`); no reimplementa cálculos ni numeración.

## Requisitos

- Invoice Ninja v5.13+ self-hosted.
- No necesita cola (`queue:work`) ni scheduler.

## Instalación

Con imagen Docker propia (recomendado, `FROM invoiceninja/invoiceninja:5.x`):

```dockerfile
FROM alpine:3.20 AS albaranes
RUN apk add --no-cache git
RUN git clone --depth 1 https://github.com/Nakytec/albaranes.git /src && rm -rf /src/.git

FROM invoiceninja/invoiceninja:5.13.26
COPY --chown=1500:1500 --from=albaranes /src /var/www/app/Modules/Albaranes
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN cd /var/www/app \
    && composer dump-autoload --no-interaction --optimize \
    && php -r '$f = "modules_statuses.json"; $j = json_decode(file_get_contents($f), true) ?: []; $j["Albaranes"] = true; file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT) . "\n");'
```

Sobre una instalación ya existente:

```bash
cd /ruta/a/invoiceninja
git clone https://github.com/Nakytec/albaranes.git Modules/Albaranes
composer dump-autoload
php artisan module:enable Albaranes
php artisan optimize:clear
```

## Configuración

`.env` (opcional; valores por defecto entre paréntesis):

```dotenv
ALBARAN_MARKER_FIELD=custom_value4     # campo del presupuesto que marca "albarán"
ALBARAN_MARKER_VALUE=albaran           # valor que indica que es albarán
ALBARAN_DOCUMENT_LABEL=Albarán         # rótulo impreso (título y cabeceras)
```

Elige un `ALBARAN_MARKER_FIELD` (`custom_value1..4`) que no uses para otra cosa en presupuestos. No hace falta activarlo en Settings: el marcado se hace desde la mini-página. Si además quieres marcarlos desde la interfaz de presupuestos, activa el campo correspondiente en Settings → Custom Fields → Invoice (Invoice Ninja comparte esos campos con los presupuestos); ten en cuenta que entonces también aparecerá en facturas.

## Uso

1. Entra en `https://TU-INSTANCIA/albaranes` e introduce tu **token de API** (Settings → Account Management → API Tokens). Se guarda sólo en tu navegador.
2. Busca el cliente y pulsa **→ Albarán** en los presupuestos que quieras convertir en albarán.
3. Con **Enviar** mandas el albarán a los contactos marcados en *Enviar los albaranes a* (por defecto, los que reciben correo en la ficha del cliente). La columna **Enviado** muestra la fecha del último envío, o *Sin enviar*.
4. Selecciona los albaranes pendientes y pulsa **Generar factura**.

También por API (`X-API-TOKEN`):

```bash
# Albaranes pendientes de un cliente
GET  /api/v1/albaranes/clients/{client_id}
# Presupuestos del cliente que aún no son albaranes
GET  /api/v1/albaranes/clients/{client_id}/candidates
# Consolidar los seleccionados en una factura
POST /api/v1/albaranes/clients/{client_id}/consolidate   {"albaranes": ["ID1","ID2"]}
# Marcar/desmarcar un presupuesto como albarán
PUT  /api/v1/albaranes/quotes/{quote_id}/toggle          {"albaran": true}
# PDF con rótulo "Albarán"
GET  /api/v1/albaranes/quotes/{quote_id}/pdf
# Enviar el albarán por correo (contacts opcional: por defecto, los que reciben correo)
POST /api/v1/albaranes/quotes/{quote_id}/email         {"contacts": ["ID1"]}
```

## Límites (por diseño)

- **No añade pantallas nativas al panel de Invoice Ninja** (su admin es una app compilada aparte). La gestión visual se hace en la mini-página propia; la creación reutiliza la interfaz de presupuestos.
- El PDF con rótulo "Albarán" se obtiene desde el endpoint del módulo (o los botones PDF/Enviar de la mini-página); descargado o enviado desde el panel de presupuestos, sale rotulado como presupuesto.
- El rótulo "Albarán" en el correo se aplica sobre los textos traducidos de Invoice Ninja. Si la empresa tiene una **plantilla de correo personalizada** con la palabra "presupuesto" escrita a mano, ese texto se envía tal cual.

## Licencia

MIT.
