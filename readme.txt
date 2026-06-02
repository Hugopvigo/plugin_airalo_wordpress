=== Mi Plugin Airalo ===
Contributors: hugopvigo, suop, hugo
Tags: airalo, esim, woocommerce, backoffice, refills
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.3.0
License: Proprietary
License URI: https://www.suop.es/

Panel de backoffice para Airalo Partner API: vincula pedidos de WooCommerce, gestiona refunds, eSIMs/QR, top-ups y consumo de datos.

Autor: SUOP Mobile SL — Hugo Pérez-Vigo (@hugopvigo) — https://www.suop.es/

== Description ==

Plugin interno para gestionar la integración con Airalo Partner API desde WordPress + WooCommerce.

* Metabox en la página de pedido de WooCommerce con la información de Airalo (orden, ICCIDs, uso, estado)
* Refunds solicitados desde el backoffice
* Descarga de QR e instrucciones de instalación
* Top-ups sobre eSIMs activas
* Listado de órdenes, listado de eSIMs con barras de uso
* Widget de balance en el dashboard de WP
* Caché de respuestas, manejo de errores, logs

== Installation ==

1. Sube la carpeta `mi_plugin_airalo` a `/wp-content/plugins/`
2. Activa el plugin desde el menú 'Plugins' de WordPress
3. Configura las credenciales en `.env` (en la raíz del plugin):
   ```
   AIRALO_CLIENT_ID=tu_client_id
   AIRALO_CLIENT_SECRET=tu_client_secret
   AIRALO_ENV=production
   ```
4. Ve a Airalo > Ajustes y verifica el estado de la API

== Changelog ==

= 2.3.0 =
* eSIMs: índice basado en `GET /v2/sims` (en vez de `/v2/orders`) — ahora se ven **todas** las eSIMs, incluidas las **expiradas/recicladas**
* eSIMs: aviso amarillo cuando una ICCID existe pero está reciclada (muestra `recycled_at`); aviso informativo si no existe en la cuenta
* eSIMs: columna **Estado** (Completada/Expirada) en la tabla principal; las filas expiradas se muestran con fondo crema
* eSIMs: búsqueda del placeholder reducida a "Buscar por ICCID" (el resto de filtros no funcionaba contra el endpoint nuevo)
* Detalle eSIM: card **eSIM User (Airalo)** con el `simable.user` que devuelve `GET /v2/sims?include=order.user` (Full Name + email reales, no solo el cliente WC)
* Detalle eSIM: formulario **Asignar / cambiar eSIM User** (Full Name + email) que llama a `POST /v2/sims/{iccid}/share` — mismo flujo que en app.partners.airalo.com
* Detalle eSIM: pill de estado de la orden (mismo estilo que en Dashboard) en la cabecera
* Órdenes: columna **Procesado por** con el usuario WP que sincronizó la orden (meta `_mpa_processed_by` → `post_author` → fallback)
* OrderLinker: nuevas constantes `META_AIRALO_USERS` y `META_PROCESSED_BY`; helpers `store_airalo_user`, `get_airalo_users`, `mark_processed_by`, `get_processed_by`
* OrderLinker: `sync_order` ahora siempre deja rastro del WP user que disparó la sincronización
* API Client: `get_sims_list()`, `get_sims_paginated()`, `search_sims_by_iccid()`, `assign_esim_user()` (nuevo)
* AJAX: nueva acción `mpa_assign_esim_user`

= 2.2.0 =
* Dashboard y Órdenes: país junto al paquete (mapa `package_id → country` cacheado 24h)
* Órdenes: resolución robusta del cliente WC (por `WC#N` o por ICCID contra el meta del pedido)
* eSIMs: índice paginado de TODAS las órdenes (no solo las 100 últimas) + búsqueda por ICCID / código / paquete / descripción
* eSIMs: fallback a `GET /v2/sims/{iccid}` cuando el ICCID está fuera del índice
* Detalle eSIM: campo "eSIM User" con el cliente WC; auto-carga de uso/QR con fallback al meta del pedido
* Detalle eSIM: botón **Share eSIM** (`POST /v2/sims/{iccid}/share`) — envía link o QR al cliente
* Reembolsos: formulario "Pedir refund por ICCID" en `Airalo → Reembolsos` con motivo + notas
* Reembolsos: rate-limit local (1 cada 5 min) reflejando el límite real de Airalo, con release si la API falla
* Reembolsos: paginación en las tablas de reconciliación (WC sin Airalo / Airalo sin WC)
* API Client: `get_orders_paginated()`, `get_sim_by_iccid()`, `share_esim()`, `get_country_for_package()`, `get_package_country_map()`
* AJAX: nuevas acciones `mpa_share_esim` y `mpa_refund_esim_by_iccid`

= 2.1.0 =
* Versión interna (no publicada): ajustes de assets, reconciliación, etc.

= 2.0.0 =
* Refactor a PSR-4 con Composer
* Integración con airalo/sdk oficial
* Metabox en pedidos WC
* Refunds, QR, uso, top-ups
* Panel admin completo
