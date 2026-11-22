<?php
/**
 * GitHub updater for External Link Manager.
 *
 * @package ExternalLinkManager
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Offers plugin updates from GitHub through the native WordPress mechanism.
 *
 * The source of truth is the highest version between the latest GitHub
 * Release and the newest vX.Y.Z tag: WordPress shows the new version on the
 * "Updates" screen and installs it from the package of the winning source. No
 * third-party libraries and no write requests to the repository.
 */
final class ELM_GitHub_Updater {

    /** GitHub repository the updates come from. */
    private const REPO = 'Gnoztis/external-link-manager';

    /**
     * Plugin slug in the repository and the archive directory name on GitHub.
     * Never used as the update transient key: see basename().
     */
    private const SLUG = 'external-link-manager';

    /** Transient holding the result of the last successful check. */
    private const TRANSIENT = 'elm_github_release';

    /** Transient holding the failure marker of the last check. */
    private const ERROR_TRANSIENT = 'elm_github_release_error';

    /** A successful check is cached for six hours. */
    private const CACHE_TTL = 21600;

    /** A failed check is retried after fifteen minutes. */
    private const ERROR_TTL = 900;

    /**
     * Version headers of the main plugin file, read once per request.
     *
     * @var array{requires:string,requires_php:string,tested:string}|null
     */
    private static ?array $header = null;

    /**
     * Registers the update hooks.
     */
    public function register_hooks(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'rename_source' ], 10, 4 );
        add_action( 'upgrader_process_complete', [ $this, 'after_upgrade' ], 10, 2 );
        add_action( 'load-plugins.php', [ $this, 'maybe_force_check' ] );
        add_filter( 'plugin_action_links_' . self::basename(), [ $this, 'action_links' ] );
    }

    /**
     * Basename of the installed main plugin file, computed at runtime.
     *
     * The folder on the site can have any name (a GitHub zipball unpacks into
     * "Gnoztis-external-link-manager-<sha>", a manual upload can be renamed).
     * WordPress keys update_plugins->response by this basename and counts
     * every entry for the admin badge, but lists only entries whose key
     * matches a plugin file from get_plugins(). A hard-coded key therefore
     * produces an update badge with an empty update list.
     *
     * @return string
     */
    public static function basename(): string {
        $basename = plugin_basename( ELM_PLUGIN_FILE );

        if ( '' === $basename ) {
            $basename = self::SLUG . '/' . wp_basename( ELM_PLUGIN_FILE );
        }

        return $basename;
    }

    /**
     * Directory of the installed plugin, empty when the main file sits
     * directly in wp-content/plugins.
     *
     * @return string
     */
    private function installed_slug(): string {
        $dir = dirname( self::basename() );

        return ( '.' === $dir || '' === $dir ) ? self::SLUG : $dir;
    }

    /* ============================================================
     *  Updates
     * ============================================================ */

    /**
     * Adds the update entry to the update_plugins transient.
     *
     * The comparison is made against both the version constant and the
     * version in the header of the file on disk: inside the request that
     * replaces the files, core rewrites this transient while the constant
     * still holds the version loaded at the start of the request. Comparing
     * only with the constant would re-inject "2.4.0 -> 2.4.0" right after a
     * successful update, and the phantom entry would live for hours.
     *
     * @param mixed $transient Update transient.
     * @return mixed
     */
    public function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = [];
        }

        $basename  = self::basename();
        $installed = $this->installed_version();

        // Runs before the early returns: a dangling entry is a leftover of
        // the previous key and must disappear even when GitHub is
        // unreachable right now.
        $this->purge_stale_entries( $transient, $basename );

        // The entry offers what is already on disk: drop it, so a phantom
        // left by an earlier run disappears on this one.
        $this->purge_installed_entry( $transient, $basename, $installed );

        $release = $this->get_release();
        if ( null === $release ) {
            return $transient;
        }

        // Newer than the running code (constant) and than the files on
        // disk (header), otherwise there is nothing to install.
        if ( version_compare( $release['version'], ELM_VERSION, '<=' )
            || version_compare( $release['version'], $installed, '<=' ) ) {
            return $transient;
        }

        $transient->response[ $basename ] = (object) [
            'id'            => $basename,
            'slug'          => $this->installed_slug(),
            'plugin'        => $basename,
            'new_version'   => $release['version'],
            'url'           => 'https://github.com/' . self::REPO,
            'package'       => $release['package'],
            'tested'        => $release['tested'],
            'requires'      => $release['requires'],
            'requires_php'  => $release['requires_php'],
            'icons'         => [],
            'banners'       => [],
            'compatibility' => new stdClass(),
        ];

        if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
            unset( $transient->no_update[ $basename ] );
        }

        return $transient;
    }

    /**
     * Version of the main plugin file as it is on disk.
     *
     * Deliberately not cached in a static: the point is to see the files
     * after Plugin_Upgrader replaced them within the current request.
     * get_file_data() reads the first 8 KB of one file, so the cost is
     * negligible next to the transient write it guards.
     *
     * @return string
     */
    private function installed_version(): string {
        $data = function_exists( 'get_file_data' )
            ? get_file_data( ELM_PLUGIN_FILE, [ 'Version' => 'Version' ], 'plugin' )
            : [];

        $version = isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';

        return '' !== $version ? $version : ELM_VERSION;
    }

    /**
     * Removes the update entry when it offers what is already installed.
     *
     * Core compares the entry's new_version with the version from
     * get_plugins() and keeps showing the row until the transient is rebuilt,
     * so such an entry must not stay in response.
     *
     * @param mixed  $transient Update transient.
     * @param string $basename  Our key in the transient.
     * @param string $installed Version from the file on disk.
     */
    private function purge_installed_entry( $transient, string $basename, string $installed ): void {
        if ( ! isset( $transient->response[ $basename ] ) || ! is_object( $transient->response[ $basename ] ) ) {
            return;
        }

        $entry   = $transient->response[ $basename ];
        $offered = isset( $entry->new_version ) ? trim( (string) $entry->new_version ) : '';

        if ( '' === $offered || version_compare( $offered, $installed, '<=' ) ) {
            unset( $transient->response[ $basename ] );
            return;
        }

        // Not newer than the constant either: the same phantom seen from the
        // other side.
        if ( version_compare( $offered, ELM_VERSION, '<=' ) ) {
            unset( $transient->response[ $basename ] );
        }
    }

    /**
     * Drops update entries that point at our main file under a foreign key.
     *
     * Such entries are leftovers of a hard-coded basename or of a renamed
     * folder. They make the admin badge count an update that
     * get_plugin_updates() cannot list, because the key does not match any
     * installed plugin file.
     *
     * @param mixed  $transient Update transient.
     * @param string $basename  Our real basename, kept as is.
     */
    private function purge_stale_entries( $transient, string $basename ): void {
        $main = wp_basename( ELM_PLUGIN_FILE );

        foreach ( [ 'response', 'no_update' ] as $bucket ) {
            if ( ! isset( $transient->{$bucket} ) || ! is_array( $transient->{$bucket} ) ) {
                continue;
            }

            foreach ( $transient->{$bucket} as $key => $entry ) {
                if ( (string) $key === $basename || ! is_object( $entry ) ) {
                    continue;
                }
                if ( empty( $entry->plugin ) || wp_basename( (string) $entry->plugin ) !== $main ) {
                    continue;
                }

                unset( $transient->{$bucket}[ $key ] );
            }
        }
    }

    /**
     * Data for the "View details" modal.
     *
     * @param mixed  $result Existing result.
     * @param string $action API action.
     * @param mixed  $args   API arguments.
     * @return mixed
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
            return $result;
        }
        if ( $this->installed_slug() !== (string) $args->slug ) {
            return $result;
        }

        $release = $this->get_release();
        if ( null === $release ) {
            return $result;
        }

        $header = $this->header_data();

        return (object) [
            'name'          => 'External Link Manager',
            'slug'          => $this->installed_slug(),
            'version'       => $release['version'],
            'author'        => '<a href="https://github.com/Gnoztis">Gnoztis</a>',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => $header['requires'],
            'tested'        => $release['tested'],
            'requires_php'  => $header['requires_php'],
            'download_link' => $release['package'],
            'last_updated'  => $release['published_at'],
            'sections'      => [
                'description' => esc_html__( 'Добавляет внешним ссылкам target="_blank", rel="nofollow" и noopener. Домены-исключения настраиваются на странице «Настройки → Внешние ссылки».', 'external-link-manager' ),
                'changelog'   => '' !== $release['body']
                    ? wp_kses_post( wpautop( $release['body'] ) )
                    : esc_html__( 'Описание изменений в релизе не заполнено.', 'external-link-manager' ),
            ],
        ];
    }

    /**
     * Whether the current upgrade job is an update of this plugin.
     *
     * Single updates carry hook_extra['plugin'], bulk updates carry
     * hook_extra['plugins']; both are handled.
     *
     * @param mixed $hook_extra Upgrade context.
     * @return bool
     */
    private function is_our_update( $hook_extra ): bool {
        if ( ! is_array( $hook_extra ) ) {
            return false;
        }

        $basename = self::basename();

        if ( ! empty( $hook_extra['plugin'] ) && $basename === (string) $hook_extra['plugin'] ) {
            return true;
        }

        if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
            return in_array( $basename, $hook_extra['plugins'], true );
        }

        return false;
    }

    /**
     * Renames the unpacked GitHub archive to the folder the site uses.
     *
     * A GitHub archive unpacks into a directory like
     * "Gnoztis-external-link-manager-1a2b3c4". Without the rename WordPress
     * would install the update next to the original and the site would end
     * up with two copies of the plugin.
     *
     * @param string $source        Unpacked source directory.
     * @param string $remote_source Temporary working directory.
     * @param mixed  $upgrader      Upgrader instance.
     * @param array  $hook_extra    Upgrade context.
     * @return string
     */
    public function rename_source( $source, $remote_source, $upgrader, $hook_extra = [] ) {
        if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
            return $source;
        }
        if ( ! $this->is_our_update( $hook_extra ) ) {
            return $source;
        }
        if ( ! is_dir( $source ) ) {
            return $source;
        }
        if ( ! is_file( trailingslashit( $source ) . wp_basename( ELM_PLUGIN_FILE ) ) ) {
            // A bulk update lists every plugin in hook_extra, so the archive
            // of another plugin reaches this point as well. Its contents
            // decide whether it is ours.
            return $source;
        }

        $folder = $this->installed_slug();
        if ( '' === $folder ) {
            return $source;
        }

        $desired = trailingslashit( $remote_source ) . $folder;
        if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ( ! $wp_filesystem ) {
            return $source;
        }

        if ( $wp_filesystem->move( $source, $desired, true ) ) {
            return trailingslashit( $desired );
        }

        return $source;
    }

    /**
     * Resets the cached check after an upgrade.
     *
     * The release cache is part of what is reset: an answer cached before the
     * update can still carry the previous "tested"/"new version" pair, and
     * the next run has to read both from the repository again.
     *
     * @param mixed $upgrader   Upgrader instance.
     * @param array $hook_extra Upgrade context.
     */
    public function after_upgrade( $upgrader = null, $hook_extra = [] ): void {
        if ( ! $this->is_our_update( $hook_extra ) ) {
            return;
        }

        $this->flush();
    }

    /**
     * Adds the "Check for updates" link to the plugin row.
     *
     * @param string[] $links Existing action links.
     * @return string[]
     */
    public function action_links( $links ): array {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return (array) $links;
        }

        $url = wp_nonce_url(
            add_query_arg( 'elm-force-check', '1', admin_url( 'plugins.php' ) ),
            'elm_force_check'
        );

        $links               = (array) $links;
        $links['elm_check']  = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Проверить обновления', 'external-link-manager' ) . '</a>';

        return $links;
    }

    /**
     * Handles the "Check for updates" click on plugins.php.
     */
    public function maybe_force_check(): void {
        if ( ! isset( $_GET['elm-force-check'] ) ) {
            return;
        }
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }
        check_admin_referer( 'elm_force_check' );

        $this->flush();

        require_once ABSPATH . 'wp-admin/includes/update.php';
        if ( function_exists( 'wp_update_plugins' ) ) {
            wp_update_plugins();
        }

        wp_safe_redirect( admin_url( 'plugins.php' ) );
        exit;
    }

    /**
     * Drops every cached value of this class.
     */
    private function flush(): void {
        delete_transient( self::TRANSIENT );
        delete_transient( self::ERROR_TRANSIENT );
        delete_site_transient( 'update_plugins' );

        self::$header = null;
    }

    /* ============================================================
     *  GitHub API
     * ============================================================ */

    /**
     * Latest version data (6 hour cache, 15 minute error cache).
     *
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}|null
     */
    private function get_release(): ?array {
        $cached = get_transient( self::TRANSIENT );
        if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
            return $cached;
        }

        if ( get_transient( self::ERROR_TRANSIENT ) ) {
            return null;
        }

        $release = $this->fetch_release();

        if ( null === $release ) {
            set_transient( self::ERROR_TRANSIENT, 1, self::ERROR_TTL );
            return null;
        }

        set_transient( self::TRANSIENT, $release, self::CACHE_TTL );

        return $release;
    }

    /**
     * Latest version data: the highest version of both sources.
     *
     * Both sources are always queried (the winner is cached as a whole).
     * Taking the release and using the tag only when there is no release
     * hides a fresh tag behind an older GitHub Release: the repository keeps
     * release 1.6.3, the owner pushes tag 1.7.0, and the update is never
     * offered until a release is created by hand.
     *
     * Equal versions: the release wins, it carries the changelog.
     *
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}|null
     */
    private function fetch_release(): ?array {
        $release = $this->fetch_latest_release();
        $tag     = $this->fetch_latest_tag();

        if ( null === $release && null === $tag ) {
            return null;
        }
        if ( null === $release ) {
            return $tag;
        }
        if ( null === $tag ) {
            return $release;
        }

        return version_compare( $tag['version'], $release['version'], '>' ) ? $tag : $release;
    }

    /**
     * Latest GitHub Release.
     *
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}|null
     */
    private function fetch_latest_release(): ?array {
        $body = $this->api_get( sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO ) );
        if ( null === $body ) {
            return null;
        }

        $version = $this->normalize_version( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '' );
        if ( '' === $version ) {
            return null;
        }

        $package = $this->find_package( $body );
        if ( '' === $package ) {
            return null;
        }

        return $this->build_release(
            $version,
            $package,
            isset( $body['body'] ) ? (string) $body['body'] : '',
            isset( $body['published_at'] ) ? (string) $body['published_at'] : ''
        );
    }

    /**
     * Highest semver tag of the repository. Competes with the release in
     * fetch_release(); a tag without a GitHub Release is a normal case.
     *
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}|null
     */
    private function fetch_latest_tag(): ?array {
        $tags = $this->api_get(
            sprintf( 'https://api.github.com/repos/%s/tags?per_page=30', self::REPO )
        );

        if ( ! is_array( $tags ) ) {
            return null;
        }

        $best_version = '';
        $best_tag     = '';

        foreach ( $tags as $tag ) {
            if ( ! is_array( $tag ) || empty( $tag['name'] ) ) {
                continue;
            }
            $version = $this->normalize_version( (string) $tag['name'] );
            if ( '' === $version ) {
                continue;
            }
            if ( '' === $best_version || version_compare( $version, $best_version, '>' ) ) {
                $best_version = $version;
                $best_tag     = (string) $tag['name'];
            }
        }

        if ( '' === $best_version || '' === $best_tag ) {
            return null;
        }

        $package = $this->safe_package_url(
            sprintf(
                'https://api.github.com/repos/%s/zipball/%s',
                self::REPO,
                rawurlencode( $best_tag )
            )
        );

        if ( '' === $package ) {
            return null;
        }

        return $this->build_release( $best_version, $package, '', '' );
    }

    /**
     * Builds the cached release array.
     *
     * @param string $version      Normalised version.
     * @param string $package      Validated package URL.
     * @param string $body         Release description.
     * @param string $published_at Release date.
     * @return array{version:string,package:string,body:string,published_at:string,tested:string,requires:string,requires_php:string}
     */
    private function build_release( string $version, string $package, string $body, string $published_at ): array {
        $header = $this->header_data();

        return [
            'version'      => $version,
            'package'      => $package,
            'body'         => $body,
            'published_at' => $published_at,
            'tested'       => $this->tested_version( $header['tested'] ),
            'requires'     => $header['requires'],
            'requires_php' => $header['requires_php'],
        ];
    }

    /**
     * "Tested up to" turned into the value WordPress compares with.
     *
     * The header holds a branch claim ("7.1"), while the update screen
     * compares the stored value with the full running version ("7.1.5"):
     * version_compare( $update->tested, $cur_wp_version, '>=' ). A branch
     * claim is always lower than a patch release, so a bare "7.1" still
     * renders "Compatibility with WordPress 7.1.5: Not tested".
     *
     * api.wordpress.org resolves this by serving the patch release of the
     * declared branch: readmes say "Tested up to: 7.1" and the API answers
     * "7.1.5". The same expansion is done here, so the claim covers the
     * running patch release of the branch the author declared.
     *
     * @param string $tested Header value, e.g. "7.1"; empty when absent.
     * @return string Empty when the header is absent, otherwise a version.
     */
    private function tested_version( string $tested ): string {
        $tested = trim( $tested );
        if ( '' === $tested ) {
            return '';
        }

        $running = function_exists( 'wp_get_wp_version' ) ? (string) wp_get_wp_version() : '';
        if ( '' === $running && isset( $GLOBALS['wp_version'] ) ) {
            $running = (string) $GLOBALS['wp_version'];
        }
        $running = trim( (string) preg_replace( '/-.*/', '', $running ) );
        if ( '' === $running ) {
            return $tested;
        }

        $tested_parts  = explode( '.', $tested );
        $running_parts = explode( '.', $running );

        // Already as specific as the running version, or more so.
        if ( count( $tested_parts ) >= count( $running_parts ) ) {
            return $tested;
        }

        // Same branch: the claim covers the running patch release as well.
        if ( array_slice( $running_parts, 0, count( $tested_parts ) ) === $tested_parts ) {
            return $running;
        }

        return $tested;
    }

    /**
     * GET to the GitHub API with our User-Agent and an optional token.
     *
     * @param string $url API URL built from the repository constant.
     * @return array<string,mixed>|null
     */
    private function api_get( string $url ): ?array {
        $args = [
            'timeout'     => 5,
            'redirection' => 2,
            'headers'     => [
                'Accept'       => 'application/vnd.github+json',
                'User-Agent'   => 'External-Link-Manager/' . ELM_VERSION,
                'Content-Type' => 'application/json',
            ],
        ];

        $token = $this->token();
        if ( '' !== $token ) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        // The URL is built from the repository constant, not from user
        // input; scheme and host of the answer are validated separately.
        $response = wp_remote_get( $url, $args ); // phpcs:ignore -- URL built from the repository constant; scheme and host are validated below.
        if ( is_wp_error( $response ) ) {
            return null;
        }
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        return is_array( $body ) ? $body : null;
    }

    /**
     * Archive URL: the release zip asset first, then the GitHub zipball.
     *
     * @param array<string,mixed> $body Release payload.
     * @return string
     */
    private function find_package( array $body ): string {
        if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
            foreach ( $body['assets'] as $asset ) {
                if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
                    continue;
                }
                $name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
                if ( ! preg_match( '/\.zip$/i', $name ) ) {
                    continue;
                }
                $url = $this->safe_package_url( (string) $asset['browser_download_url'] );
                if ( '' !== $url ) {
                    return $url;
                }
            }
        }

        if ( ! empty( $body['zipball_url'] ) ) {
            return $this->safe_package_url( (string) $body['zipball_url'] );
        }

        return '';
    }

    /**
     * HTTPS only and GitHub hosts only.
     *
     * The package must not come from a field a third-party service could
     * tamper with.
     *
     * @param string $url Candidate URL.
     * @return string Validated URL, empty when rejected.
     */
    private function safe_package_url( string $url ): string {
        $url = esc_url_raw( trim( $url ) );
        if ( '' === $url ) {
            return '';
        }

        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        $host   = wp_parse_url( $url, PHP_URL_HOST );

        if ( 'https' !== strtolower( (string) $scheme ) ) {
            return '';
        }

        $allowed = [ 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' ];
        if ( ! in_array( strtolower( (string) $host ), $allowed, true ) ) {
            return '';
        }

        return $url;
    }

    /**
     * Strict semver normalisation: 1.7.0 and v1.7.0 are equivalent,
     * pre-releases and four-part versions are rejected.
     *
     * @param string $tag Tag name.
     * @return string Normalised version, empty when invalid.
     */
    private function normalize_version( string $tag ): string {
        $tag = trim( $tag );
        if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m ) ) {
            return '';
        }
        return $m[1];
    }

    /**
     * Optional GitHub token (private repository or API rate limit).
     *
     * @return string
     */
    private function token(): string {
        $token = defined( 'ELM_GITHUB_TOKEN' ) ? (string) ELM_GITHUB_TOKEN : '';
        $token = (string) apply_filters( 'elm_github_token', $token );

        return trim( $token );
    }

    /**
     * Version headers of the main plugin file.
     *
     * `Tested up to` and `Requires at least` are different things: the first
     * is the newest WordPress the plugin was checked against, the second is
     * the oldest it runs on. Feeding the minimum into `tested` makes the
     * update screen show "compatibility: unknown", so they are read
     * separately and `tested` stays empty when the header is absent.
     *
     * @return array{requires:string,requires_php:string,tested:string}
     */
    private function header_data(): array {
        if ( null === self::$header ) {
            $data = function_exists( 'get_file_data' )
                ? get_file_data(
                    ELM_PLUGIN_FILE,
                    [
                        'Requires at least' => 'Requires at least',
                        'Requires PHP'      => 'Requires PHP',
                        'Tested up to'      => 'Tested up to',
                    ],
                    'plugin'
                )
                : [];

            self::$header = [
                'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
                'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
                'tested'       => isset( $data['Tested up to'] ) ? (string) $data['Tested up to'] : '',
            ];
        }

        return self::$header;
    }
}
