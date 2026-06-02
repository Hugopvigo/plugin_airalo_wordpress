# Mi Plugin Airalo — Documentación técnica

> Plugin de backoffice para WordPress + WooCommerce que integra la API de Airalo.
> Autor: **SUOP Mobile SL — Hugo Pérez-Vigo ([@hugopvigo](https://twitter.com/hugopvigo))** — <https://www.suop.es/>
>
> Versión del plugin: `2.0.0`
> Namespace PSR-4: `Hugo\MiPluginAiralo\`
> Text domain: `mi-plugin-airalo`
> Slug del plugin: `mi-plugin-airalo`

---

## Índice

1. [¿Qué es?](#1-qué-es)
2. [Capturas / UX](#2-capturas--ux)
3. [Arquitectura](#3-arquitectura)
4. [Modelo de seguridad](#4-modelo-de-seguridad)
5. [Modelo de caché](#5-modelo-de-caché)
6. [Instalación detallada](#6-instalación-detallada)
7. [Configuración](#7-configuración)
8. [Catálogo de funciones / métodos](#8-catálogo-de-funciones--métodos)
9. [Endpoints AJAX expuestos](#9-endpoints-ajax-expuestos)
10. [Webhook listener](#10-webhook-listener)
11. [Cron jobs](#11-cron-jobs)
12. [Hooks / filtros para developers](#12-hooks--filtros-para-developers)
13. [Migración desde el plugin legacy](#13-migración-desde-el-plugin-legacy)
14. [Roadmap](#14-roadmap)
15. [Auditoría de seguridad — checklist](#15-auditoría-de-seguridad--checklist)
16. [Glosario](#16-glosario)

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

Aplican tanto al metabox como a la card grande de la página de detalle (vía clases
`mpa-usage--warning` / `mpa-usage--critical` y el modificador `mpa-card--usage-{state}`).

---

## 3. Arquitectura

```
mi_plugin_airalo/
├── mi-plugin-airalo.php          ← bootstrap (header WP, define, require, hook boot)
├── dispositivos-api.php          ← LEGACY: plugin anterior, conservado tal cual
├── uninstall.php                 ← limpieza en desinstalación
├── readme.txt                    ← readme estilo WordPress.org
├── composer.json / composer.lock ← airalo/sdk + vlucas/phpdotenv
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
    │   ├── Client.php            ← Wrapper sobre airalo/sdk
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

### Subpáginas ocultas del menú

Algunas subpáginas se registran pero no se muestran en la nav (se accede vía enlace
directo). `Menu::hide_hidden_submenus()` las quita del submenu tras el registro:

- `mpa-esim-detail` — se accede haciendo click en un chip de ICCID desde eSIMs.

Las otras (`mpa-place-order`, `mpa-reconciliation`) sí son visibles.

---

## 4. Modelo de seguridad

| Capa | Implementación |
|---|---|
| Acceso a archivos | `defined( 'ABSPATH' ) \|\| exit;` en todos los archivos PHP |
| Plugins / temas | Cabecera WP estándar + `register_activation_hook` + `register_deactivation_hook` |
| Capacidades | `manage_woocommerce` si WC activo, si no `manage_options` |
| AJAX | `check_ajax_referer( 'mpa_admin', 'nonce' )` + `current_user_can( cap )` en cada acción |
| `admin_post_*` | `check_admin_referer()` + `current_user_can( cap )` en cada handler |
| Sanitización | `sanitize_text_field`, `absint`, `sanitize_key`, `wp_unslash` en inputs |
| Escape de salida | `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, `wp_json_encode` |
| Credenciales | `.env` + `getenv()` + `$_ENV` + `wp_options['mpa_settings']` fallback. No se exponen a JS |
| HTTPS | `MPA_API_BASE = https://partners-api.airalo.com/v2` (sin override) |
| Timeouts | `wp_remote_*` con `timeout = MPA_FILTER_REQUEST_TIMEOUT` (15s por defecto) |
| Logs | `error_log` con redacción automática de claves sensibles (`client_secret`, `token`, `password`, `authorization`) |
| Privacidad | Solo admins con la capability ven el panel; WC y backoffice usan `current_user_can` |
| Uninstall | `uninstall.php` purga opciones, transients `mpa_*` y post meta `_mpa_airalo_*` y `_airalo_*` |
| Carga de assets | Solo en pantallas del plugin, `shop_order`, `wc-orders` y `index.php` |
| SQL | `$wpdb->prepare()` + `esc_like` en `uninstall.php`; cero SQL en runtime |
| REST | Endpoint `/mpa/v1/webhook` con `permission_callback => __return_true` (Airalo no firma), pero el handler valida que la `iccid` exista en WC |

### Order de capability

1. `mpa_capability` filtro (override)
2. `manage_woocommerce` (si WC activo)
3. `manage_options` (fallback)

---

## 5. Modelo de caché

| Dato | Clave | TTL | Filtro |
|---|---|---|---|
| Token OAuth | `transient('mpa_airalo_token')` | `expires_in - 1h` | `mpa_cache_ttl_token` |
| Balance / exchange rates | `transient('mpa_airalo_balance')` | 5 min | `mpa_cache_ttl_balance` |
| Dispositivos compatibles | `transient('mpa_airalo_devices')` | 24 h | `mpa_cache_ttl_devices` |
| Lista simple de paquetes (Place order) | `transient('mpa_airalo_packages_simple')` | 1 h | — |
| Datos por pedido | `update_post_meta` (persistente) | infinito | — |
| Índice de ICCID → WC order | `wp_cache` (memoria de la request) | 5 min | — |

Las llamadas AJAX invalidan la caché del transient solo si la API responde correctamente;
los datos por pedido persisten en meta para auditoría. `mpa_daily_sync` refresca
`balance` y `devices` cada 24h.

---

## 6. Instalación detallada

### 6.1. Requisitos

| Componente | Versión mínima |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 (recomendado 8.0+, el SDK requiere `>=7.4`) |
| Composer | 2.x (solo en deploy, no en el WP) |
| Extensión PHP `sodium` | habilitada (viene por defecto) |
| Extensión PHP `curl` | habilitada (viene por defecto) |
| WooCommerce | activo y configurado |
| Permisos WP | `manage_woocommerce` (shop_manager y admin) |

### 6.2. Paso a paso en local (desarrollo)

```bash
# 1. Clonar o copiar el plugin en wp-content/plugins/
cp -r mi_plugin_airalo /path/to/wp/wp-content/plugins/

cd /path/to/wp/wp-content/plugins/mi_plugin_airalo

# 2. Instalar dependencias PHP
composer install --no-dev --optimize-autoloader

# 3. Configurar credenciales
cat > .env <<'EOF'
AIRALO_CLIENT_ID=tu_client_id_de_airalo
AIRALO_CLIENT_SECRET=tu_client_secret_de_airalo
AIRALO_ENV=production
EOF
chmod 600 .env

# 4. Asegurar que .env no se commitea
cat >> .gitignore <<'EOF'
.env
/vendor/
EOF
```

### 6.3. Paso a paso en producción (servidor)

1. **Subir el plugin.** Sube la carpeta `mi_plugin_airalo/` por SFTP/SCP a
   `wp-content/plugins/`. Si Composer no está disponible en el servidor, sube también
   la carpeta `vendor/` desde tu local (ya construida con `composer install`).

   ```bash
   # Desde tu local, copia al server:
   rsync -avz --delete \
     --exclude='.env' --exclude='.git/' --exclude='node_modules/' \
     ./mi_plugin_airalo/ usuario@servidor:/var/www/html/wp-content/plugins/mi_plugin_airalo/
   ```

2. **Permisos.** El webserver debe poder leer todos los archivos. `wp-content/plugins/mi_plugin_airalo/.env` debe tener `chmod 600` y ser del owner del webserver (no del grupo world).

   ```bash
   ssh usuario@servidor
   cd /var/www/html/wp-content/plugins/mi_plugin_airalo
   chown -R www-data:www-data .
   chmod 600 .env
   ```

3. **Activar.** WP-Admin → *Plugins* → "Mi Plugin Airalo" → *Activar*.

4. **Verificar conexión.** WP-Admin → *Airalo* → *Ajustes* → "Probar conexión".
   Debe decir "Conexión correcta" y mostrar el balance en USD.

5. **(Opcional) Si subes `.env` aparte del deploy:**

   ```bash
   # Crea .env en el servidor con las credenciales reales
   ssh usuario@servidor
   nano /var/www/html/wp-content/plugins/mi_plugin_airalo/.env
   # AIRALO_CLIENT_ID=...
   # AIRALO_CLIENT_SECRET=...
   # AIRALO_ENV=production
   chmod 600 /var/www/html/wp-content/plugins/mi_plugin_airalo/.env
   ```

### 6.4. Verificación post-instalación

Checklist tras activar:

- [ ] WP-Admin → *Airalo* aparece en el sidebar con icono de smartphone.
- [ ] *Airalo → Ajustes* dice "Configuradas" y entorno correcto.
- [ ] "Probar conexión" devuelve "Conexión correcta" con un balance.
- [ ] WP Dashboard muestra widget "Airalo" con balance y últimas órdenes.
- [ ] En un pedido WC de eSIM aparece el metabox lateral "Airalo" con la información.
- [ ] El shortcode `[buscador_dispositivos]` sigue funcionando en el front.

### 6.5. Desinstalación

Al desinstalar (NO desactivar, **desinstalar**), `uninstall.php` limpia:

- Opciones: `mpa_settings`, `mpa_airalo_token`, `mpa_airalo_balance`, `mpa_airalo_devices`.
- Transients: `mpa_airalo_*`.
- Post meta: `_mpa_airalo_*` y `_airalo_*` (cubre tanto nuestras claves como las del plugin WC de Airalo).
- User meta: `mpa_*`.
- Cron: `mpa_daily_sync`.

Para conservar datos y solo desactivar: usa *Desactivar* (no *Desinstalar*).

### 6.6. Troubleshooting

| Síntoma | Causa probable | Solución |
|---|---|---|
| "Credenciales no configuradas" en todo | `.env` no se carga o falta | Verifica que `Dotenv` esté en `vendor/` y que `.env` esté en la raíz del plugin |
| 401 al probar conexión | Client ID / Secret mal | Re-genera en partners.airalo.com y actualiza `.env` |
| 429 constante | Rate limit | Espera 1 min; el plugin cachea para reducir llamadas |
| Metabox WC no aparece | WC no activo o rol sin `manage_woocommerce` | Activa WC o cambia de rol |
| Webhook no llega | URL no accesible | Usa la URL REST `/wp-json/mpa/v1/webhook` y verifica en Airalo Partners |
| Permisos insuficientes en admin | Capability incorrecta | `add_filter( 'mpa_capability', fn() => 'manage_options' );` |

---

## 7. Configuración

### 7.1. `.env`

```ini
AIRALO_CLIENT_ID=tu_client_id
AIRALO_CLIENT_SECRET=tu_client_secret
AIRALO_ENV=production   # o "sandbox"
```

`AIRALO_ENV` activa el modo sandbox del SDK (`Airalo::$env = 'sandbox'`). El plugin NO
mezcla entornos: con `.env` se decide uno y se usa en todo.

### 7.2. `wp-config.php` (alternativa)

```php
putenv( 'AIRALO_CLIENT_ID=tu_client_id' );
putenv( 'AIRALO_CLIENT_SECRET=tu_client_secret' );
putenv( 'AIRALO_ENV=production' );
```

### 7.3. `wp_options['mpa_settings']` (fallback)

Si no hay `.env` ni variables de entorno, el plugin busca en la opción `mpa_settings`
con el mismo array de claves.

Orden de resolución: `$_ENV` → `$_SERVER` → `getenv()` → `get_option('mpa_settings')`.

### 7.4. Permisos

Por defecto usa la capability `manage_woocommerce` (rol *shop_manager* y *administrator*
la tienen). Si WC no está activo, cae a `manage_options` (solo administradores). Se puede
filtrar:

```php
add_filter( 'mpa_capability', fn() => 'manage_options' );
```

---

## 8. Catálogo de funciones / métodos

> Todos bajo el namespace `Hugo\MiPluginAiralo\`.

### 8.1. `Plugin` (`src/Plugin.php`)

| Método | Visibilidad | Descripción |
|---|---|---|
| `instance(): Plugin` | `public static` | Singleton |
| `boot(): void` | `public` | Llamado en `plugins_loaded`. Conecta todos los hooks |
| `is_woocommerce_active(): bool` | `public` | ¿WC cargado? |
| `capability(): string` | `public` | Capability efectiva del plugin |
| `on_activate(): void` | `public static` | Programa cron `mpa_daily_sync` y flushea rewrites |
| `on_deactivate(): void` | `public static` | Cancela cron y flushea rewrites |

Propiedades públicas (lazy): `api`, `config`, `logger`, `assets`, `menu`, `dashboard_widget`,
`page_dashboard`, `page_orders`, `page_esims`, `page_esim_detail`, `page_settings`,
`page_place_order`, `page_reconciliation`, `ajax`, `ajax_export`, `cron_daily`, `webhook`,
`order_linker`, `order_meta_box`, `front`.

### 8.2. `Env\Config` (`src/Env/Config.php`)

| Método | Descripción |
|---|---|
| `client_id(): string` | Lee `AIRALO_CLIENT_ID` |
| `client_secret(): string` | Lee `AIRALO_CLIENT_SECRET` |
| `env(): string` | `production` \| `sandbox` (default `production`) |
| `is_configured(): bool` | TRUE si hay client_id y client_secret |
| `cache_ttl_balance(): int` | Filtro `mpa_cache_ttl_balance` (5 min) |
| `cache_ttl_devices(): int` | Filtro `mpa_cache_ttl_devices` (24 h) |
| `cache_ttl_token(): int` | Filtro `mpa_cache_ttl_token` (1 h) |
| `request_timeout(): int` | Filtro `mpa_request_timeout` (15 s) |
| `get( $key ): mixed` | Resuelve el origen del valor (privado) |

### 8.3. `Support\Logger` (`src/Support/Logger.php`)

| Método | Descripción |
|---|---|
| `info( $msg, $ctx = [] )` | Log nivel INFO |
| `warning( $msg, $ctx = [] )` | Log nivel WARNING |
| `error( $msg, $ctx = [] )` | Log nivel ERROR |
| `sanitize_context( $ctx )` | Redacta claves sensibles y trunca strings (privado) |

Salida: `error_log( '[MPA][LEVEL] message {"ctx":"..."}' )`. Claves redactadas:
`AIRALO_CLIENT_SECRET`, `client_secret`, `authorization`, `password`, `token`.

### 8.4. `Support\Assets` (`src/Support/Assets.php`)

| Método | Descripción |
|---|---|
| `register(): void` | Engancha `admin_enqueue_scripts` y `wp_enqueue_scripts` |
| `enqueue_admin( $hook )` | Carga CSS+JS en `mpa-*`, `admin_page_mpa-esim-detail`, `shop_order`, `wc-orders`, `index.php` |
| `enqueue_front()` | Carga CSS+JS solo si el post actual tiene el shortcode |

Constantes: `HANDLE_ADMIN_CSS`, `HANDLE_ADMIN_JS`, `HANDLE_FRONT_CSS`, `HANDLE_FRONT_JS`.
Localize: `MPA.ajaxUrl`, `MPA.nonce`, `MPA.i18n.{loading,error,confirmRefund,confirmTopup,copied}`.

### 8.5. `Api\Exception` (`src/Api/Exception.php`)

| Método | Descripción |
|---|---|
| `__construct( $msg, $status, $payload, $previous )` | Excepción tipada con código HTTP y payload |
| `status_code(): int` | Código HTTP de la respuesta |
| `payload(): array` | Cuerpo de la respuesta de Airalo |
| `is_auth_error(): bool` | 401 \| 403 |
| `is_rate_limited(): bool` | 429 |

### 8.6. `Api\Client` (`src/Api/Client.php`)

| Método | Endpoint Airalo | Notas |
|---|---|---|
| `is_configured(): bool` | — | Delega a `Config` |
| `get_balance(): array` | `GET /v2/exchange-rates` | Devuelve `data.balance`; cacheado 5 min |
| `get_compatible_devices( $force = false ): array` | `GET /v2/compatible-devices` | Cacheado 24 h |
| `get_orders( $params ): array` | `GET /v2/orders` | Paginación, filtros |
| `get_sim_usage( $iccid ): array` | `GET /v2/sims/{iccid}/usage` | SDK `simUsage` |
| `get_sim_usage_bulk( $iccids ): array` | bulk | SDK `simUsageBulk` |
| `get_sim_instructions( $iccid, $lang='en' ): array` | `/v2/sims/{iccid}/instructions` | SDK `getSimInstructions` |
| `get_sim_topup_packages( $iccid ): array` | `/v2/sims/{iccid}/topup-packages` | SDK `getSimTopups` |
| `get_sim_package_history( $iccid ): array` | `/v2/sims/{iccid}/history` | SDK `getSimPackageHistory` |
| `refund_order( array $iccids, string $reason, string $notes = '' ): array` | `POST /v2/refund` | Body: `{ iccids: [...], reason, notes }` en `text/plain`. Rate limit: 1 cada 5 min por IP. |
| `topup( $package_id, $iccid, $description=null ): array` | `POST /v2/orders/topups` | SDK `topup` |

### 8.7. `Admin\Menu` (`src/Admin/Menu.php`)

| Método | Descripción |
|---|---|
| `register(): void` | Engancha `admin_menu`, `admin_head`, `admin_footer_text` |
| `register_menu(): void` | Crea el menú top-level y 7 subpáginas (una oculta: `mpa-esim-detail`) |
| `hide_hidden_submenus(): void` | Quita del sidebar las subpáginas "internas" |
| `maybe_admin_footer( $text ): string` | Añade crédito del autor en páginas del plugin |

### 8.8. `Admin\DashboardWidget` (`src/Admin/DashboardWidget.php`)

Pinta balance + últimas 5 órdenes + crédito del autor en `wp-admin/index.php`.

### 8.9. `Admin\Pages\Dashboard` (`src/Admin/Pages/Dashboard.php`)

3 cards (balance, entorno, últimas 24h) + tabla de últimas 10 órdenes.

### 8.10. `Admin\Pages\Orders` (`src/Admin/Pages/Orders.php`)

Listado paginado de órdenes Airalo con botones "Anterior / Siguiente".

### 8.11. `Admin\Pages\Esims` (`src/Admin/Pages/Esims.php`)

Listado de eSIMs con buscador por código/descripción. Cada ICCID es un chip clicable
que enlaza a la página de detalle.

### 8.12. `Admin\Pages\EsimDetail` (`src/Admin/Pages/EsimDetail.php`) ⭐

Página de detalle de una eSIM concreta. Estructura:

- **Header**: título del paquete + ICCID + pills (código Airalo, fecha).
- **Card de consumo**: barra coloreada por umbral + leyenda "usado / restante / total" + "Actualizado X".
- **Card de instalación**: tabs `iOS | Android | Manual SM-DP+ | QR`. Cada tab con steps numerados o QR/SM-DP+ copiables.
- **Top-ups disponibles**: grid de cards con precio, datos, días y botón "Comprar top-up".
- **Sidebar**:
  - Acciones: Refrescar uso, Solicitar refund.
  - Datos de la orden: paquete, tipo, cantidad, precio, APN, roaming.
  - Identificadores copiables: ICCID, matching_id.

| Método | Descripción |
|---|---|
| `render(): void` | Despacha desde `mpa-esim-detail&iccid=...` |
| `load_context( $iccid ): array` | Carga orden, sim, uso, instrucciones, top-ups |
| `render_page( $iccid, $ctx, $notices )` | Pinta la UI completa |
| `render_steps( $steps )` | Steps numerados con icono |

### 8.13. `Admin\Pages\Settings` (`src/Admin/Pages/Settings.php`)

Estado de la conexión + botón "Probar conexión".

### 8.14. `Admin\Pages\PlaceOrder` (`src/Admin/Pages/PlaceOrder.php`)

Form con `<select>` de paquetes SIM + qty (1-50) + descripción. Ejecuta `Airalo::order()`.

| Método | Descripción |
|---|---|
| `render(): void` | Pinta el form |
| `handle(): void` | Procesa el POST (nonce + cap + sanitize) |
| `store_notice( $type, $message )` | Transient con feedback |
| `render_result()` | Muestra la última orden y los notices |
| `get_packages(): array` | Cachea lista simple de paquetes SIM (1h) |

### 8.15. `Admin\Pages\Reconciliation` (`src/Admin/Pages/Reconciliation.php`)

Dos tablas:
- **WC sin Airalo**: pedidos WC con producto eSIM (heurística: nombre/categoría contiene "esim")
  sin meta `_mpa_airalo_order_id`.
- **Airalo sin WC**: órdenes Airalo con `description` que no matchean un pedido WC existente
  (regex `WC#(\d+)`).

| Método | Descripción |
|---|---|
| `find_wc_orphans( $limit )` | Escanea últimos 50 pedidos WC |
| `find_airalo_orphans()` | Escanea últimas 100 órdenes Airalo |
| `render_wc_orphans( $orders )` | Tabla con enlace al pedido |
| `render_airalo_orphans( $orders )` | Tabla con código y descripción |

### 8.16. `Admin\Ajax\OrderActions` (`src/Admin/Ajax/OrderActions.php`)

Dispatcher único. Acciones registradas: `mpa_sync_order`, `mpa_get_usage`, `mpa_get_qr`,
`mpa_refund_esim`, `mpa_topup`, `mpa_share_link`.

| Handler | Acción | Body | Efecto |
|---|---|---|---|
| `dispatch()` | — | `mpa_action, order_id, iccid, ...` | Verifica nonce + cap + existencia del pedido |
| `handle_sync( $wc_order )` | `mpa_sync_order` | — | `OrderLinker::sync_order()` |
| `handle_usage_with_lookup( $wc_order, $iccid )` | `mpa_get_usage` | `iccid` | `get_sim_usage()` + `store_usage()` |
| `handle_qr_with_lookup( $wc_order, $iccid )` | `mpa_get_qr` | `iccid` | `get_sim_instructions()` + `store_instructions()` |
| `handle_refund_with_lookup( $wc_order, $iccid, $reason )` | `mpa_refund_esim` | `reason` | `refund_order()` + `mark_refunded()` + `add_order_note()` |
| `handle_topup( $wc_order, $iccid, $pkg )` | `mpa_topup` | `package_id` | `topup()` + `store_topup()` |
| `handle_topup_by_iccid( $iccid, $pkg )` | `mpa_topup` (sin order_id) | `iccid, package_id` | Busca el WC order por ICCID y delega |
| `find_wc_order_by_iccid( $iccid )` | interno | — | Escanea últimos 50 pedidos WC y devuelve el que contiene la ICCID |

**Importante**: Las acciones basadas en ICCID funcionan SIN `order_id`: buscan el pedido
WC correspondiente. Esto permite invocarlas desde la página de detalle o desde el
metabox indistintamente.

### 8.17. `Admin\Ajax\Export` (`src/Admin/Ajax/Export.php`)

CSV streaming con BOM UTF-8.

| Endpoint | Handler | Columnas |
|---|---|---|
| `admin-post.php?action=mpa_export_orders` | `export_orders()` | id, code, created_at, package_id, quantity, price, net_price, currency, description, type |
| `admin-post.php?action=mpa_export_esims` | `export_esims()` | order_code, iccid, created_at, package_id, apn_type, is_roaming |

Paginación de hasta 20 páginas (2000 órdenes). Cache deshabilitada (exporta en vivo).

### 8.18. `Cron\Daily` (`src/Cron/Daily.php`)

Hook `mpa_daily_sync` (registrado al activar). Refresca balance y devices, guarda
`mpa_last_sync` option con timestamp y estado.

### 8.19. `Webhooks\AiraloListener` (`src/Webhooks/AiraloListener.php`)

| Endpoint | Notas |
|---|---|
| `POST /wp-json/mpa/v1/webhook` | REST API estándar |
| `POST ?mpa_webhook=1` | Fallback query var (para servers que bloquean REST) |

Comportamiento:
- Decodifica JSON, extrae `event` y `data.iccid`.
- Busca el pedido WC por ICCID.
- Añade una `order_note` con el evento y el payload.
- Dispara `do_action( 'mpa_webhook_received', $event, $data, $order )`.

Constantes: `QUERY_VAR = 'mpa_webhook'`, `REST_NS = 'mpa/v1'`, `REST_ROUTE = '/webhook'`.

### 8.20. `Integrations\WooCommerce\OrderLinker` (`src/Integrations/WooCommerce/OrderLinker.php`)

| Constante | Meta key |
|---|---|
| `META_AIRALO_ORDER_ID` | `_mpa_airalo_order_id` |
| `META_AIRALO_ORDERS` | `_mpa_airalo_orders` |
| `META_AIRALO_USAGE` | `_mpa_airalo_usage` |
| `META_AIRALO_INSTR` | `_mpa_airalo_instructions` |
| `META_AIRALO_TOPUPS` | `_mpa_airalo_topups` |
| `META_AIRALO_REFUND` | `_mpa_airalo_refund` |

| Método | Descripción |
|---|---|
| `get_airalo_order_id( $order ): ?string` | Detecta meta legacy y canónica |
| `get_airalo_orders( $order ): array` | Lista raw de Airalo |
| `get_usage / get_instructions / get_topups / get_refund` | Lectores |
| `store_usage / store_instructions / store_topup / mark_refunded` | Escritores |
| `sync_order( $order, $api )` | Búsqueda best-effort por `description = "WC#N"` |

Meta legacy reconocida: `_airalo_order_id`, `airalo_order_id`, `_airalo_order_code`,
`airalo_order_code`, `_airalo_order`, `_airalo_order_data`.

### 8.21. `Integrations\WooCommerce\OrderMetaBox` (`src/Integrations/WooCommerce/OrderMetaBox.php`)

| Método | Descripción |
|---|---|
| `register(): void` | `add_meta_boxes` |
| `add_meta_box(): void` | Registra en todos los WC order types |
| `render( $post ): void` | Pinta el panel completo |
| `maybe_auto_sync_usage( $order, $sims )` | Refresca uso si lleva >30 min sin actualizar (filtro `mpa_usage_autosync_threshold`) |

UI: header con Airalo Order ID, botón "Sincronizar desde Airalo", por cada ICCID: pill de
% usado coloreada, chip "Detalle →", barra de uso con threshold, "Actualizado X",
botones Uso/QR/Top-up/Refund, y `<details>` con JSON de instrucciones.

### 8.22. `Front\DispositivosShortcode` (`src/Front/DispositivosShortcode.php`)

| Método | Descripción |
|---|---|
| `register(): void` | `add_shortcode( 'buscador_dispositivos', [ $this, 'render' ] )` |
| `render( $atts ): string` | HTML + JS inline, con datos cacheados por transient |

Shortcode: `[buscador_dispositivos]`.

---

## 9. Endpoints AJAX expuestos

Todos requieren `wp_ajax_*` con `check_ajax_referer( 'mpa_admin' )` + `current_user_can( mpa_capability )`.

| `action` | Body requerido | Body opcional | Respuesta |
|---|---|---|---|
| `mpa_sync_order` | `order_id` | — | `{ success, data: { message } }` |
| `mpa_get_usage` | `iccid` | `order_id` | `{ success, data: { data: <usage>, message } }` |
| `mpa_get_qr` | `iccid` | `order_id` | `{ success, data: { data: <instructions>, message } }` |
| `mpa_refund_esim` | `iccid`, `reason` | `order_id` | `{ success, data: { data, message } }` |
| `mpa_topup` | `iccid`, `package_id` | `order_id` | `{ success, data: { data, message } }` |
| `mpa_share_link` | `iccid` | — | `{ success, data: { data: { iccid } } }` |

### Endpoints `admin-post.php`

| `action` | Nonce | Body | Salida |
|---|---|---|---|
| `mpa_check_connection` | `mpa_check_connection` | — | redirect a `mpa-settings` con notice |
| `mpa_place_order` | `mpa_place_order` | `package_id, quantity, description` | redirect a `mpa-place-order` con notice |
| `mpa_export_orders` | `mpa_export` | — | CSV download |
| `mpa_export_esims` | `mpa_export` | — | CSV download |

---

## 10. Webhook listener

URLs aceptadas:

- `https://tusitio.com/wp-json/mpa/v1/webhook` (REST API)
- `https://tusitio.com/?mpa_webhook=1` (fallback)

Headers esperados: `Content-Type: application/json`.

Payload esperado (ejemplo):

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

Comportamiento:
1. Decodifica y sanitiza.
2. Busca el WC order cuya meta `_mpa_airalo_orders` contenga esa ICCID.
3. Añade una `order_note` con `[Airalo webhook] {event} {json}`.
4. Dispara `do_action( 'mpa_webhook_received', $event, $data, $order )`.

Configurar la URL en **Airalo Partners → Webhooks** apuntando a la URL REST.

---

## 11. Cron jobs

| Hook | Cuándo | Qué hace |
|---|---|---|
| `mpa_daily_sync` | diario (al activar + cada 24h) | Refresca balance y devices, guarda `mpa_last_sync` option |

Para forzar manualmente: `wp cron event run mpa_daily_sync` desde WP-CLI.

---

## 12. Hooks / filtros para developers

```php
// Cambiar TTLs
add_filter( 'mpa_cache_ttl_balance', fn() => 10 * MINUTE_IN_SECONDS );
add_filter( 'mpa_cache_ttl_devices', fn() => 12 * HOUR_IN_SECONDS );
add_filter( 'mpa_cache_ttl_token',   fn() => 30 * MINUTE_IN_SECONDS );
add_filter( 'mpa_request_timeout',   fn() => 30 );

// Cambiar capability
add_filter( 'mpa_capability', fn() => 'manage_options' );

// Cambiar umbral de auto-sync de uso en metabox
add_filter( 'mpa_usage_autosync_threshold', fn() => 5 * MINUTE_IN_SECONDS );

// Reaccionar a un refund
add_action( 'mpa_order_refunded', function ( $wc_order, $reason, $response ) {
    // ...
}, 10, 3 );

// Reaccionar a un top-up
add_action( 'mpa_order_topped_up', function ( $wc_order, $iccid, $package_id, $response ) {
    // ...
}, 10, 4 );

// Reaccionar a un webhook de Airalo
add_action( 'mpa_webhook_received', function ( $event, $data, $wc_order ) {
    if ( 'low_data' === $event ) {
        // enviar email al cliente, etc.
    }
}, 10, 3 );

// Mutate el metabox (legacy - antes era mpa_metabox_after_actions)
add_action( 'mpa_metabox_after_actions', function ( $wc_order, $iccid ) {
    echo '<a href="..." class="button">My custom action</a>';
}, 10, 2 );
```

---

## 13. Migración desde el plugin legacy

`dispositivos-api.php` se conserva intacto. Pasos para migrar a la nueva versión:

1. Sube la nueva versión del plugin (sobre-escribe `mi_plugin_airalo/`, conservando `.env`).
2. Activa "Mi Plugin Airalo" desde *Plugins*.
3. Verifica la conexión en **Airalo → Ajustes → Probar conexión**.
4. Desactiva "Dispositivos API" (plugin legacy).
5. El shortcode `[buscador_dispositivos]` lo sirve ahora el plugin nuevo, sin pérdida de servicio.

---

## 14. Roadmap

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

### Motivos de refund predefinidos (API Airalo)

| Valor | Texto en UI | Uso recomendado |
|---|---|---|
| `SERVICE_ISSUES` | Problemas de servicio | La red no funcionaba |
| `INVALID_ACTIVATION` | La eSIM no se activó | El cliente no pudo activar |
| `DUPLICATE_ORDER` | Pedido duplicado | El cliente compró dos |
| `OTHERS` | Otros motivos | Requiere explicación en `notes` |

---

## 15. Auditoría de seguridad — checklist

- [x] Cabecera `ABSPATH` en todos los archivos PHP.
- [x] Cabecera `WP_UNINSTALL_PLUGIN` en `uninstall.php`.
- [x] Cabecera `defined( 'ABSPATH' ) || exit;` en cada clase.
- [x] Capability checks en cada handler admin y AJAX.
- [x] Nonce en cada endpoint AJAX y en los handlers `admin_post_*`.
- [x] Sanitización de inputs (`absint`, `sanitize_text_field`, `sanitize_key`, `wp_unslash`).
- [x] Escape de salida (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, `wp_json_encode`).
- [x] Timeouts en todas las llamadas `wp_remote_*` (15 s por defecto, filtrable).
- [x] HTTPS forzado en `MPA_API_BASE`.
- [x] Logger con redacción automática de secretos.
- [x] Credenciales fuera del código (`.env` / `wp-config.php` / `wp_options`).
- [x] Cero SQL dinámico sin `prepare` + `esc_like`.
- [x] `uninstall.php` limpia opciones, transients y meta.
- [x] `composer.lock` commiteado para builds reproducibles.
- [x] `airalo/sdk` con auto-renovación de token y rate limit.
- [x] Sin `eval`, sin `file_get_contents` sobre URLs (excepto el webhook, que valida), sin `extract` sobre user input.

---

## 16. Glosario

- **ICCID**: Identificador único de la eSIM (19-20 dígitos).
- **SM-DP+**: Servidor de provisioning remoto que entrega el perfil al dispositivo.
- **LPA**: Local Profile Assistant, el componente de Android/iOS que instala el perfil.
- **APN**: Access Point Name, configuración de red del operador.
- **Top-up**: Recarga de datos sobre una eSIM ya instalada (no es un nuevo ICCID).
- **Refund**: Devolución del importe de una eSIM, cancela la misma.
- **eSIM Cloud**: Servicio de Airalo para entregar eSIMs vía link/email sin tocar tu backend.
- **Package ID**: Identificador de un paquete concreto (ej. `meraki-mobile-7days-1gb`).
- **Order code**: Código humano de Airalo para una orden (ej. `20241018-124189`).
- **Net price vs price**: `price` es PVP; `net_price` es lo que se te cobra (post-descuento).


---

## Changelog

### v2.0.1 — Correcciones de funcionalidad y seguridad

- 🔴 **Balance** — Ahora llama a `GET /v2/balance` en vez de `getExchangeRates()`. El dashboard y widget muestran el saldo real.
- 🔴 **Refund** — Endpoint corregido a `POST /v2/refund` con body `{ iccids, reason, notes }` y `Content-Type: text/plain`. Valida reasons predefinidos.
- 🟡 **Assets** — Screen IDs corregidos: ahora CSS/JS cargan en todas las páginas del plugin.
- 🟡 **Reconciliation** — `exit()` eliminado; ya no trunca la página.
- 🟡 **Refund UI** — Los botones de refund tienen un `<select>` HTML con los 4 motivos predefinidos de la API + `<textarea>` para notas. Sin `window.prompt()`.
- 🟢 **Top-up detail** — Los botones de top-up ya no usan un `data-order` inexistente; buscan el pedido WC automáticamente por ICCID.

---

*Documento mantenido por SUOP Mobile SL — Hugo Pérez-Vigo ([@hugopvigo](https://twitter.com/hugopvigo)).*
