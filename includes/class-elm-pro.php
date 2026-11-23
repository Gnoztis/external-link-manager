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

    /** Transient holding the cached PRO user list. */
    private const CACHE_KEY = 'elm_pro_users';

    /** The list is cached for five minutes; the tab has a refresh button. */
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
     * Registers the admin hooks.
     */
    public static function register_hooks(): void {
        add_action( 'admin_init', [ self::class, 'handle_refresh' ] );
    }

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
     * The Membership service, or null when the theme API is unavailable.
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

        if ( ! self::is_theme_active() ) {
            return null;
        }

        $class = '\WPShop\WPCommunity\Membership';

        if ( ! class_exists( $class ) || ! method_exists( $class, 'is_member' ) ) {
            return null;
        }
        if ( ! function_exists( 'theme_container' ) ) {
            return null;
        }

        try {
            $container = theme_container();
            if ( ! is_object( $container ) || ! method_exists( $container, 'get' ) ) {
                return null;
            }

            $membership = $container->get( $class );
            if ( is_object( $membership ) && $membership instanceof $class ) {
                self::$membership        = $membership;
                self::$membership_source = 'membership';
            }
        } catch ( \Throwable $error ) {
            self::$membership        = null;
            self::$membership_source = 'error';
        }

        return self::$membership;
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
                $is     = (bool) $membership->is_member( $user_id );
                $source = 'membership';
            } catch ( \Throwable $error ) {
                $is     = false;
                $source = 'error';
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
     * @param int $user_id User ID.
     * @return string membership, missing, error, filter, or an empty string.
     */
    public static function last_source( int $user_id ): string {
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
            return $cached;
        }

        $result = [
            'users'     => [],
            'truncated' => false,
            'total'     => 0,
            'api'       => false,
        ];

        if ( ! self::is_theme_active() || null === self::membership() ) {
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
     * Handles the "Обновить" button on the PRO tab.
     */
    public static function handle_refresh(): void {
        if ( ! isset( $_GET['elm_pro_refresh'] ) ) {
            return;
        }
        if ( ! self::can_view_tab() ) {
            return;
        }

        check_admin_referer( 'elm_pro_refresh' );
        self::flush_cache();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'external-link-manager',
                    'tab'  => 'pro',
                ],
                admin_url( 'options-general.php' )
            )
        );
        exit;
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

        $refresh_url = wp_nonce_url(
            add_query_arg(
                [
                    'page'             => 'external-link-manager',
                    'tab'              => 'pro',
                    'elm_pro_refresh'  => '1',
                ],
                admin_url( 'options-general.php' )
            ),
            'elm_pro_refresh'
        );
        ?>
        <h2><?php esc_html_e( 'PRO-пользователи', 'external-link-manager' ); ?></h2>
        <p><?php esc_html_e( 'Пользователи с активной подпиской PRO. Ссылки в их статьях не получают rel="nofollow".', 'external-link-manager' ); ?></p>
        <p>
            <a class="button" href="<?php echo esc_url( $refresh_url ); ?>">
                <?php esc_html_e( 'Обновить', 'external-link-manager' ); ?>
            </a>
        </p>

        <?php if ( ! $data['api'] ) : ?>
            <p><?php esc_html_e( 'Список недоступен: тема не предоставила Membership::is_member().', 'external-link-manager' ); ?></p>
            <?php return; ?>
        <?php endif; ?>

        <?php if ( $data['truncated'] ) : ?>
            <p><?php esc_html_e( 'Показаны первые 2000 пользователей; на этом сайте пользователей больше.', 'external-link-manager' ); ?></p>
        <?php endif; ?>

        <?php if ( [] === $rows ) : ?>
            <p><?php esc_html_e( 'Активных PRO-пользователей нет.', 'external-link-manager' ); ?></p>
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
        <?php
    }
}
