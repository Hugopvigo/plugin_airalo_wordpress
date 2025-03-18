<?php
/*
Plugin Name: Dispositivos API
Description: Plugin para obtener dispositivos compatibles eSIM
Version: 1.1
Author: <a href="https://www.suop.es/" target="_blank">SUOP</a> - Hugo Perez-Vigo
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Cargar Composer autoload
require_once __DIR__ . '/vendor/autoload.php';

// Cargar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Función para obtener el token de acceso
function obtener_token_acceso() {
    // Verificar si ya tenemos un token almacenado
    $token = get_transient('airalo_access_token');

    if ($token) {
        return $token;
    }

    // Si no hay token, solicitamos uno nuevo
    $client_id = $_ENV['AIRALO_CLIENT_ID'];
    $client_secret = $_ENV['AIRALO_CLIENT_SECRET'];
    $url = 'https://partners-api.airalo.com/v2/token';

    $args = array(
        'headers' => array(
            'Accept' => 'application/json',
        ),
        'body' => array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => 'client_credentials',
        ),
    );

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        error_log('Error al obtener el token: ' . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['data']['access_token'])) {
        error_log('Error: No se pudo obtener el token de acceso. Respuesta de la API: ' . print_r($data, true));
        return false;
    }

    $token = $data['data']['access_token'];
    $expires_in = $data['data']['expires_in']; // Tiempo de expiración en segundos

    // Almacenar el token en una transiente con un margen de seguridad (por ejemplo, 1 hora menos)
    set_transient('airalo_access_token', $token, $expires_in - 3600);

    return $token;
}

// Funcion idioma
function dispositivos_api_cargar_textdomain() {
    load_plugin_textdomain('dispositivos-api', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('init', 'dispositivos_api_cargar_textdomain');

// Función para obtener la lista de dispositivos
function obtener_lista_dispositivos($token) {
    $url = 'https://partners-api.airalo.com/v2/compatible-devices';

    $args = array(
        'headers' => array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ),
    );

    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        error_log('Error al obtener la lista de dispositivos: ' . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['data'])) {
        error_log('Error: No se pudieron cargar los dispositivos. Respuesta de la API: ' . print_r($data, true));
        return false;
    }

    return $data['data'];
}

// Shortcode para mostrar el buscador
function mostrar_buscador_dispositivos() {
    $token = obtener_token_acceso();

    if (!$token) {
        return '<p>' . __('El buscador no está disponible en este momento.', 'dispositivos-api') . '</p>';
    }

    $dispositivos = obtener_lista_dispositivos($token);

    if (!$dispositivos) {
        return '<p>' . __('No se pudieron cargar los dispositivos.', 'dispositivos-api') . '</p>';
    }

    // Limitar la lista a 3000 dispositivos
    $dispositivos = array_slice($dispositivos, 0, 3000);

    ob_start();
    ?>
    <div id="buscador-dispositivos">
        <input type="text" id="busqueda" placeholder="<?php esc_attr_e('Busca tu teléfono o tablet...', 'dispositivos-api'); ?>">
        <ul id="resultados"></ul>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const dispositivos = <?php echo json_encode($dispositivos); ?>;
            const input = document.getElementById('busqueda');
            const resultados = document.getElementById('resultados');

            // Textos traducidos
            const textoResultados = "<?php echo esc_js(__('Mostrando 15 de', 'dispositivos-api')); ?>";
            const textoResultadosSufijo = "<?php echo esc_js(__('resultados...', 'dispositivos-api')); ?>";

            input.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                resultados.innerHTML = '';

                if (query.length > 1) {
                    const filtrados = dispositivos.filter(dispositivo => 
                        dispositivo.name.toLowerCase().includes(query) || 
                        dispositivo.brand.toLowerCase().includes(query) || 
                        dispositivo.model.toLowerCase().includes(query)
                    );

                    // Limitar los resultados a 15
                    const resultadosLimitados = filtrados.slice(0, 15);

                    resultadosLimitados.forEach(dispositivo => {
                        const li = document.createElement('li');
                        li.textContent = `${dispositivo.name} (${dispositivo.brand})`;
                        resultados.appendChild(li);
                    });

                    // Mostrar mensaje si hay más de 15 resultados
                    if (filtrados.length > 15) {
                        const li = document.createElement('li');
                        li.textContent = `${textoResultados} ${filtrados.length} ${textoResultadosSufijo}`;
                        resultados.appendChild(li);
                    }
                }
            });
        });
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('buscador_dispositivos', 'mostrar_buscador_dispositivos');
