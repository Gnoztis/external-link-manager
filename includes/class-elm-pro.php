<?php
/**
 * WPCommunity PRO integration.
 *
 * @package ExternalLinkManager
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Optional integration with the WPCommunity theme.
 *
 * The theme is the only supported source of the PRO status. Its public API is
 * Membership::is_member(); there is no filter for it, so every call is made
 * through a defensive adapter: theme and class are checked, container errors
 * are caught, and any failure means "not PRO" (fail closed).
 *
 * Nothing here reads the theme's user meta or options directly.
 */
final class ELM_Pro {

    /**
     * Theme version this integration was verified against.
     *
     * The Membership service is a private theme API; see README.
     */
    public const TESTED_THEME_VERSION = '1.6.0';

    /** Transient holding the cached PRO user list. */
    private const CACHE_KEY = 'elm_pro_users';

    /** The list is cached for five minutes and recalculated when the tab opens stale. */
    private const CACHE_TTL = 300;

    /** Users shown per page. */
    private const PER_PAGE = 20;

    /** Safety cap for the user scan on very large sites. */
    private const MAX_USERS = 2000;

    /**
     * Whether the theme check has run in this request.
     *
     * @var bool
     */
    private static bool $theme_checked = false;

    /**
     * Result of the theme check.
     *
     * @var bool
     */
    private static bool $theme_active = false;

    /**
     * Per-request PRO results, keyed by user ID.
     *
     * @var array<int,bool>
     */
    private static array $pro_cache = [];

    /**
     * Per-request origin of each PRO answer, for diagnostics.
     *
     * @var array<int,string>
     */
    private static array $source_cache = [];

    /**
     * Which Membership piece was missing: class, method, function, container, object.
     *
     * @var string
     */
    private static string $missing_reason = '';

    /**
     * Exception message from the theme container, if it threw.
     *
     * @var string
     */
    private static string $error_text = '';

    /**
     * Container identifier used in the last lookup.
     *
     * @var string
     */
    private static string $container_id = '';

    /**
     * Membership service from the theme container, if available.
     *
     * @var object|null
     */
    private static ?object $membership = null;

    /**
     * Whether the container lookup has run in this request.
     *
     * @var bool
     */
    private static bool $membership_loaded = false;

    /**
     * Where the last Membership lookup came from: membership, missing, error.
     *
     * Diagnostics only; it never changes the decision.
     *
     * @var string
     */
    private static string $membership_source = 'missing';

    /**
     * Forgets the per-request caches.
     *
     * Used by tests and after a subscription change within the same request.
     */
    public static function reset_cache(): void {
        self::$theme_checked    = false;
        self::$theme_active     = false;
        self::$pro_cache        = [];
        self::$source_cache     = [];
        self::$membership       = null;
        self::$membership_loaded = false;
        self::$membership_source = 'missing';
        self::$missing_reason    = '';
        self::$error_text        = '';
        self::$container_id      = '';
    }

    /**
     * Whether the active theme is WPCommunity (child themes included).
     */
    public static function is_theme_active(): bool {
        if ( self::$theme_checked ) {
            return self::$theme_active;
        }

        self::$theme_checked = true;
        self::$theme_active  = function_exists( 'get_template' ) && 'wpcommunity' === get_template();

        return self::$theme_active;
    }

    /**
     * Version of the parent theme (get_template() is the parent slug).
     *
     * @return string Empty when WordPress cannot report it.
     */
    public static function theme_version(): string {
        if ( ! self::is_theme_active() || ! function_exists( 'wp_get_theme' ) ) {
            return '';
        }

        try {
            $theme = wp_get_theme( get_template() );

            return is_object( $theme ) && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Version' ) : '';
        } catch ( \Throwable $error ) {
            return '';
        }
    }

    /**
     * Whether the theme version differs from the one this integration was tested with.
     */
    public static function theme_version_mismatch(): bool {
        $version = self::theme_version();

        return '' !== $version && $version !== self::TESTED_THEME_VERSION;
    }

    /**
     * User-facing warning for an untested theme version.
     */
    public static function theme_version_note(): string {
        if ( ! self::theme_version_mismatch() ) {
            return '';
        }

        return sprintf(
            /* translators: 1: detected theme version, 2: tested theme version. */
            __( 'Тема WPCommunity %1$s не проверялась с этой версией плагина (проверено с %2$s). Статус PRO может быть недоступен.', 'external-link-manager' ),
            self::theme_version(),
            self::TESTED_THEME_VERSION
        );
    }

    /**
     * The Membership service, or null when the theme API is unavailable.
     *
     * The theme function lives in the WPShop\WPCommunity namespace:
     * `\WPShop\WPCommunity\theme_container()`. Calling the unqualified
     * `theme_container()` from the plugin's global namespace always fails,
     * which is why the class was reported as missing before 2.5.1.
     *
     * Any container problem is treated as "no API": the caller then falls back
     * to "not PRO" and nofollow stays in place.
     *
     * @return object|null
     */
    private static function membership(): ?object {
        if ( self::$membership_loaded ) {
            return self::$membership;
        }

        self::$membership_loaded = true;
        self::$membership        = null;
        self::$membership_source = 'missing';
        self::$missing_reason    = '';
        self::$error_text        = '';
        self::$container_id      = '';

        if ( ! self::is_theme_active() ) {
            return null;
        }

        $class = '\WPShop\WPCommunity\Membership';
        // The theme's ServiceLocator matches identifiers exactly as registered:
        // Membership::class has no leading backslash. get() with the slashed
        // name throws UnknownIdentifierException, so the unslashed ID goes first.
        $id = ltrim( $class, '\\' );

        if ( ! class_exists( $class ) ) {
            self::$missing_reason = 'class';
            return null;
        }
        if ( ! method_exists( $class, 'is_member' ) ) {
            self::$missing_reason = 'method';
            return null;
        }

        $container_function = '\WPShop\WPCommunity\theme_container';
        if ( ! function_exists( $container_function ) && function_exists( 'theme_container' ) ) {
            // Older theme snapshots exposed the function globally; keep it as
            // a fallback without relying on it.
            $container_function = 'theme_container';
        }
        if ( ! function_exists( $container_function ) ) {
            self::$missing_reason = 'function';
            return null;
        }

        try {
            $container = $container_function();
            if ( ! is_object( $container ) || ! method_exists( $container, 'get' ) ) {
                self::$missing_reason = 'container';
                return null;
            }

            $candidates   = [ $id, $class ];
            $last_error   = null;
            $wrong_object = false;

            foreach ( $candidates as $candidate ) {
                self::$container_id = $candidate;

                if ( method_exists( $container, 'has' ) ) {
                    if ( ! $container->has( $candidate ) ) {
                        continue;
                    }

                    try {
                        $membership = $container->get( $candidate );
                    } catch ( \Throwable $error ) {
                        self::$membership_source = 'error';
                        self::$error_text        = $error->getMessage();
                        return null;
                    }
                } else {
                    try {
                        $membership = $container->get( $candidate );
                    } catch ( \Throwable $error ) {
                        $last_error = $error;
                        continue;
                    }
                }

                if ( is_object( $membership ) && $membership instanceof $class ) {
                    self::$membership        = $membership;
                    self::$membership_source = self::theme_version_mismatch() ? 'theme_version' : 'membership';
                    return $membership;
                }

                $wrong_object = true;
            }

            if ( null !== $last_error ) {
                if ( self::is_unknown_identifier( $last_error ) ) {
                    self::$membership_source = 'container_id';
                } else {
                    self::$membership_source = 'error';
                    self::$error_text        = $last_error->getMessage();
                }
            } elseif ( $wrong_object ) {
                self::$membership_source = 'missing';
                self::$missing_reason    = 'object';
            } else {
                self::$membership_source = 'container_id';
            }

            if ( '' === self::$missing_reason ) {
                self::$missing_reason = 'container_id';
            }
        } catch ( \Throwable $error ) {
            self::$membership        = null;
            self::$membership_source = 'error';
            self::$error_text        = $error->getMessage();
        }

        return self::$membership;
    }

    /**
     * Whether an exception means "this identifier is not in the container".
     *
     * Matches the theme's UnknownIdentifierException without requiring the
     * theme's exception class to be loaded.
     *
     * @param \Throwable $error Exception from the container.
     */
    private static function is_unknown_identifier( \Throwable $error ): bool {
        return false !== strpos( $error->getMessage(), ' is not defined.' )
            || false !== strpos( get_class( $error ), 'UnknownIdentifierException' );
    }

    /**
     * Diagnostics: container identifier used in the last lookup.
     */
    public static function last_container_id(): string {
        return self::$container_id;
    }

    /**
     * Diagnostics: where the last Membership answer came from.
     *
     * @return string membership, missing, error, filter, or an empty string.
     */
    public static function source(): string {
        return self::$membership_source;
    }

    /**
     * Specific explanation for a missing or broken theme API.
     *
     * Deliberately names the missing piece instead of claiming the theme does
     * not provide Membership::is_member(); the theme 1.6.0 does provide it.
     */
    public static function diagnostic_message(): string {
        if ( 'error' === self::$membership_source ) {
            $text = '' !== self::$error_text ? self::$error_text : __( 'без текста', 'external-link-manager' );

            return sprintf(
                /* translators: %s: exception message from the theme container. */
                __( 'Тема WPCommunity вернула ошибку при обращении к Membership: %s', 'external-link-manager' ),
                $text
            );
        }

        switch ( self::$missing_reason ) {
            case 'class':
                return __( 'Класс WPShop\\WPCommunity\\Membership не найден. Проверьте, что активна тема WPCommunity 1.6.0+ и её автозагрузчик доступен в админке.', 'external-link-manager' );
            case 'method':
                return __( 'В классе Membership нет метода is_member(). Версия темы несовместима с плагином.', 'external-link-manager' );
            case 'function':
                return __( 'Функция WPShop\\WPCommunity\\theme_container() не найдена. Тема загружена не полностью или это не WPCommunity.', 'external-link-manager' );
            case 'container':
                return __( 'Контейнер темы WPCommunity недоступен: theme_container() не вернул объект с методом get().', 'external-link-manager' );
            case 'container_id':
                return sprintf(
                    /* translators: %s: container identifier that was not found. */
                    __( 'Контейнер темы не знает идентификатор %s: тема зарегистрировала Membership под другим именем.', 'external-link-manager' ),
                    '' !== self::$container_id ? self::$container_id : __( '(пусто)', 'external-link-manager' )
                );
            case 'object':
                return __( 'Контейнер темы вернул не Membership, а другой объект. Проверьте версию темы WPCommunity.', 'external-link-manager' );
            default:
                return __( 'Не удалось определить причину: тема WPCommunity активна, но Membership недоступен.', 'external-link-manager' );
        }
    }

    /**
     * Whether a user has an active PRO subscription.
     *
     * Fail-closed: a missing theme API, a throwing container or a broken
     * filter callback all mean false, so nofollow is kept. The result is
     * cached for the request, which also guarantees the filter runs at most
     * once per user per request.
     *
     * Filter contract:
     *     apply_filters( 'elm_user_is_pro', bool $is_pro, int $user_id, string $source )
     *
     * $source is diagnostics only ('membership', 'missing', 'error', 'filter')
     * and never changes the decision. Exceptions thrown by a callback are
     * caught and treated as false.
     *
     * @param int $user_id User ID.
     */
    public static function is_pro_user( int $user_id ): bool {
        if ( $user_id <= 0 || ! self::is_theme_active() ) {
            return false;
        }
        if ( array_key_exists( $user_id, self::$pro_cache ) ) {
            return self::$pro_cache[ $user_id ];
        }

        $is         = false;
        $membership = self::membership();
        $source     = self::$membership_source;

        if ( null !== $membership ) {
            try {
                $is = (bool) $membership->is_member( $user_id );
                if ( 'theme_version' !== $source ) {
                    $source = 'membership';
                }
            } catch ( \Throwable $error ) {
                $is                       = false;
                $source                   = 'error';
                self::$membership_source  = 'error';
                self::$error_text         = $error->getMessage();
            }
        }

        $built_in     = $is;
        $filter_failed = false;

        try {
            $is = (bool) apply_filters( 'elm_user_is_pro', $is, $user_id, $source );
        } catch ( \Throwable $error ) {
            $is            = false;
            $source        = 'error';
            $filter_failed = true;
        }

        if ( ! $filter_failed && $is !== $built_in ) {
            $source = 'filter';
        }

        self::$pro_cache[ $user_id ]    = $is;
        self::$source_cache[ $user_id ] = $source;

        return $is;
    }

    /**
     * Diagnostics: where the cached answer for a user came from.
     *
     * With no user ID it returns the last Membership source used by the tab
     * (membership, missing or error).
     *
     * @param int $user_id Optional user ID.
     * @return string membership, missing, error, filter, or an empty string.
     */
    public static function last_source( int $user_id = 0 ): string {
        if ( $user_id <= 0 ) {
            return self::$membership_source;
        }

        return self::$source_cache[ $user_id ] ?? '';
    }

    /**
     * Formatted subscription end date for a user, or an empty string.
     *
     * Only the public Membership::get_expired_date() is used; the raw
     * `expired` meta is never read. Errors are treated as "unknown date".
     *
     * @param int $user_id User ID.
     */
    public static function get_user_expiry_date( int $user_id ): string {
        $membership = self::membership();

        if ( null === $membership || ! method_exists( $membership, 'get_expired_date' ) ) {
            return '';
        }

        try {
            $format = (string) get_option( 'date_format', 'd.m.Y' );
            return (string) $membership->get_expired_date( $user_id, '' !== $format ? $format : 'd.m.Y' );
        } catch ( \Throwable $error ) {
            self::$membership_source = 'error';
            self::$error_text        = $error->getMessage();

            return '';
        }
    }

    /**
     * Whether nofollow should be omitted for the content being rendered.
     *
     * WordPress has one author per post; the policy belongs to that author
     * (post_author), not to the viewer and not to whoever edited the post.
     * A missing or deleted author means the normal behaviour: nofollow stays.
     * Co-Authors Plus is not supported on purpose — without explicit support
     * a multi-author post keeps nofollow.
     *
     * Only the main post content and excerpt of a regular post are eligible.
     * The decision is made at render time, so an expired subscription
     * restores nofollow without any background job.
     */
    public static function should_suppress_nofollow(): bool {
        if ( ! self::is_theme_active() ) {
            return false;
        }
        if ( ! function_exists( 'current_filter' ) || ! in_array( current_filter(), [ 'the_content', 'the_excerpt' ], true ) ) {
            return false;
        }
        if ( ! function_exists( 'get_post' ) ) {
            return false;
        }

        $post = get_post();
        if ( ! is_object( $post ) || empty( $post->ID ) || ! isset( $post->post_type ) || 'post' !== $post->post_type ) {
            return false;
        }

        $author_id = isset( $post->post_author ) ? (int) $post->post_author : 0;
        if ( $author_id <= 0 ) {
            return false;
        }

        if ( function_exists( 'get_userdata' ) && false === get_userdata( $author_id ) ) {
            return false;
        }

        return self::is_pro_user( $author_id );
    }

    /**
     * Whether the PRO tab may be shown to the current admin.
     */
    public static function can_view_tab(): bool {
        return self::is_theme_active() && current_user_can( 'manage_options' );
    }

    /**
     * Active PRO users, ordered by display name.
     *
     * The cached list contains identity and expiry only; the published post
     * count is computed for the current page in render_tab(), so pagination
     * stays cheap and a page never depends on counts of other users.
     *
     * @return array{users:array<int,array{id:int,display_name:string,expiry:string}>,truncated:bool,total:int,api:bool}
     */
    public static function get_pro_users(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) && isset( $cached['users'] ) ) {
            // The transient normally expires after CACHE_TTL; the timestamp
            // also covers a stale value left by an older plugin version.
            if ( (int) ( $cached['at'] ?? 0 ) + self::CACHE_TTL > time() ) {
                return $cached;
            }
        }

        $result = [
            'users'      => [],
            'truncated'  => false,
            'total'      => 0,
            'api'        => false,
            'at'         => time(),
            'error_text' => '',
            'source'     => self::$membership_source,
        ];

        if ( ! self::is_theme_active() || null === self::membership() ) {
            $result['source'] = self::$membership_source;

            return $result;
        }

        $result['api'] = true;

        $users = get_users(
            [
                'fields'  => [ 'ID', 'display_name' ],
                'number'  => self::MAX_USERS + 1,
                'orderby' => 'ID',
                'order'   => 'ASC',
            ]
        );

        if ( ! is_array( $users ) ) {
            return $result;
        }

        $result['truncated'] = count( $users ) > self::MAX_USERS;
        if ( $result['truncated'] ) {
            $users = array_slice( $users, 0, self::MAX_USERS );
        }

        foreach ( $users as $user ) {
            $user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
            if ( $user_id <= 0 || ! self::is_pro_user( $user_id ) ) {
                continue;
            }

            $result['users'][] = [
                'id'           => $user_id,
                'display_name' => is_object( $user ) && isset( $user->display_name ) ? (string) $user->display_name : '',
                'expiry'       => self::get_user_expiry_date( $user_id ),
            ];
        }

        usort(
            $result['users'],
            static function ( array $left, array $right ): int {
                $by_name = strcasecmp( $left['display_name'], $right['display_name'] );

                return 0 !== $by_name ? $by_name : $left['id'] <=> $right['id'];
            }
        );

        $result['total'] = count( $result['users'] );
        $result['error_text'] = self::$error_text;
        $result['source']     = self::$membership_source;

        set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

        return $result;
    }

    /**
     * Drops the cached PRO user list.
     */
    public static function flush_cache(): void {
        delete_transient( self::CACHE_KEY );
    }

    /**
     * Renders the PRO tab contents.
     *
     * The caller is responsible for the tab wrapper; this method checks the
     * capability again so it cannot be reused against a lower-privileged page.
     */
    public static function render_tab(): void {
        if ( ! self::can_view_tab() ) {
            return;
        }

        $data  = self::get_pro_users();
        $users = $data['users'];
        $pages = max( 1, (int) ceil( count( $users ) / self::PER_PAGE ) );

        $page = 1;
        if ( isset( $_GET['elm_pro_page'] ) ) {
            $page = absint( wp_unslash( (string) $_GET['elm_pro_page'] ) );
            $page = max( 1, min( $pages, $page ) );
        }

        $rows = array_slice( $users, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

        $post_counts = [];
        foreach ( $rows as $row ) {
            $post_counts[ $row['id'] ] = (int) count_user_posts( $row['id'], 'post', true );
        }

        $source     = (string) ( $data['source'] ?? self::last_source() );
        $theme_note = self::theme_version_note();
        $diagnostic = '';
        if ( ! $data['api'] ) {
            $diagnostic = self::diagnostic_message();
        } elseif ( '' !== ( $data['error_text'] ?? '' ) ) {
            $diagnostic = sprintf(
                /* translators: %s: exception message from the theme container. */
                __( 'Тема WPCommunity вернула ошибку при обращении к Membership: %s', 'external-link-manager' ),
                (string) $data['error_text']
            );
        }
        ?>
        <p><?php esc_html_e( 'Пользователи с активной подпиской PRO. Ссылки в их статьях не получают rel="nofollow".', 'external-link-manager' ); ?></p>
        <?php if ( '' !== $theme_note ) : ?>
            <p><em><?php echo esc_html( $theme_note ); ?></em></p>
        <?php endif; ?>

        <?php if ( ! $data['api'] ) : ?>
            <p><?php echo esc_html( $diagnostic ); ?></p>
            <p class="description">
                <?php esc_html_e( 'Источник данных:', 'external-link-manager' ); ?>
                <code><?php echo esc_html( $source ); ?></code>
            </p>
            <?php return; ?>
        <?php endif; ?>

        <?php if ( $data['truncated'] ) : ?>
            <p><?php esc_html_e( 'Показаны первые 2000 пользователей; на этом сайте пользователей больше.', 'external-link-manager' ); ?></p>
        <?php endif; ?>

        <?php if ( [] === $rows ) : ?>
            <p><?php esc_html_e( 'Активных PRO-пользователей нет.', 'external-link-manager' ); ?></p>
            <?php if ( '' !== $diagnostic ) : ?>
                <p><?php echo esc_html( $diagnostic ); ?></p>
            <?php endif; ?>
            <p class="description">
                <?php esc_html_e( 'Источник данных:', 'external-link-manager' ); ?>
                <code><?php echo esc_html( $source ); ?></code>
            </p>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Пользователь', 'external-link-manager' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Подписка до', 'external-link-manager' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Записей', 'external-link-manager' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <?php
                    $expiry = '' !== $row['expiry'] ? $row['expiry'] : '—';
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( get_edit_user_link( $row['id'] ) ); ?>">
                                <?php echo esc_html( $row['display_name'] ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( $expiry ); ?></td>
                        <td><?php echo esc_html( (string) ( $post_counts[ $row['id'] ] ?? 0 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $pages > 1 ) : ?>
            <p class="elm-pro-pagination">
                <?php if ( $page > 1 ) : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'external-link-manager', 'tab' => 'pro', 'elm_pro_page' => $page - 1 ], admin_url( 'options-general.php' ) ) ); ?>">
                        <?php esc_html_e( '« Назад', 'external-link-manager' ); ?>
                    </a>
                <?php endif; ?>
                <span>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: current page, 2: total pages. */
                            __( 'Страница %1$d из %2$d', 'external-link-manager' ),
                            $page,
                            $pages
                        )
                    );
                    ?>
                </span>
                <?php if ( $page < $pages ) : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'external-link-manager', 'tab' => 'pro', 'elm_pro_page' => $page + 1 ], admin_url( 'options-general.php' ) ) ); ?>">
                        <?php esc_html_e( 'Вперёд »', 'external-link-manager' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ( '' !== $diagnostic ) : ?>
            <p><?php echo esc_html( $diagnostic ); ?></p>
        <?php endif; ?>

        <p class="description">
            <?php esc_html_e( 'Источник данных:', 'external-link-manager' ); ?>
            <code><?php echo esc_html( $source ); ?></code>
        </p>
        <?php
    }
}
