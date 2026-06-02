<?php
/**
 * Front: buscador de dispositivos compatibles (shortcode).
 *
 * Migrated from dispositivos-api.php to the new PSR-4 structure.
 * The legacy dispositivos-api.php plugin is kept untouched for backwards
 * compatibility; deactivate it once the new plugin is active.
 *
 * Shortcode: [buscador_dispositivos]
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Front;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class DispositivosShortcode {

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        add_shortcode( 'buscador_dispositivos', [ $this, 'render' ] );
    }

    public function render( $atts = [] ): string {
        if ( ! $this->api->is_configured() ) {
            return '<p>' . esc_html__( 'El buscador no está disponible en este momento.', MPA_TEXTDOMAIN ) . '</p>';
        }

        try {
            $devices = $this->api->get_compatible_devices();
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'Dispositivos shortcode: ' . $e->getMessage() );
            return '<p>' . esc_html__( 'No se pudieron cargar los dispositivos.', MPA_TEXTDOMAIN ) . '</p>';
        }

        if ( ! is_array( $devices ) || empty( $devices ) ) {
            return '<p>' . esc_html__( 'No hay dispositivos para mostrar.', MPA_TEXTDOMAIN ) . '</p>';
        }

        $devices = array_slice( $devices, 0, 3000 );

        ob_start();
        ?>
        <div id="buscador-dispositivos" class="mpa-buscador">
            <input
                type="text"
                id="busqueda"
                class="mpa-buscador__input"
                placeholder="<?php esc_attr_e( 'Busca tu teléfono o tablet...', MPA_TEXTDOMAIN ); ?>"
                autocomplete="off"
            >
            <ul id="resultados" class="mpa-buscador__resultados" role="listbox"></ul>
        </div>
        <script>
        (function () {
            const dispositivos = <?php echo wp_json_encode( $devices ); ?>;
            const input = document.getElementById('busqueda');
            const resultados = document.getElementById('resultados');
            const t1 = <?php echo wp_json_encode( __( 'Mostrando 15 de', MPA_TEXTDOMAIN ) ); ?>;
            const t2 = <?php echo wp_json_encode( __( 'resultados...', MPA_TEXTDOMAIN ) ); ?>;

            function normalise(s){ return (s || '').toString().toLowerCase(); }

            if (!input) return;
            input.addEventListener('input', function () {
                const q = normalise(this.value);
                resultados.innerHTML = '';
                if (q.length < 2) return;
                const filtered = dispositivos.filter(d =>
                    normalise(d.name).includes(q) ||
                    normalise(d.brand).includes(q) ||
                    normalise(d.model).includes(q)
                ).slice(0, 15);

                filtered.forEach(d => {
                    const li = document.createElement('li');
                    li.textContent = d.name + ' (' + d.brand + ')';
                    li.setAttribute('role', 'option');
                    resultados.appendChild(li);
                });
                if (filtered.length === 0) return;
                if (filtered.length > 15 || filtered.length < dispositivos.filter(d =>
                    normalise(d.name).includes(q) || normalise(d.brand).includes(q) || normalise(d.model).includes(q)
                ).length) {
                    const li = document.createElement('li');
                    li.className = 'mpa-buscador__more';
                    li.textContent = t1 + ' ' + dispositivos.filter(d =>
                        normalise(d.name).includes(q) || normalise(d.brand).includes(q) || normalise(d.model).includes(q)
                    ).length + ' ' + t2;
                    resultados.appendChild(li);
                }
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
