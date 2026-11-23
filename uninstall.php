<?php
/**
 * Removes the data External Link Manager stores in the database.
 *
 * Only the plugin's own options and its update-check transients are deleted.
 * The shared core transient (update_plugins) is left alone, because other
 * plugins keep their entries there. On multisite every site stores its own
 * copy, so each site is cleaned in turn.
 *
 * @package ExternalLinkManager
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! function_exists( 'elm_uninstall_site_data' ) ) {
    /**
     * Deletes the plugin data of the current site.
     *
     * Option and transient names are literals on purpose: uninstall.php runs
     * on its own, before the plugin code is loaded.
     */
    function elm_uninstall_site_data() {
        $options = [
            'elm_excluded_domains',
            'elm_target_blank',
            'elm_nofollow',
        ];

        foreach ( $options as $option ) {
            delete_option( $option );
        }

        // Update-check cache created by includes/class-elm-github-updater.php.
        delete_transient( 'elm_github_release' );
        delete_transient( 'elm_github_release_error' );

        // PRO tab cache created by includes/class-elm-pro.php.
        delete_transient( 'elm_pro_users' );
    }
}

if ( is_multisite() ) {
    $elm_sites = get_sites(
        [
            'fields' => 'ids',
            'number' => 0,
        ]
    );

    foreach ( (array) $elm_sites as $elm_site_id ) {
        if ( ! switch_to_blog( (int) $elm_site_id ) ) {
            continue;
        }

        elm_uninstall_site_data();
        restore_current_blog();
    }
} else {
    elm_uninstall_site_data();
}
