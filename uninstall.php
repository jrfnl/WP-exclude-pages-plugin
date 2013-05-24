<?php

/**
 * Exclude Pages from Navigation Uninstall Functions
 *
 * Code used when the plugin is removed (not just deactivated but actively deleted through the WordPress Admin).
 *
 * @package Exclude Pages from Navigation
 * @subpackage Uninstall
 * @since 2.0
 *
 * @author Juliette Reinders Folmer
 */


if( !defined( 'ABSPATH' ) && !defined( 'WP_UNINSTALL_PLUGIN' ) )
    exit();
 
delete_option( 'ep_exclude_pages' );