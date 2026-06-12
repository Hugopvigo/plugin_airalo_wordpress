# Mi Plugin Airalo

> Plugin de backoffice para WordPress + WooCommerce que integra la API de Airalo.

**Autor:** SUOP Mobile SL — Hugo Pérez-Vigo ([@hugopvigo](https://twitter.com/hugopvigo)) — <https://www.suop.es/>

| Campo | Valor |
|---|---|
| Versión del plugin | `2.4.3` |
| Namespace PSR-4 | `Hugo\MiPluginAiralo\` |
| Text domain | `mi-plugin-airalo` |
| Slug | `mi-plugin-airalo` |
| Requiere WP | 6.0+ |
| Requiere PHP | 7.4+ (recomendado 8.0+) |
| Requiere WooCommerce | Sí |
| Licencia | [CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/) |

---

## Índice

1. [¿Qué es?](#1-qué-es)
2. [Capturas / UX](#2-capturas--ux)
3. [Arquitectura](#3-arquitectura)
4. [Modelo de seguridad](#4-modelo-de-seguridad)
5. [Modelo de caché](#5-modelo-de-caché)
6. [Instalación](#6-instalación)
7. [Configuración](#7-configuración)
8. [Catálogo de funciones / métodos](#8-catálogo-de-funciones--métodos)
9. [Endpoints AJAX expuestos](#9-endpoints-ajax-expuestos)
10. [Webhook listener](#10-webhook-listener)
11. [Cron jobs](#11-cron-jobs)
12. [Hooks / filtros para developers](#12-hooks--filtros-para-developers)
13. [Migración desde el plugin legacy](#13-migración-desde-el-plugin-legacy)
14. [Changelog](#14-changelog)
15. [Roadmap](#15-roadmap)
16. [Licencia](#16-licencia)

---

## 1. ¿Qué es?

Un panel de backoffice para gestionar la cuenta de partner de Airalo desde WordPress. El plugin
de WooCommerce oficial de Airalo ya se encarga del flujo de venta y entrega de QR por email;
este plugin añade el resto de herramientas que faltan en producción:

- **Vinculación de pedidos WC ↔ Airalo** (con detección de la meta del plugin oficial).
- **Refunds** desde la página de pedido o desde la página de detalle de eSIM.
- **Página de detalle de eSIM** con QR, instrucciones iOS/Android en tabs, manual SM-DP+,
  paquetes de top-up disponibles, uso, identificadores copiables.
- **Top-ups** sobre eSIMs activas.
- **Visualización de uso de datos** por eSIM, con auto-sync cada 30 min.
- **Listado de órdenes, listado de eSIMs, balance de cuenta.**
- **Widget de balance** en el dashboard de WP.
- **Reconciliación** (huérfanos WC ↔ Airalo).
- **Place order manual** (testing/operativa).
- **Export CSV** de órdenes y eSIMs (contabilidad).
- **Webhook listener** para notificaciones de Airalo (low data, expired).
- **Cron diario** que refresca balance y devices.
- **Shortcode de buscador de dispositivos compatibles** en el front (migrado desde
  `dispositivos-api.php`).

---

## 2. Capturas / UX

| Vista | Qué muestra |
|---|---|
| **Dashboard** (`Airalo > Dashboard`) | 3 cards (balance, entorno, últimas 24h) + tabla de últimas 10 órdenes |
| **Órdenes** | Tabla paginada con todas las órdenes de Airalo |
| **eSIMs** | Tabla con chips clicables hacia la página de detalle |
| **eSIM detail** (`?page=mpa-esim-detail&iccid=...`) | Header con ICCID + pills, card grande de uso con barra coloreada por umbral, tabs iOS/Android/Manual/QR, grid de top-ups, sidebar con acciones y datos |
| **Reconciliación** | Dos tablas: WC sin Airalo, Airalo sin WC |
| **Place order** | Form con `<select>` de paquetes + qty + descripción |
| **Metabox WC** | Pills de estado de uso (% usado con color), barras, botones de acción, link "Detalle →" |
| **Widget WP** | Balance grande + últimas órdenes + crédito del autor |

### Sistema de colores (CSS variables)

```css
--mpa-accent:    #00A8A8   /* brand teal */
--mpa-success:   #00a32a
--mpa-warning:   #dba617
--mpa-danger:    #b32d2e
--mpa-text:      #1d2327
--mpa-text-soft: #50575e
--mpa-text-mute: #8c8f94
```

### Umbrales de la barra de uso

| % usado | Estado | Color |
|---|---|---|
| `< 70%` | OK | teal (`#00A8A8 → #00C896`) |
| `70 – 89%` | Warning | amarillo (`#f0b429 → #dba617`) |
| `≥ 90%` | Critical | rojo (`#d63638 → #b32d2e`) |

---

## 3. Arquitectura

```
mi_plugin_airalo/
├── mi-plugin-airalo.php          ← bootstrap (header WP, define, require, hook boot)
├── uninstall.php                 ← limpieza en desinstalación
├── composer.json / composer.lock ← dependencias PHP
├── .env                          ← credenciales (NO se commitea)
├── .gitignore
├── languages/                    ← i18n
├── vendor/                       ← dependencias Composer
├── assets/
│   ├── css/
│   │   ├── admin.css             ← estilos admin (cards, tabs, copy, detail)
│   │   └── shortcode.css         ← estilos del buscador front
│   └── js/
│       ├── admin.js              ← handlers AJAX, tabs, copy buttons
│       └── shortcode.js          ← placeholder
└── src/
    ├── Plugin.php                ← Singleton, cablea todo
    ├── Env/Config.php            ← Loader de .env / constantes / opciones
    ├── Support/
    │   ├── Logger.php            ← error_log con redacción de secretos
    │   └── Assets.php            ← enqueue admin + front
    ├── Api/
    │   ├── Client.php            ← Wrapper REST sobre Airalo Partner API
    │   └── Exception.php         ← Excepción tipada
    ├── Admin/
    │   ├── Menu.php              ← Top-level menu + subpáginas
    │   ├── DashboardWidget.php   ← Widget en wp-admin/index.php
    │   ├── Pages/
    │   │   ├── Dashboard.php
    │   │   ├── Orders.php
    │   │   ├── Esims.php
    │   │   ├── EsimDetail.php    ← Página de detalle (QR, tabs, top-ups)
    │   │   ├── Settings.php
    │   │   ├── PlaceOrder.php    ← Crear orden manual
    │   │   └── Reconciliation.php← Huérfanos WC ↔ Airalo
    │   └── Ajax/
    │       ├── OrderActions.php  ← Endpoints AJAX (nonce + cap)
    │       └── Export.php        ← CSV de órdenes / eSIMs
    ├── Cron/
    │   └── Daily.php             ← Hook `mpa_daily_sync`
    ├── Webhooks/
    │   └── AiraloListener.php    ← POST /wp-json/mpa/v1/webhook + ?mpa_webhook=1
    ├── Integrations/
    │   └── WooCommerce/
    │       ├── OrderLinker.php   ← Bridge WC ↔ Airalo (meta y helpers)
    │       └── OrderMetaBox.php  ← Metabox en la página de pedido
    └── Front/
        └── DispositivosShortcode.php  ← [buscador_dispositivos]
```

### Flujo de inicialización

```
plugins_loaded
   └─ Plugin::instance()->boot()
        ├─ load_plugin_textdomain
        ├─ Assets::register()            (enqueue condicional)
        ├─ Menu::register()              (menú top + subpáginas + hide hidden)
        ├─ DashboardWidget::register()   (widget en index.php)
        ├─ Pages\*::register()           (callbacks render)
        ├─ Ajax\OrderActions::register() (handlers wp_ajax_*)
        ├─ Ajax\Export::register()       (admin_post_*)
        ├─ Cron\Daily::register()        (mpa_daily_sync)
        ├─ Webhooks\AiraloListener::register() (REST + ?mpa_webhook=1)
        ├─ Integrations\Woo\OrderLinker + OrderMetaBox (si WC activo)
        └─ Front\DispositivosShortcode
```

---

## 4. Modelo de seguridad

| Capa | Implementación |
|---|---|
| Acceso a archivos | `defined( 'ABSPATH' ) \|\| exit;` en todos los archivos PHP |
| Capacidades | `manage_woocommerce` si WC activo, si no `manage_options` |
| AJAX | `check_ajax_referer( 'mpa_admin', 'nonce' )` + `current_user_can( cap )` |
| Sanitización | `sanitize_text_field`, `absint`, `sanitize_key`, `wp_unslash` |
| Escape de salida | `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, `wp_json_encode` |
| Credenciales | `.env` + `getenv()` + `$_ENV` + `wp_options['mpa_settings']` fallback |
| HTTPS | `MPA_API_BASE = https://partners-api.airalo.com/v2` (sin override) |
| Timeouts | `wp_remote_*` con `timeout = MPA_FILTER_REQUEST_TIMEOUT` (15s por defecto) |
| Logs | `error_log` con redacción automática de claves sensibles |
| SQL | `$wpdb->prepare()` + `esc_like`; cero SQL dinámico en runtime |

---

## 5. Modelo de caché

| Dato | Clave | TTL | Filtro |
|---|---|---|---|
| Token OAuth | `transient('mpa_airalo_token')` | `expires_in - 1h` | `mpa_cache_ttl_token` |
| Balance / exchange rates | `transient('mpa_airalo_balance')` | 5 min | `mpa_cache_ttl_balance` |
| Dispositivos compatibles | `transient('mpa_airalo_devices')` | 24 h | `mpa_cache_ttl_devices` |
| Lista simple de paquetes | `transient('mpa_airalo_packages_simple')` | 1 h | — |
| Datos por pedido | `update_post_meta` (persistente) | infinito | — |

---

## 6. Instalación

### 6.1. Requisitos

| Componente | Versión mínima |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 (recomendado 8.0+) |
| Composer | 2.x (solo en deploy) |
| WooCommerce | activo y configurado |

### 6.2. Local (desarrollo)

```bash
# 1. Clonar el plugin en wp-content/plugins/
cd /path/to/wp/wp-content/plugins/
git clone git@github.com:tuser/mi_plugin_airalo.git
cd mi_plugin_airalo

# 2. Instalar dependencias PHP
composer install --no-dev --optimize-autoloader

# 3. Configurar credenciales
cat > .env <<'EOF'
AIRALO_CLIENT_ID=tu_client_id_de_airalo
AIRALO_CLIENT_SECRET=tu_client_secret_de_airalo
AIRALO_ENV=production
EOF
chmod 600 .env
```

### 6.3. Producción (servidor)

```bash
rsync -avz --delete \
  --exclude='.env' --exclude='.git/' --exclude='node_modules/' \
  ./mi_plugin_airalo/ usuario@servidor:/var/www/html/wp-content/plugins/mi_plugin_airalo/
```

```bash
ssh usuario@servidor
cd /var/www/html/wp-content/plugins/mi_plugin_airalo
chown -R www-data:www-data .
chmod 600 .env
```

### 6.4. Verificación post-instalación

- [ ] WP-Admin → *Airalo* aparece en el sidebar.
- [ ] *Airalo → Ajustes* dice "Configuradas" y entorno correcto.
- [ ] "Probar conexión" devuelve "Conexión correcta" con un balance.
- [ ] WP Dashboard muestra widget "Airalo".
- [ ] En un pedido WC de eSIM aparece el metabox "Airalo".
- [ ] El shortcode `[buscador_dispositivos]` funciona en el front.

---

## 7. Configuración

### 7.1. `.env` (recomendado)

```ini
AIRALO_CLIENT_ID=tu_client_id
AIRALO_CLIENT_SECRET=tu_client_secret
AIRALO_ENV=production   # o "sandbox"
```

### 7.2. `wp-config.php` (alternativa)

```php
putenv( 'AIRALO_CLIENT_ID=tu_client_id' );
putenv( 'AIRALO_CLIENT_SECRET=tu_client_secret' );
putenv( 'AIRALO_ENV=production' );
```

Orden de resolución: `$_ENV` → `$_SERVER` → `getenv()` → `get_option('mpa_settings')`.

### 7.3. Permisos

Por defecto usa `manage_woocommerce`. Se puede filtrar:

```php
add_filter( 'mpa_capability', fn() => 'manage_options' );
```

---

## 8. Catálogo de funciones / métodos

> Todos bajo el namespace `Hugo\MiPluginAiralo\`.

### 8.1. `Plugin` (`src/Plugin.php`)

Singleton. Cablea todos los hooks en `plugins_loaded`.

### 8.2. `Env\Config` (`src/Env/Config.php`)

| Método | Descripción |
|---|---|
| `client_id(): string` | Lee `AIRALO_CLIENT_ID` |
| `client_secret(): string` | Lee `AIRALO_CLIENT_SECRET` |
| `env(): string` | `production` \| `sandbox` |
| `is_configured(): bool` | TRUE si hay credenciales |

### 8.3. `Api\Client` (`src/Api/Client.php`)

| Método | Endpoint Airalo | Notas |
|---|---|---|
| `get_balance(): array` | `GET /v2/balance` | Cacheado 5 min |
| `get_compatible_devices( $force ): array` | `GET /v2/compatible-devices` | Cacheado 24 h |
| `get_orders( $params ): array` | `GET /v2/orders` | Paginación, stale-while-revalidate |
| `get_orders_paginated( $page, $per_page ): array` | `GET /v2/orders` | Cacheado con TTL configurable |
| `get_sims_list(): array` | `GET /v2/sims` | Índice de todas las eSIMs |
| `get_sims_paginated( $page, $per_page ): array` | `GET /v2/sims` | Paginado |
| `search_sims_by_iccid( $iccid ): array` | `GET /v2/sims` | Búsqueda por ICCID |
| `get_sim_usage( $iccid ): array` | `GET /v2/sims/{iccid}/usage` | |
| `get_sim_instructions( $iccid, $lang ): array` | `/v2/sims/{iccid}/instructions` | |
| `get_sim_topup_packages( $iccid ): array` | `/v2/sims/{iccid}/topup-packages` | |
| `get_sim_package_history( $iccid ): array` | `/v2/sims/{iccid}/history` | |
| `refund_order( $iccids, $reason, $notes ): array` | `POST /v2/refund` | Rate limit: 1 cada 5 min |
| `topup( $package_id, $iccid, $description ): array` | `POST /v2/orders/topups` | |
| `place_order( $package_id, $qty, $desc ): array` | `POST /v2/orders` | |
| `share_esim( $iccid, $email ): array` | `POST /v2/sims/{iccid}/share` | |
| `assign_esim_user( $iccid, $name, $email ): array` | `POST /v2/sims/{iccid}/share` | Asignar eSIM user |
| `get_country_for_package( $package_id ): string` | — | Mapeo ISO country code |
| `get_package_country_map(): array` | — | Cacheado 24 h |

### 8.4. `Admin\Pages\EsimDetail` (`src/Admin/Pages/EsimDetail.php`)

Página de detalle de eSIM:
- Header con ICCID + pills
- Card de uso con barra coloreada por umbral
- Tabs iOS / Android / Manual SM-DP+ / QR
- Top-ups disponibles con grid
- Sidebar: acciones, datos de la orden, identificadores copiables

### 8.5. `Admin\Ajax\OrderActions` (`src/Admin/Ajax/OrderActions.php`)

| Handler | Acción | Body | Efecto |
|---|---|---|---|
| `handle_sync` | `mpa_sync_order` | `order_id` | Sincroniza desde Airalo |
| `handle_usage_with_lookup` | `mpa_get_usage` | `iccid` | Obtiene uso actual |
| `handle_qr_with_lookup` | `mpa_get_qr` | `iccid` | Obtiene instrucciones/QR |
| `handle_refund_with_lookup` | `mpa_refund_esim` | `iccid, reason` | Solicita refund |
| `handle_topup` | `mpa_topup` | `iccid, package_id` | Top-up de datos |
| `mpa_share_link` | `mpa_share_link` | `iccid` | Link de compartición |
| `mpa_assign_esim_user` | `mpa_assign_esim_user` | `iccid, name, email` | Asignar eSIM user |

### 8.6. `Admin\Ajax\Export` (`src/Admin/Ajax/Export.php`)

| Endpoint | Columnas |
|---|---|
| `mpa_export_orders` | id, code, created_at, package_id, quantity, price, net_price, currency, description, type |
| `mpa_export_esims` | order_code, iccid, created_at, package_id, apn_type, is_roaming |

### 8.7. `Integrations\WooCommerce\OrderLinker`

| Constante | Meta key |
|---|---|
| `META_AIRALO_ORDER_ID` | `_mpa_airalo_order_id` |
| `META_AIRALO_ORDERS` | `_mpa_airalo_orders` |
| `META_AIRALO_USAGE` | `_mpa_airalo_usage` |
| `META_AIRALO_INSTR` | `_mpa_airalo_instructions` |
| `META_AIRALO_TOPUPS` | `_mpa_airalo_topups` |
| `META_AIRALO_REFUND` | `_mpa_airalo_refund` |
| `META_AIRALO_USERS` | `_mpa_airalo_users` |
| `META_PROCESSED_BY` | `_mpa_processed_by` |

### 8.8. `Front\DispositivosShortcode`

Shortcode: `[buscador_dispositivos]` — buscador de dispositivos compatibles en el front.

---

## 9. Endpoints AJAX expuestos

Todos requieren `wp_ajax_*` con `check_ajax_referer( 'mpa_admin' )` + `current_user_can( mpa_capability )`.

| `action` | Body requerido | Body opcional |
|---|---|---|
| `mpa_sync_order` | `order_id` | — |
| `mpa_get_usage` | `iccid` | `order_id` |
| `mpa_get_qr` | `iccid` | `order_id` |
| `mpa_refund_esim` | `iccid`, `reason` | `order_id` |
| `mpa_topup` | `iccid`, `package_id` | `order_id` |
| `mpa_share_link` | `iccid` | — |
| `mpa_assign_esim_user` | `iccid`, `name`, `email` | — |

### Endpoints `admin-post.php`

| `action` | Nonce | Salida |
|---|---|---|
| `mpa_check_connection` | `mpa_check_connection` | redirect con notice |
| `mpa_place_order` | `mpa_place_order` | redirect con notice |
| `mpa_export_orders` | `mpa_export` | CSV download |
| `mpa_export_esims` | `mpa_export` | CSV download |

---

## 10. Webhook listener

URLs:
- `https://tusitio.com/wp-json/mpa/v1/webhook` (REST API)
- `https://tusitio.com/?mpa_webhook=1` (fallback)

Payload ejemplo:

```json
{
  "event": "sim.status.changed",
  "data": {
    "iccid": "893000000000034143",
    "status": "low_data",
    "remaining": 524288,
    "total": 536870912
  }
}
```

Configurar en **Airalo Partners → Webhooks**.

---

## 11. Cron jobs

| Hook | Cuándo | Qué hace |
|---|---|---|
| `mpa_daily_sync` | diario | Refresca balance y devices |

Forzar manualmente: `wp cron event run mpa_daily_sync`

---

## 12. Hooks / filtros para developers

```php
// Cambiar TTLs
add_filter( 'mpa_cache_ttl_balance', fn() => 10 * MINUTE_IN_SECONDS );
add_filter( 'mpa_cache_ttl_devices', fn() => 12 * HOUR_IN_SECONDS );
add_filter( 'mpa_cache_ttl_token',   fn() => 30 * MINUTE_IN_SECONDS );
add_filter( 'mpa_cache_ttl_orders',  fn() => 10 * MINUTE_IN_SECONDS );
add_filter( 'mpa_request_timeout',   fn() => 30 );

// Cambiar capability
add_filter( 'mpa_capability', fn() => 'manage_options' );

// Auto-sync threshold en metabox
add_filter( 'mpa_usage_autosync_threshold', fn() => 5 * MINUTE_IN_SECONDS );

// Eventos
add_action( 'mpa_order_refunded', function ( $wc_order, $reason, $response ) { /* ... */ }, 10, 3 );
add_action( 'mpa_order_topped_up', function ( $wc_order, $iccid, $package_id, $response ) { /* ... */ }, 10, 4 );
add_action( 'mpa_webhook_received', function ( $event, $data, $wc_order ) { /* ... */ }, 10, 3 );
add_action( 'mpa_metabox_after_actions', function ( $wc_order, $iccid ) { /* ... */ }, 10, 2 );
```

---

## 13. Migración desde el plugin legacy

1. Sube la nueva versión del plugin (sobre-escribe, conservando `.env`).
2. Activa "Mi Plugin Airalo" desde *Plugins*.
3. Verifica la conexión en **Airalo → Ajustes → Probar conexión**.
4. Desactiva "Dispositivos API" (plugin legacy).

El shortcode `[buscador_dispositivos]` lo sirve ahora el plugin nuevo.

---

## 14. Changelog

Ver [`changelog.txt`](changelog.txt) para el historial completo de versiones.

---

## 15. Roadmap

| Prioridad | Feature | Estado |
|---|---|---|
| 🔴 Alta | Metabox en pedidos WC con ICCID, uso, refund, QR, top-up | ✅ |
| 🔴 Alta | Página de detalle de eSIM con QR + tabs + top-ups | ✅ |
| 🔴 Alta | Auto-sync de uso en metabox al render | ✅ |
| 🟡 Media | Reconciliación WC ↔ Airalo | ✅ |
| 🟡 Media | Place order manual | ✅ |
| 🟡 Media | Export CSV de órdenes/eSIMs | ✅ |
| 🟡 Media | Cron diario | ✅ |
| 🟡 Media | Webhook listener | ✅ |
| 🟡 Media | Logs visibles en admin | ⏳ (solo `error_log`) |
| 🟢 Baja | Bulk resync de pedidos huérfanos | ⏳ |
| 🟢 Baja | Auto-associar por `description` al recibir webhook | ⏳ |
| 🟢 Baja | Indicador de uso bajo con acción "Notificar cliente" | ⏳ |

---

## 16. Licencia

**[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)** — Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International.

Puedes:
- **Compartir** — copiar y redistribuir el material en cualquier medio o formato.
- **Adaptar** — remezclar, transformar y crear a partir del material.

Bajo las siguientes condiciones:
- **Atribución** — Debes dar crédito de manera adecuada, proporcionando un enlace a la licencia y indicando si se realizaron cambios.
- **NoComercial** — No puedes utilizar el material para fines comerciales.
- **CompartirIgual** — Si remezclas, transformas o creas a partir del material, debes distribuir tus contribuciones bajo la misma licencia.

```
© 2024-2026 SUOP Mobile SL — Hugo Pérez-Vigo
```
