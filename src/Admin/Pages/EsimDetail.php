<?php
/**
 * Admin page: eSIM detail.
 *
 * URL: /wp-admin/admin.php?page=mpa-esims&action=view&iccid=...
 *
 * Shows full info for a single eSIM: package, usage, top-up options,
 * installation instructions (iOS / Android / Manual), QR and SM-DP+ data.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin\Pages;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Api\Exception;
use Hugo\MiPluginAiralo\Integrations\WooCommerce\OrderLinker;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class EsimDetail {

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        // No hooks; rendered as a sub-page dispatched by Menu::render_page().
    }

    public function render(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }

        $iccid = isset( $_GET['iccid'] ) ? sanitize_text_field( wp_unslash( $_GET['iccid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( '' === $iccid ) {
            echo '<div class="wrap"><h1>Airalo</h1><div class="notice notice-error"><p>' . esc_html__( 'ICCID requerido.', MPA_TEXTDOMAIN ) . '</p></div></div>';
            return;
        }

        $context = $this->load_context( $iccid );
        $notices = [];

        if ( ! empty( $_GET['mpa_action_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $notices[] = [
                'type'    => 'success',
                'message' => sanitize_text_field( wp_unslash( (string) $_GET['mpa_action_done'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ];
        }

        $wc_lookup           = OrderLinker::find_wc_order_by_iccid( $iccid );
        $context['wc_order'] = $wc_lookup['order'];

        $this->render_page( $iccid, $context, $notices );
    }

    private function load_context( string $iccid ): array {
        $ctx = [
            'order'        => null,
            'sim'          => null,
            'usage'        => null,
            'instructions' => null,
            'topups'       => [],
            'error'        => null,
        ];

        if ( ! $this->api->is_configured() ) {
            $ctx['error'] = __( 'Credenciales no configuradas.', MPA_TEXTDOMAIN );
            return $ctx;
        }

        // 1) Find the Airalo order that owns this SIM.
        try {
            $orders = $this->api->get_orders_paginated( 5, 50 );
            foreach ( (array) $orders as $o ) {
                foreach ( (array) ( $o['sims'] ?? [] ) as $s ) {
                    if ( ( $s['iccid'] ?? '' ) === $iccid ) {
                        $ctx['order'] = $o;
                        $ctx['sim']   = $s;
                        break 2;
                    }
                }
            }
        } catch ( Exception $e ) {
            $this->logger->warning( 'eSIM detail orders: ' . $e->getMessage() );
        }

        // 2) Fall back to the single-sim endpoint if we couldn't find the SIM
        //    in the orders list (e.g. > 250 orders old). This endpoint also
        //    brings the `order`, `order.status`, `order.user` and `share`
        //    includes when requested, which gives us the "eSIM User"
        //    (Full Name + email) and the order status badge.
        if ( null === $ctx['sim'] ) {
            $sim_data = $this->api->get_sim_by_iccid( $iccid );
            if ( is_array( $sim_data ) ) {
                $ctx['sim'] = $sim_data;
            }
        }

        // 3) Always try the live sims endpoint with `include=order,order.status,order.user,share`
        //    to enrich the context with the real eSIM User and the live status,
        //    even when we already found the SIM via orders in step 1.
        try {
            $rows = $this->api->search_sims_by_iccid( $iccid );
            if ( ! empty( $rows ) ) {
                $row = (array) $rows[0];
                $ctx['sim']   = $row;
                $ctx['order'] = is_array( $row['order'] ?? null ) ? array_merge( (array) ( $ctx['order'] ?: [] ), $row['order'] ) : ( $ctx['order'] ?? null );
            }
        } catch ( Exception $e ) {
            $this->logger->warning( 'eSIM detail sims include: ' . $e->getMessage() );
        }

        // 3) Resolve the WC order early so we can fall back to its stored usage.
        $wc_order = OrderLinker::find_wc_order_by_iccid( $iccid )['order'] ?? null;

        // 4) Live usage, then fall back to WC meta so a freshly-installed
        //    SIM (Airalo still returning null) shows last known values.
        try {
            $ctx['usage'] = $this->api->get_sim_usage( $iccid );
        } catch ( Exception $e ) {
            $this->logger->warning( 'eSIM detail usage: ' . $e->getMessage() );
        }
        if ( ( empty( $ctx['usage'] ) || empty( $ctx['usage']['data'] ) ) && $wc_order ) {
            $stored = OrderLinker::get_usage( $wc_order );
            if ( ! empty( $stored[ $iccid ] ) ) {
                $ctx['usage'] = $stored[ $iccid ];
            }
        }

        try {
            $ctx['instructions'] = $this->api->get_sim_instructions( $iccid, substr( (string) get_user_locale(), 0, 2 ) ?: 'en' );
        } catch ( Exception $e ) {
            $this->logger->warning( 'eSIM detail instructions: ' . $e->getMessage() );
        }
        if ( ( empty( $ctx['instructions'] ) || empty( $ctx['instructions']['data'] ) ) && $wc_order ) {
            $stored = OrderLinker::get_instructions( $wc_order );
            if ( ! empty( $stored[ $iccid ] ) ) {
                $ctx['instructions'] = $stored[ $iccid ];
            }
        }

        try {
            $topups = $this->api->get_sim_topup_packages( $iccid );
            $ctx['topups'] = is_array( $topups['data'] ?? null ) ? $topups['data'] : ( is_array( $topups ) ? $topups : [] );
        } catch ( Exception $e ) {
            $this->logger->warning( 'eSIM detail topups: ' . $e->getMessage() );
        }

        return $ctx;
    }

    private function render_page( string $iccid, array $ctx, array $notices ): void {
        $order = $ctx['order'];
        $sim   = $ctx['sim'] ?? [];
        $usage = $ctx['usage']['data'] ?? [];
        $instr = $ctx['instructions']['data']['instructions'] ?? [];
        $topups= $ctx['topups'];
        $wc_order = $ctx['wc_order'] ?? null;

        $package_id = (string) ( $order['package_id'] ?? '' );
        $pkg_title  = (string) ( $order['package'] ?? $order['package_id'] ?? 'eSIM' );
        $code       = (string) ( $order['code'] ?? '' );
        $created    = (string) ( $sim['created_at'] ?? $order['created_at'] ?? '' );
        $country    = $this->api->get_country_for_package( $package_id );

        $remaining = isset( $usage['remaining'] ) ? (float) $usage['remaining'] : null;
        $total     = isset( $usage['total'] ) ? (float) $usage['total'] : null;
        $used_pct  = ( null !== $remaining && null !== $total && $total > 0 )
            ? max( 0, min( 100, ( 1 - $remaining / $total ) * 100 ) )
            : null;
        $usage_state = 'unknown';
        if ( null !== $used_pct ) {
            $usage_state = $used_pct >= 90 ? 'critical' : ( $used_pct >= 70 ? 'warning' : 'ok' );
        }

        $qr_payload = (string) ( $sim['qrcode'] ?? '' );
        $qr_url     = (string) ( $sim['qrcode_url'] ?? '' );
        $apple_url  = (string) ( $sim['direct_apple_installation_url'] ?? '' );

        $smdp_ios = '';
        $steps_ios_qr = [];
        $steps_ios_manual = [];
        $steps_android_qr = [];
        $steps_android_manual = [];
        if ( is_array( $instr ) ) {
            $ios_block     = $instr['ios'][0] ?? [];
            $android_block = $instr['android'][0] ?? [];
            $smdp_ios      = (string) ( $ios_block['installation_manual']['smdp_address_and_activation_code'] ?? '' );
            $steps_ios_qr     = array_values( (array) ( $ios_block['installation_via_qr_code']['steps'] ?? [] ) );
            $steps_ios_manual = array_values( (array) ( $ios_block['installation_manual']['steps'] ?? [] ) );
            $steps_android_qr = array_values( (array) ( $android_block['installation_via_qr_code']['steps'] ?? [] ) );
            $steps_android_manual = array_values( (array) ( $android_block['installation_manual']['steps'] ?? [] ) );
        }

        $customer_name  = '';
        $customer_email = '';
        $wc_edit_url    = '';
        if ( $wc_order ) {
            $customer_name  = trim( (string) $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
            $customer_email = (string) $wc_order->get_billing_email();
            $wc_edit_url    = (string) $wc_order->get_edit_order_url();
        }

        // Airalo-side eSIM User (Full Name + email) from simable.user.
        $airalo_user = (array) ( $sim['user'] ?? $order['user'] ?? [] );
        $airalo_user_name  = (string) ( $airalo_user['name'] ?? '' );
        $airalo_user_email = (string) ( $airalo_user['email'] ?? '' );

        // Order status (live, from /v2/sims include=order.status).
        $order_status = (array) ( $order['status'] ?? [] );
        $status_slug  = strtolower( (string) ( $order_status['slug'] ?? $order['status'] ?? '' ) );
        $status_name  = (string) ( $order_status['name'] ?? '' );

        ?>
        <div class="wrap mpa-wrap mpa-detail">
            <a class="mpa-back" href="<?php echo esc_url( admin_url( 'admin.php?page=mpa-esims' ) ); ?>">
                ← <?php esc_html_e( 'Volver a eSIMs', MPA_TEXTDOMAIN ); ?>
            </a>

            <header class="mpa-detail__head">
                <div class="mpa-detail__title">
                    <h1>
                        <?php echo esc_html( $pkg_title ); ?>
                        <?php if ( '' !== $country ) : ?>
                            <span class="mpa-pill mpa-pill--country" title="<?php echo esc_attr( $country ); ?>"><?php echo esc_html( $country ); ?></span>
                        <?php endif; ?>
                    </h1>
                    <code class="mpa-detail__iccid"><?php echo esc_html( $iccid ); ?></code>
                </div>
                <div class="mpa-detail__meta">
                    <?php if ( '' !== $code ) : ?>
                        <span class="mpa-pill"><?php
                            /* translators: %s: Airalo order code */
                            echo esc_html( sprintf( __( 'Airalo %s', MPA_TEXTDOMAIN ), $code ) );
                        ?></span>
                    <?php endif; ?>
                    <?php if ( '' !== $created ) : ?>
                        <span class="mpa-pill mpa-pill--muted"><?php echo esc_html( $created ); ?></span>
                    <?php endif; ?>
                    <?php if ( '' !== $status_slug ) : ?>
                        <span class="mpa-status mpa-status--<?php echo esc_attr( $status_slug ); ?>" title="<?php echo esc_attr( $status_name ); ?>"><?php echo esc_html( '' !== $status_name ? $status_name : $status_slug ); ?></span>
                    <?php endif; ?>
                    <?php if ( $wc_order ) : ?>
                        <a class="mpa-pill mpa-pill--muted" href="<?php echo esc_url( $wc_edit_url ); ?>" title="<?php esc_attr_e( 'Ver pedido WooCommerce', MPA_TEXTDOMAIN ); ?>">
                            <?php
                            /* translators: %d: WC order id */
                            echo esc_html( sprintf( __( 'WC#%d', MPA_TEXTDOMAIN ), $wc_order->get_id() ) );
                            ?>
                        </a>
                    <?php endif; ?>
                </div>
            </header>

            <?php foreach ( $notices as $n ) :
                $type = ( 'error' === ( $n['type'] ?? '' ) ) ? 'error' : 'success'; ?>
                <div class="notice notice-<?php echo esc_attr( $type ); ?> inline"><p><?php echo esc_html( $n['message'] ); ?></p></div>
            <?php endforeach; ?>

            <?php if ( $ctx['error'] ) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html( $ctx['error'] ); ?></p></div>
            <?php endif; ?>

            <div class="mpa-card mpa-customer mpa-customer--airalo">
                <strong><?php esc_html_e( 'eSIM User (Airalo):', MPA_TEXTDOMAIN ); ?></strong>
                <?php if ( '' !== $airalo_user_name || '' !== $airalo_user_email ) : ?>
                    <?php echo esc_html( '' !== $airalo_user_name ? $airalo_user_name : $airalo_user_email ); ?>
                    <?php if ( '' !== $airalo_user_email ) : ?>
                        &lt;<a href="mailto:<?php echo esc_attr( $airalo_user_email ); ?>"><?php echo esc_html( $airalo_user_email ); ?></a>&gt;
                    <?php endif; ?>
                <?php else : ?>
                    <span style="color:#8c8f94;"><?php esc_html_e( 'Sin asignar en Airalo.', MPA_TEXTDOMAIN ); ?></span>
                <?php endif; ?>
                <button type="button" class="button button-small mpa-assign-toggle" style="margin-left:8px;vertical-align:middle;"><?php esc_html_e( 'Asignar / cambiar', MPA_TEXTDOMAIN ); ?></button>
                <div class="mpa-assign-form" style="display:none;margin-top:8px;">
                    <input type="text" class="mpa-assign-name" style="width:100%;margin-bottom:4px;" value="<?php echo esc_attr( $airalo_user_name ); ?>" placeholder="<?php esc_attr_e( 'Full Name', MPA_TEXTDOMAIN ); ?>" />
                    <input type="email" class="mpa-assign-email" style="width:100%;margin-bottom:4px;" value="<?php echo esc_attr( $airalo_user_email ); ?>" placeholder="<?php esc_attr_e( 'email@cliente.com', MPA_TEXTDOMAIN ); ?>" />
                    <button type="button" class="button button-secondary mpa-action" data-action="mpa_assign_esim_user" data-iccid="<?php echo esc_attr( $iccid ); ?>">
                        <?php esc_html_e( 'Asignar eSIM User', MPA_TEXTDOMAIN ); ?>
                    </button>
                    <p class="description" style="margin-top:6px;">
                        <?php esc_html_e( 'Envía el eSIM Cloud a este usuario y queda registrado como eSIM User en Airalo (igual que en app.partners.airalo.com).', MPA_TEXTDOMAIN ); ?>
                    </p>
                </div>
            </div>

            <?php if ( $wc_order ) : ?>
                <div class="mpa-card mpa-customer">
                    <strong><?php esc_html_e( 'Cliente WC:', MPA_TEXTDOMAIN ); ?></strong>
                    <?php echo esc_html( $customer_name !== '' ? $customer_name : $customer_email ); ?>
                    <?php if ( $customer_name !== '' && $customer_email !== '' ) : ?>
                        &lt;<a href="mailto:<?php echo esc_attr( $customer_email ); ?>"><?php echo esc_html( $customer_email ); ?></a>&gt;
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mpa-detail__grid">
                <main class="mpa-detail__main">

                    <section class="mpa-card mpa-card--usage mpa-card--usage-<?php echo esc_attr( $usage_state ); ?>">
                        <header class="mpa-card__header">
                            <h2><?php esc_html_e( 'Consumo', MPA_TEXTDOMAIN ); ?></h2>
                            <?php if ( ! empty( $ctx['usage']['updated'] ) || ! empty( $usage['updated_at'] ) ) : ?>
                                <small class="mpa-card__hint">
                                    <?php
                                    $when = (string) ( $ctx['usage']['updated'] ?? $usage['updated_at'] ?? '' );
                                    if ( '' !== $when ) {
                                        echo esc_html( sprintf( __( 'Actualizado %s', MPA_TEXTDOMAIN ), $when ) );
                                    }
                                    ?>
                                </small>
                            <?php endif; ?>
                        </header>
                        <?php if ( null === $used_pct ) : ?>
                            <p class="mpa-card__empty"><?php esc_html_e( 'Aún no hay datos de uso.', MPA_TEXTDOMAIN ); ?></p>
                        <?php else : ?>
                            <div class="mpa-usage-large">
                                <div class="mpa-usage-large__bar">
                                    <span style="width: <?php echo esc_attr( (string) $used_pct ); ?>%"></span>
                                </div>
                                <div class="mpa-usage-large__legend">
                                    <span><?php echo esc_html( size_format( (float) ( $total - $remaining ) ) ); ?> <?php esc_html_e( 'usado', MPA_TEXTDOMAIN ); ?></span>
                                    <span class="mpa-usage-large__remaining"><?php echo esc_html( size_format( $remaining ) ); ?> <?php esc_html_e( 'restante', MPA_TEXTDOMAIN ); ?></span>
                                    <span><?php echo esc_html( size_format( $total ) ); ?> <?php esc_html_e( 'total', MPA_TEXTDOMAIN ); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="mpa-card">
                        <header class="mpa-card__header">
                            <h2><?php esc_html_e( 'Instalación', MPA_TEXTDOMAIN ); ?></h2>
                        </header>
                        <div class="mpa-tabs" data-tabs>
                            <div class="mpa-tabs__nav" role="tablist">
                                <button type="button" class="mpa-tabs__tab is-active" data-tab="ios" role="tab">iOS</button>
                                <button type="button" class="mpa-tabs__tab" data-tab="android" role="tab">Android</button>
                                <button type="button" class="mpa-tabs__tab" data-tab="manual" role="tab"><?php esc_html_e( 'Manual SM-DP+', MPA_TEXTDOMAIN ); ?></button>
                                <button type="button" class="mpa-tabs__tab" data-tab="qr" role="tab"><?php esc_html_e( 'QR', MPA_TEXTDOMAIN ); ?></button>
                            </div>

                            <div class="mpa-tabs__panel is-active" data-panel="ios">
                                <h3><?php esc_html_e( 'Por QR', MPA_TEXTDOMAIN ); ?></h3>
                                <?php $this->render_steps( $steps_ios_qr ); ?>
                                <h3><?php esc_html_e( 'Manual', MPA_TEXTDOMAIN ); ?></h3>
                                <?php $this->render_steps( $steps_ios_manual ); ?>
                            </div>

                            <div class="mpa-tabs__panel" data-panel="android">
                                <h3><?php esc_html_e( 'Por QR', MPA_TEXTDOMAIN ); ?></h3>
                                <?php $this->render_steps( $steps_android_qr ); ?>
                                <h3><?php esc_html_e( 'Manual', MPA_TEXTDOMAIN ); ?></h3>
                                <?php $this->render_steps( $steps_android_manual ); ?>
                            </div>

                            <div class="mpa-tabs__panel" data-panel="manual">
                                <p class="mpa-card__hint"><?php esc_html_e( 'Copia y pega estos datos en Ajustes > Datos celulares > Añadir eSIM > Introduce los datos manualmente.', MPA_TEXTDOMAIN ); ?></p>
                                <div class="mpa-copyfield">
                                    <code id="mpa-smdp"><?php echo esc_html( $smdp_ios ); ?></code>
                                    <button type="button" class="button button-small" data-copy="#mpa-smdp"><?php esc_html_e( 'Copiar', MPA_TEXTDOMAIN ); ?></button>
                                </div>
                            </div>

                            <div class="mpa-tabs__panel" data-panel="qr">
                                <?php if ( '' !== $qr_url ) : ?>
                                    <div class="mpa-qr">
                                        <img src="<?php echo esc_url( $qr_url ); ?>" alt="<?php esc_attr_e( 'QR de instalación', MPA_TEXTDOMAIN ); ?>" />
                                    </div>
                                <?php elseif ( '' !== $qr_payload ) : ?>
                                    <p class="mpa-card__empty"><?php esc_html_e( 'No hay URL de imagen; usa el payload:', MPA_TEXTDOMAIN ); ?></p>
                                <?php else : ?>
                                    <p class="mpa-card__empty"><?php esc_html_e( 'QR no disponible.', MPA_TEXTDOMAIN ); ?></p>
                                <?php endif; ?>

                                <?php if ( '' !== $qr_payload ) : ?>
                                    <div class="mpa-copyfield">
                                        <code id="mpa-qr-payload"><?php echo esc_html( $qr_payload ); ?></code>
                                        <button type="button" class="button button-small" data-copy="#mpa-qr-payload"><?php esc_html_e( 'Copiar payload', MPA_TEXTDOMAIN ); ?></button>
                                    </div>
                                <?php endif; ?>

                                <?php if ( '' !== $apple_url ) : ?>
                                    <p>
                                        <a class="button" href="<?php echo esc_url( $apple_url ); ?>" target="_blank" rel="noopener">
                                            <?php esc_html_e( 'Instalación directa iOS (iOS 17.4+)', MPA_TEXTDOMAIN ); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>

                    <?php if ( ! empty( $topups ) ) : ?>
                        <section class="mpa-card">
                            <header class="mpa-card__header">
                                <h2><?php esc_html_e( 'Top-ups disponibles', MPA_TEXTDOMAIN ); ?></h2>
                            </header>
                            <div class="mpa-topup-grid">
                                <?php foreach ( (array) $topups as $t ) :
                                    $tpkg  = (string) ( $t['package_id'] ?? '' );
                                    $ttitle= (string) ( $t['title'] ?? $tpkg );
                                    $tdata = (string) ( $t['data'] ?? '' );
                                    $tday  = (int) ( $t['day'] ?? 0 );
                                    $tprice= isset( $t['net_price'] ) ? (float) $t['net_price'] : (float) ( $t['price'] ?? 0 );
                                    ?>
                                    <div class="mpa-topup-card">
                                        <h3 class="mpa-topup-card__title"><?php echo esc_html( $ttitle ); ?></h3>
                                        <p class="mpa-topup-card__meta">
                                            <?php if ( '' !== $tdata ) : ?><span><?php echo esc_html( $tdata ); ?></span><?php endif; ?>
                                            <?php if ( $tday > 0 ) : ?><span><?php
                                                /* translators: %d: days */
                                                echo esc_html( sprintf( _n( '%d día', '%d días', $tday, MPA_TEXTDOMAIN ), $tday ) );
                                            ?></span><?php endif; ?>
                                        </p>
                                        <p class="mpa-topup-card__price">$<?php echo esc_html( number_format_i18n( $tprice, 2 ) ); ?></p>
                                        <button type="button"
                                                class="button button-secondary mpa-action"
                                                data-action="mpa_topup"
                                                data-order="<?php echo esc_attr( (string) ( $ctx['wc_order'] ? $ctx['wc_order']->get_id() : 0 ) ); ?>"
                                                data-iccid="<?php echo esc_attr( $iccid ); ?>"
                                                data-package="<?php echo esc_attr( $tpkg ); ?>">
                                            <?php esc_html_e( 'Comprar top-up', MPA_TEXTDOMAIN ); ?>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </main>

                <aside class="mpa-detail__side">
                    <div class="mpa-card">
                        <h2 class="mpa-card__title"><?php esc_html_e( 'Acciones', MPA_TEXTDOMAIN ); ?></h2>
                        <div class="mpa-action-stack">
                            <button type="button" class="button mpa-action" data-action="mpa_get_usage" data-iccid="<?php echo esc_attr( $iccid ); ?>">
                                <?php esc_html_e( 'Refrescar uso', MPA_TEXTDOMAIN ); ?>
                            </button>
                            <button type="button" class="button mpa-action" data-action="mpa_get_qr" data-iccid="<?php echo esc_attr( $iccid ); ?>">
                                <?php esc_html_e( 'Refrescar QR / instrucciones', MPA_TEXTDOMAIN ); ?>
                            </button>
                            <button type="button" class="button mpa-share-toggle">
                                <?php esc_html_e( 'Share eSIM', MPA_TEXTDOMAIN ); ?>
                            </button>
                            <div class="mpa-share-form" style="display:none;margin-top:6px;">
                                <input type="email" class="mpa-share-email" style="width:100%;margin-bottom:4px;" value="<?php echo esc_attr( $customer_email ); ?>" placeholder="<?php esc_attr_e( 'email@cliente.com', MPA_TEXTDOMAIN ); ?>" />
                                <select class="mpa-share-option" style="width:100%;margin-bottom:4px;">
                                    <option value="link"><?php esc_html_e( 'Enviar link de instalación', MPA_TEXTDOMAIN ); ?></option>
                                    <option value="qrcode"><?php esc_html_e( 'Enviar QR por email', MPA_TEXTDOMAIN ); ?></option>
                                </select>
                                <button type="button" class="button button-small mpa-action" data-action="mpa_share_esim" data-iccid="<?php echo esc_attr( $iccid ); ?>">
                                    <?php esc_html_e( 'Enviar al cliente', MPA_TEXTDOMAIN ); ?>
                                </button>
                            </div>
                            <button type="button" class="button mpa-action mpa-action--danger mpa-refund-toggle">
                                <?php esc_html_e( 'Solicitar refund', MPA_TEXTDOMAIN ); ?>
                            </button>
                            <div class="mpa-refund-form" style="display:none;margin-top:6px;">
                                <select class="mpa-refund-reason" style="width:100%;margin-bottom:4px;">
                                    <option value="SERVICE_ISSUES"><?php esc_html_e( 'Problemas de servicio', MPA_TEXTDOMAIN ); ?></option>
                                    <option value="INVALID_ACTIVATION"><?php esc_html_e( 'La eSIM no se activó', MPA_TEXTDOMAIN ); ?></option>
                                    <option value="DUPLICATE_ORDER"><?php esc_html_e( 'Pedido duplicado', MPA_TEXTDOMAIN ); ?></option>
                                    <option value="OTHERS"><?php esc_html_e( 'Otros motivos', MPA_TEXTDOMAIN ); ?></option>
                                </select>
                                <textarea class="mpa-refund-notes" style="width:100%;min-height:40px;font-size:12px;" placeholder="<?php esc_attr_e( 'Notas opcionales…', MPA_TEXTDOMAIN ); ?>"></textarea>
                                <button type="button" class="button button-small mpa-action mpa-action--danger" data-action="mpa_refund_esim">
                                    <?php esc_html_e( 'Confirmar refund', MPA_TEXTDOMAIN ); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if ( ! empty( $order ) ) : ?>
                        <div class="mpa-card">
                            <h2 class="mpa-card__title"><?php esc_html_e( 'Datos de la orden', MPA_TEXTDOMAIN ); ?></h2>
                            <dl class="mpa-dl">
                                <dt><?php esc_html_e( 'Paquete', MPA_TEXTDOMAIN ); ?></dt><dd><?php echo esc_html( (string) ( $order['package_id'] ?? '' ) ); ?></dd>
                                <dt><?php esc_html_e( 'Tipo', MPA_TEXTDOMAIN ); ?></dt><dd><?php echo esc_html( (string) ( $order['type'] ?? '' ) ); ?></dd>
                                <dt><?php esc_html_e( 'Cantidad', MPA_TEXTDOMAIN ); ?></dt><dd><?php echo esc_html( (string) ( $order['quantity'] ?? '' ) ); ?></dd>
                                <dt><?php esc_html_e( 'Precio', MPA_TEXTDOMAIN ); ?></dt><dd>$<?php echo esc_html( number_format_i18n( (float) ( $order['price'] ?? 0 ), 2 ) ); ?></dd>
                                <dt><?php esc_html_e( 'APN', MPA_TEXTDOMAIN ); ?></dt><dd><?php echo esc_html( (string) ( $sim['apn_type'] ?? '' ) ); ?><?php
                                    $apn = (string) ( $sim['apn_value'] ?? '' );
                                    if ( '' !== $apn ) {
                                        echo ' (' . esc_html( $apn ) . ')';
                                    }
                                ?></dd>
                                <dt><?php esc_html_e( 'Roaming', MPA_TEXTDOMAIN ); ?></dt><dd><?php
                                    echo ! empty( $sim['is_roaming'] ) ? esc_html__( 'Sí', MPA_TEXTDOMAIN ) : esc_html__( 'No', MPA_TEXTDOMAIN );
                                ?></dd>
                            </dl>
                        </div>
                    <?php endif; ?>

                    <div class="mpa-card">
                        <h2 class="mpa-card__title"><?php esc_html_e( 'Identificadores', MPA_TEXTDOMAIN ); ?></h2>
                        <div class="mpa-copyfield">
                            <code id="mpa-iccid"><?php echo esc_html( $iccid ); ?></code>
                            <button type="button" class="button button-small" data-copy="#mpa-iccid"><?php esc_html_e( 'Copiar', MPA_TEXTDOMAIN ); ?></button>
                        </div>
                        <?php if ( ! empty( $sim['matching_id'] ) ) : ?>
                            <div class="mpa-copyfield">
                                <code id="mpa-match"><?php echo esc_html( (string) $sim['matching_id'] ); ?></code>
                                <button type="button" class="button button-small" data-copy="#mpa-match"><?php esc_html_e( 'Copiar matching_id', MPA_TEXTDOMAIN ); ?></button>
                            </div>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        </div>
        <?php
    }

    private function render_steps( array $steps ): void {
        if ( empty( $steps ) ) {
            echo '<p class="mpa-card__empty">' . esc_html__( 'Sin instrucciones.', MPA_TEXTDOMAIN ) . '</p>';
            return;
        }
        echo '<ol class="mpa-steps">';
        foreach ( $steps as $i => $step ) {
            echo '<li><span class="mpa-steps__num">' . esc_html( (string) ( $i + 1 ) ) . '</span><span>' . esc_html( (string) $step ) . '</span></li>';
        }
        echo '</ol>';
    }
}
