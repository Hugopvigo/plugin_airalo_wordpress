<?php
/**
 * Admin page: eSIMs list (with usage).
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin\Pages;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Api\Exception;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Countries;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Esims {

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {}

    public function render(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }

        $search      = sanitize_text_field( (string) ( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_pg  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page    = 50;
        $sims        = [];
        $error       = null;
        $loaded      = 0;
        $notice      = null;

        if ( $this->api->is_configured() ) {
            try {
                $sims   = $this->load_sim_index();
                $loaded = count( $sims );

                if ( '' !== $search ) {
                    $needle = strtolower( $search );
                    $sims   = array_values( array_filter( $sims, static function ( $s ) use ( $needle ) {
                        return strpos( strtolower( (string) ( $s['iccid'] ?? '' ) ), $needle ) !== false;
                    } ) );

                    if ( empty( $sims ) && preg_match( '/^\d{16,22}$/', $search ) ) {
                        $rows = $this->api->search_sims_by_iccid( $search );
                        if ( ! empty( $rows ) ) {
                            $sims = array_map( [ $this, 'normalize_sim' ], $rows );
                            if ( (bool) ( $sims[0]['_recycled'] ?? false ) ) {
                                $recycled_at = (string) ( $sims[0]['_recycled_at'] ?? '' );
                                $notice      = sprintf(
                                    /* translators: 1: ICCID, 2: recycled-at date */
                                    __( 'eSIM %1$s encontrada pero está expirada (reciclada el %2$s).', MPA_TEXTDOMAIN ),
                                    $search,
                                    $recycled_at ? $recycled_at : '—'
                                );
                            } else {
                                $notice = sprintf(
                                    /* translators: %s: ICCID */
                                    __( 'eSIM %s encontrada vía búsqueda directa en Airalo.', MPA_TEXTDOMAIN ),
                                    $search
                                );
                            }
                        } elseif ( '' !== $search ) {
                            $notice = sprintf(
                                /* translators: %s: searched ICCID */
                                __( 'No se encontró ninguna eSIM con ICCID %s en tu cuenta Airalo.', MPA_TEXTDOMAIN ),
                                $search
                            );
                        }
                    } elseif ( '' !== $search && empty( $sims ) ) {
                        $notice = sprintf(
                            /* translators: %s: search query */
                            __( 'No hay coincidencias para "%s" en el índice local. Si la eSIM está expirada prueba con la búsqueda directa (ya integrada) o con la ICCID completa.', MPA_TEXTDOMAIN ),
                            $search
                        );
                    }
                }
            } catch ( Exception $e ) {
                $error = $e->getMessage();
                $this->logger->warning( 'eSIMs list: ' . $e->getMessage() );
            }
        }

        $total_pages = (int) ceil( $loaded / $per_page );
        $offset      = ( $current_pg - 1 ) * $per_page;
        $page_sims   = array_slice( $sims, $offset, $per_page );
        ?>
        <div class="wrap mpa-wrap">
            <h1><?php esc_html_e( 'Airalo · eSIMs', MPA_TEXTDOMAIN ); ?></h1>

            <form method="get" class="mpa-search">
                <input type="hidden" name="page" value="mpa-esims" />
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Buscar por ICCID', MPA_TEXTDOMAIN ); ?>" />
                <?php submit_button( __( 'Buscar', MPA_TEXTDOMAIN ), 'secondary', '', false ); ?>
                <?php if ( '' !== $search ) : ?>
                    <a class="button button-link" href="<?php echo esc_url( remove_query_arg( 's' ) ); ?>"><?php esc_html_e( 'Limpiar', MPA_TEXTDOMAIN ); ?></a>
                <?php endif; ?>
            </form>

            <p class="description">
                <?php
                /* translators: %d: number of eSIMs indexed */
                echo esc_html( sprintf( _n( 'Índice: %d eSIM', 'Índice: %d eSIMs', $loaded, MPA_TEXTDOMAIN ), $loaded ) );
                ?>
                <span style="margin-left:8px;color:#8c8f94;"><?php esc_html_e( 'La búsqueda funciona por ICCID exacta (incluye eSIMs expiradas).', MPA_TEXTDOMAIN ); ?></span>
            </p>

            <?php if ( $error ) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <?php if ( $notice ) :
                $cls = str_contains( strtolower( $notice ), 'expirada' ) || str_contains( strtolower( $notice ), 'no se encontró' ) ? 'notice-warning' : 'notice-info';
                ?>
                <div class="notice <?php echo esc_attr( $cls ); ?> inline"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>

            <?php $this->render_table( $page_sims ); ?>

            <?php if ( $total_pages > 1 && '' === $search ) : ?>
            <div class="mpa-pagination" style="margin-top:12px;">
                <?php if ( $current_pg > 1 ) : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current_pg - 1 ) ); ?>"><?php esc_html_e( '« Anterior', MPA_TEXTDOMAIN ); ?></a>
                <?php endif; ?>
                <span style="margin:0 10px;line-height:30px;">
                    <?php echo esc_html( sprintf( __( 'Página %d de %d', MPA_TEXTDOMAIN ), $current_pg, $total_pages ) ); ?>
                </span>
                <?php if ( $current_pg < $total_pages ) : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current_pg + 1 ) ); ?>"><?php esc_html_e( 'Siguiente »', MPA_TEXTDOMAIN ); ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Builds a flat list of every eSIM in the account.
     *
     * Uses `GET /v2/sims` (not `/v2/orders`) so that recycled / expired
     * eSIMs are included in the index and can be looked up. The list is
     * cached for 5 min.
     *
     * @return array<int, array<string,mixed>>
     */
    private function load_sim_index(): array {
        $cache_key = 'mpa_sim_index_v2';
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $rows        = $this->api->get_sims_paginated( [], 30, 100 );
        $order_map   = $this->api->build_iccid_order_map();
        $sims        = [];
        foreach ( (array) $rows as $row ) {
            $sims[] = $this->normalize_sim( $row, $order_map );
        }

        set_transient( $cache_key, $sims, 5 * MINUTE_IN_SECONDS );
        return $sims;
    }

    /**
     * Maps a raw `GET /v2/sims` row to the internal shape used by the table.
     * Falls back to the ICCID→order map for fields no longer returned by
     * the list endpoint (package, description, code).
     *
     * @param array<string,array{package_id:string,package:string,description:string,code:string,created_at:string}> $order_map
     */
    private function normalize_sim( array $row, array $order_map = [] ): array {
        $iccid   = (string) ( $row['iccid'] ?? '' );
        $enrich  = $iccid && isset( $order_map[ $iccid ] ) ? $order_map[ $iccid ] : [];
        $order   = (array) ( $row['order'] ?? [] );

        $row['_order_code']  = (string) ( $order['code'] ?? $enrich['code'] ?? $row['code'] ?? '' );
        $row['_package_id']  = (string) ( $row['package_id'] ?? $enrich['package_id'] ?? $order['package_id'] ?? '' );
        $row['_package']     = (string) ( $enrich['package'] ?? $order['package'] ?? $row['package'] ?? '' );
        $row['_created_at']  = (string) ( $row['created_at'] ?? $order['created_at'] ?? $enrich['created_at'] ?? '' );
        $row['_description'] = (string) ( $enrich['description'] ?? $order['description'] ?? '' );
        $row['_recycled']    = (bool) ( $row['recycled'] ?? false );
        $row['_recycled_at'] = (string) ( $row['recycled_at'] ?? '' );
        return $row;
    }

    private function render_table( array $sims ): void {
        if ( empty( $sims ) ) {
            echo '<p>' . esc_html__( 'No hay eSIMs para mostrar.', MPA_TEXTDOMAIN ) . '</p>';
            return;
        }
        ?>
        <table class="widefat striped mpa-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ICCID', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Usuario', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'País', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Paquete', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Código', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Creada', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Acciones', MPA_TEXTDOMAIN ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $sims as $sim ) :
                $iccid  = (string) ( $sim['iccid'] ?? '' );
                if ( '' === $iccid ) {
                    continue;
                }
                $detail_url = add_query_arg(
                    [ 'page' => 'mpa-esim-detail', 'iccid' => $iccid ],
                    admin_url( 'admin.php' )
                );
                $user_label = $this->get_esim_user( $sim );
                $country    = $this->api->get_country_for_package( (string) ( $sim['_package_id'] ?? '' ) );
                $recycled   = (bool) ( $sim['_recycled'] ?? false );
            ?>
                <tr <?php echo $recycled ? 'style="background:#fff8e1;"' : ''; ?>>
                    <td><a href="<?php echo esc_url( $detail_url ); ?>"><code><?php echo esc_html( $iccid ); ?></code></a></td>
                    <td><?php echo '' !== $user_label ? esc_html( $user_label ) : '<span style="color:#8c8f94;">—</span>'; ?></td>
                    <td><?php echo '' !== $country ? '<span class="mpa-pill mpa-pill--country" title="' . esc_attr( $country ) . '">' . esc_html( Countries::name( $country ) ) . '</span>' : '<span style="color:#8c8f94;">—</span>'; ?></td>
                    <td>
                        <?php echo esc_html( (string) ( $sim['_package'] ?? $sim['_package_id'] ?? '' ) ); ?>
                        <?php if ( ! empty( $sim['_package'] ) && ! empty( $sim['_package_id'] ) && $sim['_package'] !== $sim['_package_id'] ) : ?>
                            <br><code class="mpa-mono"><?php echo esc_html( (string) $sim['_package_id'] ); ?></code>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( (string) ( $sim['_order_code'] ?? '' ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $sim['_created_at'] ?? '' ) ); ?></td>
                    <td>
                        <div class="mpa-action-stack" style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Detalle', MPA_TEXTDOMAIN ); ?></a>
                            <?php if ( ! $recycled ) : ?>
                                <button type="button" class="button button-small mpa-action" data-action="mpa_get_usage" data-iccid="<?php echo esc_attr( $iccid ); ?>"><?php esc_html_e( 'Uso', MPA_TEXTDOMAIN ); ?></button>
                                <button type="button" class="button button-small mpa-action" data-action="mpa_refund_esim" data-iccid="<?php echo esc_attr( $iccid ); ?>"><?php esc_html_e( 'Refund', MPA_TEXTDOMAIN ); ?></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function get_esim_user( array $sim ): string {
        $description = (string) ( $sim['_description'] ?? '' );
        if ( $description && preg_match( '/WC#(\d+)/', $description, $m ) ) {
            $wc_order = wc_get_order( (int) $m[1] );
            if ( $wc_order ) {
                $name  = trim( (string) $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
                $email = (string) $wc_order->get_billing_email();
                return trim( $name ) ? "$name ($email)" : $email;
            }
        }

        $iccid = (string) ( $sim['iccid'] ?? '' );
        if ( '' !== $iccid ) {
            $found = \Hugo\MiPluginAiralo\Integrations\WooCommerce\OrderLinker::find_wc_order_by_iccid( $iccid );
            if ( $found['order'] ) {
                $wc_order = $found['order'];
                $name  = trim( (string) $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
                $email = (string) $wc_order->get_billing_email();
                return trim( $name ) ? "$name ($email)" : $email;
            }
        }
        return '';
    }
}
