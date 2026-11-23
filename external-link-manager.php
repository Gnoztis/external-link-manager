<?php
/**
 * Plugin Name: External Link Manager
 * Description: Добавляет rel="nofollow" и target="_blank" для внешних ссылок. Есть возможность указать домены-исключения.
 * Version:     2.4.3
 * Author:      Gnostis
 * Author URI:  https://github.com/Gnoztis
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:  https://github.com/Gnoztis/external-link-manager
 * Text Domain: external-link-manager
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Tested up to: 7.1
 *
 * @package ExternalLinkManager
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ELM_VERSION', '2.4.3' );
define( 'ELM_PLUGIN_FILE', __FILE__ );
define( 'ELM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ELM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ELM_BASENAME', plugin_basename( __FILE__ ) );
define( 'ELM_REQUIRES_PHP', '8.0' );
define( 'ELM_REQUIRES_WP', '6.4' );

require_once ELM_PLUGIN_DIR . 'includes/class-elm-external-link-manager.php';
require_once ELM_PLUGIN_DIR . 'includes/class-elm-github-updater.php';

( new ELM_GitHub_Updater() )->register_hooks();

/* -------------------------------------------------------------------------
 * Settings link in the plugin list.
 * ---------------------------------------------------------------------- */

/**
 * Adds the settings link to the plugin row.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function elm_add_settings_link( array $links ): array {
    $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=external-link-manager' ) ) . '">'
        . esc_html__( 'Настройки', 'external-link-manager' )
        . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . ELM_BASENAME, 'elm_add_settings_link' );

/* -------------------------------------------------------------------------
 * Settings page and registered options.
 * ---------------------------------------------------------------------- */

/**
 * Registers the settings page.
 */
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

/**
 * Registers the two settings and the exclusion list.
 */
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
 * Sanitisation.
 * ---------------------------------------------------------------------- */

/**
 * Sanitises a checkbox value to 1 or 0.
 *
 * @param mixed $input Submitted value.
 * @return int
 */
function elm_sanitize_boolean( $input ): int {
    return filter_var( $input, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
}

/**
 * Sanitises the exclusion list.
 *
 * Accepts an array from the form fields or a single string. Each value may
 * hold several entries separated by commas, semicolons or newlines; every
 * entry is reduced to a bare host so "https://WWW.Example.com/page" covers
 * "example.com" and its subdomains. Empty entries are dropped.
 *
 * @param mixed $input Submitted value.
 * @return string Newline-separated normalised hosts.
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
        if ( '' === $item ) {
            continue;
        }

        $pieces = preg_split( '/[\r\n,;]+/', $item );
        if ( ! is_array( $pieces ) ) {
            continue;
        }

        foreach ( $pieces as $piece ) {
            $piece  = trim( (string) $piece );
            $domain = class_exists( 'ELM_External_Link_Manager' )
                ? ELM_External_Link_Manager::normalize_domain( $piece )
                : strtolower( $piece );

            if ( '' !== $domain ) {
                $cleaned[ $domain ] = true;
            }
        }
    }

    return implode( "\n", array_keys( $cleaned ) );
}

/* -------------------------------------------------------------------------
 * Settings page with tabs.
 * ---------------------------------------------------------------------- */

/**
 * Renders the settings page.
 */
function elm_render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $active_tab = 'general';
    if ( isset( $_GET['tab'] ) ) {
        $requested_tab = sanitize_key( wp_unslash( (string) $_GET['tab'] ) );
        if ( 'excluded' === $requested_tab ) {
            $active_tab = 'excluded';
        }
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
               class="nav-tab <?php echo esc_attr( 'general' === $active_tab ? 'nav-tab-active' : '' ); ?>">
                <?php esc_html_e( 'Общие', 'external-link-manager' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=external-link-manager&tab=excluded' ) ); ?>"
               class="nav-tab <?php echo esc_attr( 'excluded' === $active_tab ? 'nav-tab-active' : '' ); ?>">
                <?php esc_html_e( 'Исключённые домены', 'external-link-manager' ); ?>
            </a>
        </h2>

        <form action="options.php" method="post">
            <?php settings_fields( 'elm_settings_group' ); ?>

            <!-- General tab -->
            <div class="elm-tab-pane"<?php if ( 'general' !== $active_tab ) : ?> style="display:none;"<?php endif; ?>>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Открывать в новом окне', 'external-link-manager' ); ?></th>
                        <td>
                            <input type="hidden" name="elm_target_blank" value="0" />
                            <label>
                                <input type="checkbox" name="elm_target_blank" value="1" <?php checked( 1, (int) get_option( 'elm_target_blank', 1 ) ); ?> />
                                <?php esc_html_e( 'Добавлять target="_blank" к внешним ссылкам', 'external-link-manager' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Добавлять rel="nofollow"', 'external-link-manager' ); ?></th>
                        <td>
                            <input type="hidden" name="elm_nofollow" value="0" />
                            <label>
                                <input type="checkbox" name="elm_nofollow" value="1" <?php checked( 1, (int) get_option( 'elm_nofollow', 1 ) ); ?> />
                                <?php esc_html_e( 'Добавлять rel="nofollow" к внешним ссылкам', 'external-link-manager' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Excluded domains tab -->
            <div class="elm-tab-pane"<?php if ( 'excluded' !== $active_tab ) : ?> style="display:none;"<?php endif; ?>>
                <h2><?php esc_html_e( 'Исключённые домены', 'external-link-manager' ); ?></h2>
                <p><?php esc_html_e( 'Ссылки на указанные домены и их поддомены не будут изменяться. Начало «www.» игнорируется: example.com покрывает www.example.com и sub.example.com.', 'external-link-manager' ); ?></p>

                <div id="elm-domains-list"></div>

                <p>
                    <button type="button" class="button" id="elm-add-domain">
                        + <?php esc_html_e( 'Добавить домен', 'external-link-manager' ); ?>
                    </button>
                </p>

                <!-- Fallback: the field is submitted even when every row was removed. -->
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
 * Link processing.
 * ---------------------------------------------------------------------- */

/**
 * Starts the link processor.
 */
function elm_init(): void {
    if ( ! class_exists( 'ELM_External_Link_Manager' ) ) {
        return;
    }
    $plugin = new ELM_External_Link_Manager();
    $plugin->run();
}
add_action( 'plugins_loaded', 'elm_init' );

/* -------------------------------------------------------------------------
 * Requirements check.
 * ---------------------------------------------------------------------- */

/**
 * Explains why the current stack is not supported.
 *
 * The message names both the required and the running version, so the admin
 * can see why activation failed on this particular site.
 *
 * @return string Empty when every requirement is met.
 */
function elm_requirements_error(): string {
    if ( version_compare( PHP_VERSION, ELM_REQUIRES_PHP, '<' ) ) {
        return sprintf(
            /* translators: 1: required PHP version, 2: running PHP version. */
            __( 'Для работы плагина External Link Manager требуется PHP %1$s или новее. Сейчас установлен PHP %2$s.', 'external-link-manager' ),
            ELM_REQUIRES_PHP,
            PHP_VERSION
        );
    }

    $wp_version = (string) get_bloginfo( 'version' );
    if ( version_compare( $wp_version, ELM_REQUIRES_WP, '<' ) ) {
        return sprintf(
            /* translators: 1: required WordPress version, 2: running WordPress version. */
            __( 'Для работы плагина External Link Manager требуется WordPress %1$s или новее. Сейчас установлен WordPress %2$s.', 'external-link-manager' ),
            ELM_REQUIRES_WP,
            $wp_version
        );
    }

    return '';
}

/**
 * Stops activation when the stack is not supported.
 *
 * deactivate_plugins() must not be called here: at this point the plugin is
 * not stored in the active_plugins option yet, so the deactivation would be
 * lost and only wp_die() would abort the request. A plugin that is already
 * active (for example after the server was downgraded) is handled by
 * elm_deactivate_on_unsupported_stack() on admin_init.
 */
function elm_activation_check(): void {
    $error = elm_requirements_error();
    if ( '' === $error ) {
        return;
    }

    wp_die(
        esc_html( $error ),
        esc_html__( 'Плагин не активирован', 'external-link-manager' ),
        [ 'back_link' => true ]
    );
}
register_activation_hook( __FILE__, 'elm_activation_check' );

/**
 * Deactivates the plugin when an active site no longer meets the requirements.
 *
 * Runs on every admin request as a safety net: WordPress checks the "Requires
 * PHP" and "Requires at least" headers itself during activation, but a
 * downgrade afterwards bypasses that check.
 */
function elm_deactivate_on_unsupported_stack(): void {
    if ( '' === elm_requirements_error() ) {
        return;
    }

    if ( ! function_exists( 'deactivate_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    deactivate_plugins( ELM_BASENAME );
    add_action( 'admin_notices', 'elm_unsupported_stack_notice' );
}
add_action( 'admin_init', 'elm_deactivate_on_unsupported_stack' );

/**
 * Shows the reason the plugin deactivated itself.
 */
function elm_unsupported_stack_notice(): void {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    $error = elm_requirements_error();
    if ( '' === $error ) {
        return;
    }

    echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
}
