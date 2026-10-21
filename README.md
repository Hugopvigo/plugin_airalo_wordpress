<p align="center">
  <img src="docs/capturas/00-cabecera.png" alt="Airalo para WordPress — backoffice de la cuenta de partner de Airalo en WooCommerce" width="100%">
</p>

<p align="center">
  <b>Un backoffice de WordPress para la cuenta de partner de Airalo.</b><br>
  Vincula pedidos de WooCommerce con órdenes de Airalo, muestra el consumo de datos de cada eSIM,
  pide reembolsos, hace top-ups y exporta a CSV, sin salir del panel.
</p>

<p align="center">
  <img alt="licencia" src="https://img.shields.io/badge/licencia-CC%20BY--NC--SA%204.0-8E8E93?style=flat-square&labelColor=1a1a1e">
  <img alt="php" src="https://img.shields.io/badge/PHP-7.4%2B-8E8E93?style=flat-square&labelColor=1a1a1e">
  <img alt="wordpress" src="https://img.shields.io/badge/WordPress-6.0%2B-8E8E93?style=flat-square&labelColor=1a1a1e">
  <img alt="version" src="https://img.shields.io/badge/versi%C3%B3n-2.4.3-3DDC84?style=flat-square&labelColor=1a1a1e">
  <img alt="woocommerce" src="https://img.shields.io/badge/WooCommerce-8E8E93?style=flat-square&labelColor=1a1a1e">
</p>

---

## Qué es esto

El plugin oficial de WooCommerce de Airalo ya cubre la venta y el envío del QR por correo.
Este plugin añade la capa de **operación diaria** que falta en producción:

- **Metabox en la pantalla de pedido** de WooCommerce: orden de Airalo vinculada, ICCIDs, barra de
  consumo por eSIM, botones de sincronizar, QR, reembolso y top-up.
- **Detalle de eSIM**: QR, instrucciones de iOS y Android, instalación manual SM-DP+, paquetes de
  top-up disponibles y asignación de *eSIM User*.
- **Balance de la cuenta**, listado de órdenes y listado de eSIMs (incluidas las expiradas),
  con widget propio en el dashboard de WordPress.
- **Reembolsos** por ICCID y **reconciliación** entre pedidos de WooCommerce y órdenes de Airalo.
- **Exportación a CSV** de órdenes y de eSIMs, para contabilidad.
- **Webhook listener** de Airalo (firma HMAC obligatoria) y **cron diario** de refresco.
- **Shortcode `[buscador_dispositivos]`** para el front: buscador de móviles y tablets compatibles.

El plugin habla **REST directamente** contra la Airalo Partner API v2 (`wp_remote_*`), sin el SDK
`airalo/sdk`, para no chocar con las clases `Airalo\*` del plugin oficial cuando ambos están
activos en el mismo sitio.

## Requisitos

| Componente | Versión mínima | Para qué |
|---|---|---|
| WordPress | 6.0 | — |
| PHP | 7.4 (recomendado 8.0+) | — |
| Composer | 2.x | Instalar la única dependencia (`vlucas/phpdotenv`) |
| WooCommerce | cualquier versión activa | Metabox del pedido y vinculación de órdenes |

Sin WooCommerce el resto del panel sigue funcionando (dashboard, eSIMs, ajustes, shortcode), pero
el plugin baja a la capacidad `manage_options` en lugar de `manage_woocommerce`.

## Instalación

```bash
cd /ruta/al/wp-content/plugins/
git clone https://github.com/Hugopvigo/plugin_airalo_wordpress.git mi-plugin-airalo
cd mi-plugin-airalo
composer install --no-dev --optimize-autoloader
```

`composer install` **no es opcional**: el plugin carga `vendor/autoload.php` al arrancar.

Después, credenciales (fuera del repo, con permisos restrictivos):

```bash
cat > .env <<'EOF'
AIRALO_CLIENT_ID=tu_client_id
AIRALO_CLIENT_SECRET=tu_client_secret
AIRALO_ENV=production
EOF
chmod 600 .env
```

Y activa el plugin desde *Plugins* en el admin. Para desplegarlo en un servidor, `package.sh`
empaqueta la versión que declara la cabecera del plugin:

```bash
./package.sh          # → /tmp/mi-plugin-airalo-2.4.3.zip
```

### Verificación

- [ ] En el admin aparece el menú **Airalo**.
- [ ] *Airalo → Ajustes* dice **Configuradas** y el entorno correcto.
- [ ] *Probar conexión* responde con el balance de la cuenta.
- [ ] El dashboard de WordPress muestra el widget de balance.
- [ ] En un pedido de WooCommerce aparece el metabox **Airalo**.
- [ ] El shortcode `[buscador_dispositivos]` responde en el front.

## Configuración

### Credenciales

Se leen de `$_ENV` → `$_SERVER` → `getenv()` → opción `mpa_settings`, en ese orden. Además de
`.env`, valen las constantes de `wp-config.php`:

```php
putenv( 'AIRALO_CLIENT_ID=tu_client_id' );
putenv( 'AIRALO_CLIENT_SECRET=tu_client_secret' );
putenv( 'AIRALO_ENV=sandbox' );
```

`AIRALO_ENV` acepta `production` (por defecto) y `sandbox`. El valor **no** cambia la URL de la
API: la base es siempre `https://partners-api.airalo.com/v2`. Sirve como etiqueta para no
confundir un entorno con otro en el panel.

### Webhook de Airalo

El listener exige un **secreto compartido** (Airalo no firma sus webhooks de fábrica):

1. En *Airalo → Ajustes* pulsa **Generar secreto**. Se guarda en la opción `mpa_settings`
   (`webhook_secret`).
2. En el panel de partners de Airalo, en la sección de webhooks, apunta a una de estas dos URLs:

| Endpoint | Nota |
|---|---|
| `https://tusitio.com/wp-json/mpa/v1/webhook` | Ruta REST, la recomendada |
| `https://tusitio.com/?mpa_webhook=1` | Fallback sobre `init` |

3. Airalo debe enviar la cabecera `X-MPA-Signature: sha256=<hmac-sha256 del cuerpo con el secreto>`.

Si el secreto está vacío, el endpoint responde **401** y rechaza todo. Además aplica un límite de
**20 peticiones por IP cada 5 minutos**, un tope de **8 KB** por payload y una lista blanca de
eventos (`order.status.changed`, `order.completed`, `sim.activated`, `sim.expired`,
`sim.low_data`, `sim.usage.threshold`).

## Uso

El menú **Airalo** tiene siete pantallas:

| Pantalla | Qué hace |
|---|---|
| **Dashboard** | Balance, entorno, número de órdenes de las últimas 24 h y tabla de las 50 más recientes |
| **Órdenes** | Listado paginado de las órdenes de Airalo (20 por página), con enlace al detalle de la eSIM |
| **eSIMs** | Todas las eSIMs de la cuenta (buscador por ICCID), con acciones de uso y reembolso |
| **Detalle eSIM** | Consumo, QR, instrucciones iOS/Android, instalación manual y top-ups |
| **Ajustes** | Estado de la conexión, prueba de conexión y secreto del webhook |
| **Place order** | Alta manual de órdenes. Marcado en rojo como herramienta técnica: genera cargos reales |
| **Reembolsos** | Reembolso por ICCID y reconciliación WooCommerce ↔ Airalo |

Fuera del menú:

- **Metabox en el pedido de WooCommerce** (columna lateral): estado de consumo por ICCID con barra
  de color por umbral, botón de sincronizar, QR, reembolso y top-up.
- **Widget en el dashboard de WordPress**: balance en USD y últimas órdenes.
- **Shortcode `[buscador_dispositivos]`** en el front: busca por nombre, marca o modelo y devuelve
  hasta 15 resultados.

Los motivos de reembolso que acepta la API son `INVALID_ACTIVATION`, `DUPLICATE_ORDER`,
`SERVICE_ISSUES` y `OTHERS`. Airalo **solo acepta un reembolso cada 5 minutos por IP**.

## Hooks principales

**Shortcode**

```php
[buscador_dispositivos]
```

**Hook de WordPress que usa**

`plugins_loaded`, `admin_menu`, `admin_head`, `admin_enqueue_scripts`, `wp_enqueue_scripts`,
`wp_dashboard_setup`, `add_meta_boxes`, `init`, `rest_api_init`, `admin_post_*`, `wp_ajax_*` y
`register_activation_hook` / `register_deactivation_hook`.

**Cron**

| Hook | Cuándo | Qué hace |
|---|---|---|
| `mpa_daily_sync` | diario | Refresca balance y dispositivos compatibles; guarda el resultado en `mpa_last_sync` |

Forzarlo a mano: `wp cron event run mpa_daily_sync`.

**Filtros**

```php
add_filter( 'mpa_cache_ttl_balance', fn() => 10 * MINUTE_IN_SECONDS );
add_filter( 'mpa_cache_ttl_devices', fn() => 12 * HOUR_IN_SECONDS );
add_filter( 'mpa_cache_ttl_token',   fn() => 30 * MINUTE_IN_SECONDS );
add_filter( 'mpa_cache_ttl_orders',  fn() => 10 * MINUTE_IN_SECONDS );
add_filter( 'mpa_cache_ttl_orders_stale', fn() => 2 * DAY_IN_SECONDS );
add_filter( 'mpa_request_timeout',   fn() => 30 );
add_filter( 'mpa_env_label',         fn() => 'production' );
add_filter( 'mpa_usage_autosync_threshold', fn() => 5 * MINUTE_IN_SECONDS );
```

**Acciones**

```php
add_action( 'mpa_webhook_received', function ( $event, $data, $wc_order ) { /* ... */ }, 10, 3 );
add_action( 'mpa_revalidate_orders', function ( $params ) { /* ... */ } );
```

**Endpoint REST**

`POST /wp-json/mpa/v1/webhook` (firma HMAC + límite de tasa + lista blanca de eventos).

**Acciones AJAX** (`wp_ajax_*`, con nonce `mpa_admin` y comprobación de capacidad)

| `action` | Qué hace |
|---|---|
| `mpa_sync_order` | Sincroniza el pedido de WooCommerce con Airalo |
| `mpa_get_usage` | Consulta y guarda el consumo de una ICCID |
| `mpa_get_qr` | Descarga instrucciones y QR de instalación |
| `mpa_refund_esim` | Pide el reembolso de una ICCID |
| `mpa_refund_esim_by_iccid` | Lo mismo, resolviendo el pedido por ICCID |
| `mpa_topup` | Añade datos a una eSIM activa |
| `mpa_share_link` | Devuelve la ICCID de la eSIM |
| `mpa_share_esim` | Envía la eSIM a un correo (link o QR) |
| `mpa_assign_esim_user` | Asigna *eSIM User* (nombre + email) en Airalo |

**Metas de pedido** que guarda el plugin: `_mpa_airalo_order_id`, `_mpa_airalo_orders`,
`_mpa_airalo_usage`, `_mpa_airalo_instructions`, `_mpa_airalo_topups`, `_mpa_airalo_refund`,
`_mpa_airalo_users` y `_mpa_processed_by`.

## Estructura

```
plugin_airalo_wordpress/
├── mi-plugin-airalo.php     bootstrap: cabecera WP, constantes, carga y arranque
├── uninstall.php             borra opciones, transients, metas y el cron al desinstalar
├── package.sh                genera el zip de distribución
├── changelog.txt             historial de versiones
├── composer.json             PSR-4: Hugo\MiPluginAiralo\ → src/
├── languages/                traducciones es_ES y en_GB (+ .pot)
├── assets/                   admin.css, shortcode.css, admin.js, shortcode.js
└── src/
    ├── Plugin.php            singleton: cablea todo en plugins_loaded
    ├── Env/Config.php        credenciales, TTL de caché y timeout
    ├── Api/Client.php        cliente REST de la Airalo Partner API v2
    ├── Api/Exception.php     excepción tipada
    ├── Admin/Menu.php        menú «Airalo» y subpáginas
    ├── Admin/DashboardWidget.php
    ├── Admin/Pages/          Dashboard, Orders, Esims, EsimDetail, Settings, PlaceOrder, Reconciliation
    ├── Admin/Ajax/           OrderActions (acciones AJAX) y Export (CSV)
    ├── Cron/Daily.php        hook mpa_daily_sync
    ├── Webhooks/AiraloListener.php   listener REST + fallback
    ├── Integrations/WooCommerce/     OrderLinker y OrderMetaBox
    ├── Front/DispositivosShortcode.php  shortcode [buscador_dispositivos]
    └── Support/              Assets, Logger, Countries
```

## Licencia

El [`LICENSE`](LICENSE) del repositorio es **CC BY-NC-SA 4.0** (Creative Commons
Attribution-NonCommercial-ShareAlike 4.0 International): se puede compartir y adaptar, con
atribución, **sin uso comercial** y manteniendo la misma licencia en las obras derivadas.

Todo está alineado con ese fichero: el `composer.json` declara la licencia `CC-BY-NC-SA-4.0`, la
cabecera del plugin (`mi-plugin-airalo.php`) indica `License: CC BY-NC-SA 4.0` y `changelog.txt`
repite `License: CC BY-NC-SA 4.0`.

© 2024-2026 SUOP Mobile SL — Hugo Pérez-Vigo ([@hugopvigo](https://twitter.com/hugopvigo)).
