<?php
/**
 * External link processing.
 *
 * @package ExternalLinkManager
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds target/rel attributes to external links in rendered content.
 *
 * A link is external when its host differs from the site host after
 * normalisation. Excluded domains cover the configured host itself and every
 * subdomain of it, but never a look-alike such as "notexample.com".
 */
final class ELM_External_Link_Manager {

    /**
     * Option holding the newline-separated domain exclusion list.
     *
     * @var string
     */
    private string $excluded_domains_option = 'elm_excluded_domains';

    /**
     * Option: open external links in a new tab.
     *
     * @var string
     */
    private string $target_blank_option = 'elm_target_blank';

    /**
     * Option: add rel="nofollow" to external links.
     *
     * @var string
     */
    private string $nofollow_option = 'elm_nofollow';

    /**
     * Registers the content filters.
     */
    public function run(): void {
        $this->add_content_filters();
    }

    /**
     * Hooks into the filters that render user-visible HTML.
     *
     * Every hook here runs during rendering only: stored post content,
     * comment text and term descriptions are not modified.
     */
    private function add_content_filters(): void {
        $filters = [
            'the_content',
            'the_excerpt',
            'comment_text',
            'widget_text_content',
            'term_description',
        ];

        foreach ( $filters as $filter ) {
            add_filter( $filter, [ $this, 'process_links' ], 999 );
        }
    }

    /**
     * Processes every external link in a piece of rendered content.
     *
     * The parameter is deliberately untyped: other plugins may pass a
     * non-string value through the same filter, and such a value must be
     * returned unchanged instead of raising a TypeError.
     *
     * @param mixed $content Rendered content.
     * @return mixed
     */
    public function process_links( $content ) {
        if ( ! is_string( $content ) || '' === $content || false === strpos( $content, '<a ' ) ) {
            return $content;
        }

        // The HTML API appeared in WordPress 6.2. On an older core the
        // content is returned untouched instead of breaking the page.
        if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
            return $content;
        }

        $home_host = self::normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        if ( '' === $home_host ) {
            return $content;
        }

        $excluded     = $this->get_excluded_domains();
        $open_new_tab = (bool) get_option( $this->target_blank_option, 1 );
        $add_nofollow = (bool) get_option( $this->nofollow_option, 1 );

        // A PRO author's posts keep target/noopener (security) but not
        // nofollow (SEO). The check is per post author and runs at render
        // time, so an expired subscription restores nofollow by itself.
        $suppress_nofollow = class_exists( 'ELM_Pro' ) && ELM_Pro::should_suppress_nofollow();

        $processor = new WP_HTML_Tag_Processor( $content );

        while ( $processor->next_tag( 'a' ) ) {
            $href = $processor->get_attribute( 'href' );
            if ( ! is_string( $href ) || '' === $href || '#' === $href[0] ) {
                continue;
            }

            $link_host = self::normalize_host( (string) wp_parse_url( $href, PHP_URL_HOST ) );

            // Relative URLs, anchors, mailto:, tel: and javascript: have
            // no host and are left alone.
            if ( '' === $link_host || $link_host === $home_host ) {
                continue;
            }

            if ( $this->is_excluded_domain( $link_host, $excluded ) ) {
                continue;
            }

            $existing_target = $processor->get_attribute( 'target' );
            $opens_new_tab   = $open_new_tab
                || ( is_string( $existing_target ) && '_blank' === strtolower( trim( $existing_target ) ) );

            if ( $open_new_tab ) {
                $processor->set_attribute( 'target', '_blank' );
            }

            $rel_tokens = [];

            if ( $add_nofollow && ! $suppress_nofollow ) {
                $rel_tokens[] = 'nofollow';
            }

            // noopener is required for every link that opens a new tab,
            // including one authored by hand: without it the target page can
            // reach back through window.opener. It is added even when the
            // nofollow option is off.
            if ( $opens_new_tab ) {
                $rel_tokens[] = 'noopener';
            }

            if ( [] !== $rel_tokens ) {
                $this->add_rel_tokens( $processor, $rel_tokens );
            }
        }

        return $processor->get_updated_html();
    }

    /**
     * Adds tokens to the rel attribute without dropping or duplicating any.
     *
     * An existing rel is preserved; tokens are compared case-insensitively
     * because rel keywords are ASCII case-insensitive, and the attribute is
     * rebuilt from non-empty tokens so repeated spaces do not survive.
     *
     * @param WP_HTML_Tag_Processor $processor Tag processor positioned on a tag.
     * @param string[]              $tokens    Tokens to add.
     */
    private function add_rel_tokens( WP_HTML_Tag_Processor $processor, array $tokens ): void {
        $current = $processor->get_attribute( 'rel' );
        $rels    = [];

        if ( is_string( $current ) && '' !== trim( $current ) ) {
            $parts = preg_split( '/\s+/', trim( $current ), -1, PREG_SPLIT_NO_EMPTY );
            if ( is_array( $parts ) ) {
                $rels = $parts;
            }
        }

        $lower     = array_map( 'strtolower', $rels );
        $appending = false;

        foreach ( $tokens as $token ) {
            if ( ! in_array( strtolower( $token ), $lower, true ) ) {
                $rels[]    = $token;
                $lower[]   = strtolower( $token );
                $appending = true;
            }
        }

        if ( $appending ) {
            $processor->set_attribute( 'rel', implode( ' ', $rels ) );
        }
    }

    /**
     * Whether a host matches the exclusion list.
     *
     * A list entry covers the host itself and all of its subdomains:
     * "example.com" excludes "sub.example.com" but not "notexample.com".
     *
     * @param string   $host     Normalised host of the link.
     * @param string[] $excluded Normalised exclusion list.
     * @return bool
     */
    private function is_excluded_domain( string $host, array $excluded ): bool {
        if ( '' === $host ) {
            return false;
        }

        foreach ( $excluded as $domain ) {
            if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads and normalises the exclusion list option.
     *
     * The option is stored as newline-separated text, but users paste commas,
     * semicolons, full URLs and mixed case as well.
     *
     * @return string[] Normalised unique hosts.
     */
    private function get_excluded_domains(): array {
        $excluded = get_option( $this->excluded_domains_option, '' );

        if ( ! is_string( $excluded ) || '' === trim( $excluded ) ) {
            return [];
        }

        $entries = preg_split( '/[\r\n,;]+/', $excluded );
        if ( ! is_array( $entries ) ) {
            return [];
        }

        $domains = [];

        foreach ( $entries as $entry ) {
            $domain = self::normalize_domain( (string) $entry );
            if ( '' !== $domain ) {
                $domains[ $domain ] = true;
            }
        }

        return array_keys( $domains );
    }

    /**
     * Normalises one exclusion entry to a bare host.
     *
     * Used both when saving the option and when reading it, so a value saved
     * by an older version of the plugin still matches. Accepts a bare host,
     * "host:port" or a full URL; paths, schemes, "www.", case and the trailing
     * root dot are discarded.
     *
     * @param string $entry Raw exclusion entry.
     * @return string Normalised host, empty when nothing usable is left.
     */
    public static function normalize_domain( string $entry ): string {
        $entry = trim( $entry );
        if ( '' === $entry ) {
            return '';
        }

        $candidate = $entry;

        // wp_parse_url() needs a scheme or a protocol-relative prefix to
        // treat the value as a URL; without one it may read "host:port" as
        // a scheme.
        if ( false === strpos( $candidate, '://' ) && 0 !== strpos( $candidate, '//' ) ) {
            $candidate = '//' . $candidate;
        }

        $host = wp_parse_url( $candidate, PHP_URL_HOST );

        if ( ! is_string( $host ) || '' === $host ) {
            // Last resort for a malformed entry: strip the scheme and
            // everything from the first slash or colon.
            $host = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $entry );
            $host = (string) preg_replace( '#[/:].*$#', '', $host );
        }

        return self::normalize_host( $host );
    }

    /**
     * Normalises a host for comparison.
     *
     * Case, the trailing root dot, a leading "www." and IDN encoding are not
     * meaningful differences between hosts. The port is not part of the host
     * returned by wp_parse_url() and therefore never reaches this method.
     *
     * @param string $host Raw host.
     * @return string Normalised host, empty when the input has no host.
     */
    public static function normalize_host( string $host ): string {
        $host = strtolower( trim( $host ) );

        // "example.com." and "example.com" are the same DNS name.
        $host = rtrim( $host, '.' );

        if ( '' === $host ) {
            return '';
        }

        // IDN hosts arrive as UTF-8 from wp_parse_url(). Comparisons have to
        // happen in one form: the ASCII (punycode) one, when intl is present.
        if ( function_exists( 'idn_to_ascii' ) ) {
            $ascii = idn_to_ascii( $host );
            if ( is_string( $ascii ) && '' !== $ascii ) {
                $host = strtolower( $ascii );
            }
        }

        // Most sites redirect between the www and the bare host, so both
        // mean the same site for the purpose of this plugin.
        if ( str_starts_with( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }

        return $host;
    }
}
