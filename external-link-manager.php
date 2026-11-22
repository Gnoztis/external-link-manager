<?php
/**
 * Plugin Name: External Link Manager
 * Description: Добавляет rel="nofollow" и target="_blank" для внешних ссылок. Есть возможность указать домены-исключения.
 * Version:     2.3.0
 * License:     GPL v2 or later
 * Text Domain: external-link-manager
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.5
 * Tested up to: 7.1
 *
 * @package ExternalLinkManager
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ELM_VERSION', '2.3.0' );
define( 'ELM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ELM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ELM_BASENAME', plugin_basename( __FILE__ ) );
define( 'ELM_REQUIRES_PHP', '8.5' );
define( 'ELM_REQUIRES_WP', '6.4' );

$elm_class_file = ELM_PLUGIN_DIR . 'includes/class-elm-external-link-manager.php';
if ( file_exists( $elm_class_file ) ) {
    require_once $elm_class_file;
}

/* -------------------------------------------------------------------------
 * Ссылка «Настройки» в списке плагинов.
 * ---------------------------------------------------------------------- */

function elm_add_settings_link( array $links ): array {
    $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=external-link-manager' ) ) . '">'
        . esc_html__( 'Настройки', 'external-link-manager' )
        . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . ELM_BASENAME, 'elm_add_settings_link' );

/* -------------------------------------------------------------------------
 * Регистрация страницы и настроек.
 * ---------------------------------------------------------------------- */

function elm_register_admin_menu(): void {
    add_options_page(
        __( 'External Link Manager', 'external-link-manager' ),
        __( 'Внешние ссылки', 'external-link-manager' ),
        'manage_options',
        'external-link-manager',
        'elm_render_settings_page'
    );
}
add_action( 'admin_menu', 'elm_register_admin_menu' );

function elm_register_plugin_settings(): void {
    register_setting(
        'elm_settings_group',
        'elm_excluded_domains',
        [
            'type'              => 'string',
            'sanitize_callback' => 'elm_sanitize_excluded_domains',
            'default'           => '',
        ]
    );
    register_setting(
        'elm_settings_group',
        'elm_target_blank',
        [
            'type'              => 'boolean',
            'sanitize_callback' => 'elm_sanitize_boolean',
            'default'           => 1,
        ]
    );
    register_setting(
        'elm_settings_group',
        'elm_nofollow',
        [
            'type'              => 'boolean',
            'sanitize_callback' => 'elm_sanitize_boolean',
            'default'           => 1,
        ]
    );
}
add_action( 'admin_init', 'elm_register_plugin_settings' );

/* -------------------------------------------------------------------------
 * Санитизация.
 * ---------------------------------------------------------------------- */

function elm_sanitize_boolean( $input ): int {
    return filter_var( $input, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
}

/**
 * Санитизация списка доменов.
 * Принимает массив из полей формы или строку; сохраняет строкой с \n.
 *
 * @param mixed $input Входные данные.
 * @return string
 */
function elm_sanitize_excluded_domains( $input ): string {
    if ( ! is_array( $input ) ) {
        $input = [ (string) $input ];
    }
    $cleaned = [];
    foreach ( $input as $item ) {
        if ( ! is_string( $item ) ) {
            continue;
        }
        $item = trim( $item );
        if ( '' !== $item ) {
            $cleaned[] = $item;
        }
    }
    return implode( "\n", array_unique( $cleaned ) );
}

/* -------------------------------------------------------------------------
 * Страница настроек с вкладками.
 * ---------------------------------------------------------------------- */

function elm_render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $active_tab = 'general';
    if ( isset( $_GET['tab'] ) && 'excluded' === $_GET['tab'] ) {
        $active_tab = 'excluded';
    }

    $excluded_raw = (string) get_option( 'elm_excluded_domains', '' );
    $excluded_arr = array_values(
        array_filter( array_map( 'trim', explode( "\n", $excluded_raw ) ) )
    );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'External Link Manager', 'external-link-manager' ); ?></h1>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=external-link-manager&tab=general' ) ); ?>"
               class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Общие', 'external-link-manager' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=external-link-manager&tab=excluded' ) ); ?>"
               class="nav-tab <?php echo 'excluded' === $active_tab ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Исключённые домены', 'external-link-manager' ); ?>
            </a>
        </h2>

        <form action="options.php" method="post">
            <?php settings_fields( 'elm_settings_group' ); ?>

            <!-- Вкладка «Общие» -->
            <div class="elm-tab-pane" <?php echo 'general' !== $active_tab ? 'style="display:none;"' : ''; ?>>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Открывать в новом окне', 'external-link-manager' ); ?></th>
                        <td>
                            <input type="hidden" name="elm_target_blank" value="0" />
                            <label>
                                <input type="checkbox" name="elm_target_blank" value="1" <?php checked( 1, get_option( 'elm_target_blank', 1 ) ); ?> />
                                <?php esc_html_e( 'Добавлять target="_blank" к внешним ссылкам', 'external-link-manager' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Добавлять rel="nofollow"', 'external-link-manager' ); ?></th>
                        <td>
                            <input type="hidden" name="elm_nofollow" value="0" />
                            <label>
                                <input type="checkbox" name="elm_nofollow" value="1" <?php checked( 1, get_option( 'elm_nofollow', 1 ) ); ?> />
                                <?php esc_html_e( 'Добавлять rel="nofollow" к внешним ссылкам', 'external-link-manager' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Вкладка «Исключённые домены» -->
            <div class="elm-tab-pane" <?php echo 'excluded' !== $active_tab ? 'style="display:none;"' : ''; ?>>
                <h2><?php esc_html_e( 'Исключённые домены', 'external-link-manager' ); ?></h2>
                <p><?php esc_html_e( 'Ссылки на указанные домены не будут изменяться.', 'external-link-manager' ); ?></p>

                <div id="elm-domains-list"></div>

                <p>
                    <button type="button" class="button" id="elm-add-domain">
                        + <?php esc_html_e( 'Добавить домен', 'external-link-manager' ); ?>
                    </button>
                </p>

                <!-- Страховка: если пользователь удалит все строки, поле всё равно отправится. -->
                <input type="hidden" name="elm_excluded_domains[]" value="" />
            </div>

            <?php submit_button(); ?>
        </form>
    </div>

    <script>
    (function () {
        var list   = document.getElementById('elm-domains-list');
        var addBtn = document.getElementById('elm-add-domain');
        if (!list || !addBtn) { return; }

        var existing = <?php echo wp_json_encode( $excluded_arr ); ?>;

        function createRow(value) {
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;max-width:640px;';

            var input = document.createElement('input');
            input.type = 'text';
            input.name = 'elm_excluded_domains[]';
            input.value = value || '';
            input.className = 'regular-text';
            input.placeholder = 'example.com';
            input.style.flex = '1';

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button';
            remove.style.cssText = 'color:#b32d2e;min-width:36px;height:30px;padding:0;line-height:1;font-size:18px;';
            remove.setAttribute('aria-label', <?php echo wp_json_encode( __( 'Удалить', 'external-link-manager' ) ); ?>);
            remove.textContent = '\u00D7';
            remove.addEventListener('click', function () { row.remove(); });

            row.appendChild(input);
            row.appendChild(remove);
            return row;
        }

        if (existing.length === 0) {
            list.appendChild(createRow(''));
        } else {
            existing.forEach(function (v) { list.appendChild(createRow(v)); });
        }

        addBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var row = createRow('');
            list.appendChild(row);
            var input = row.querySelector('input');
            if (input) { input.focus(); }
        });
    })();
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * Инициализация класса обработки ссылок.
 * ---------------------------------------------------------------------- */

function elm_init(): void {
    if ( ! class_exists( 'ELM_External_Link_Manager' ) ) {
        return;
    }
    $plugin = new ELM_External_Link_Manager();
    $plugin->run();
}
add_action( 'plugins_loaded', 'elm_init' );

/* -------------------------------------------------------------------------
 * Проверка требований при активации.
 * ---------------------------------------------------------------------- */

function elm_activation_check(): void {
    if ( version_compare( PHP_VERSION, ELM_REQUIRES_PHP, '<' ) ) {
        deactivate_plugins( ELM_BASENAME );
        wp_die( sprintf( esc_html__( 'Для работы плагина требуется PHP %s+.', 'external-link-manager' ), ELM_REQUIRES_PHP ) );
    }
    if ( version_compare( get_bloginfo( 'version' ), ELM_REQUIRES_WP, '<' ) ) {
        deactivate_plugins( ELM_BASENAME );
        wp_die( sprintf( esc_html__( 'Для работы плагина требуется WordPress %s+.', 'external-link-manager' ), ELM_REQUIRES_WP ) );
    }
}
register_activation_hook( __FILE__, 'elm_activation_check' );