<?php
/*
Plugin Name: Exclude Pages from Navigation
Plugin URI: http://wordpress.org/extend/plugins/exclude-pages/
Description: Provides a checkbox on the editing page which you can check to exclude pages from the primary navigation. IMPORTANT NOTE: This will remove the pages from any "consumer" side page listings, which may not be limited to your page navigation listings.
Version: 2.0.beta.2
Author: Simon Wheatley
Author URI: http://simonwheatley.co.uk/wordpress/
Contributor: Juliette Reinders Folmer
Contributor URI: http://adviesenzo.nl/
Contributor: Will Earnhardt
Contributor URI: http://twitter.com/earnjam
Text Domain: exclude-pages
Domain Path: /locale/

Copyright 2007-2013 Simon Wheatley, Juliette Reinders Folmer, Will Earnhardt

This script is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This script is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

/**
 * @todo: rework to class
 * @todo: change ctrl-check to proper nonce-check
 */

// @todo Note: until 2.0.0 is released, the version number also needs to be changed in the upgrade routine
define('EP_VERSION', '2.0.beta.2');

// Full filesystem path to this dir
define('EP_PLUGIN_DIR', dirname(__FILE__));

// Option name for exclusion data
define('EP_OPTION_NAME', 'ep_exclude_pages');
// Option name for plugin version
define('EP_VERSION_OPTION_NAME', 'ep_exclude_pages_version');

// Separator for the string of IDs stored in the option value
define('EP_OPTION_SEP', ',');
// The textdomain for the WP i18n gear
define( 'EP_TD', 'exclude-pages' );


/**
 * Take the pages array, and return the pages array without the excluded pages
 * Does NOT do this when in the admin area
 *
 * @author Simon Wheatley
 *
 * @param array $pages
 * @return array
 */
function ep_exclude_pages( $pages ) {
	// If the URL includes "wp-admin", just return the unaltered list
	// This constant, WP_ADMIN, only came into WP on 2007-12-19 17:56:16 rev 6412, i.e. not something we can rely upon unfortunately.
	// May as well check it though.
	// Also check the URL... let's hope they haven't got a page called wp-admin (probably not)
	// @todo SWTODO: Actually, you can create a page with an address of wp-admin (which is then inaccessible), I consider this a bug in WordPress (which I may file a report for, and patch, another time).
	$bail_out = ( ( defined( 'WP_ADMIN' ) && WP_ADMIN == true ) || ( strpos( $_SERVER[ 'PHP_SELF' ], 'wp-admin' ) !== false ) );
	$bail_out = apply_filters( 'ep_admin_bail_out', $bail_out );
	if ( $bail_out ) return $pages;
	$excluded_ids = ep_get_excluded_ids();
	$length = count($pages);
	// Ensure we catch all descendant pages, so that if a parent
	// is hidden, it's children are too.
	for ( $i=0; $i<$length; $i++ ) {
		$page = & $pages[$i];
		// If one of the ancestor pages is excluded, add it to our exclude array
		if ( ep_ancestor_excluded( $page, $excluded_ids, $pages ) ) {
			// Can't actually delete the pages at the moment,
			// it'll screw with our recursive search.
			// For the moment, just tag the ID onto our excluded IDs
			$excluded_ids[] = $page->ID;
		}
	}

	// Ensure the array only has unique values
	$delete_ids = array_unique( $excluded_ids );

	// Loop though the $pages array and actually unset/delete stuff
	for ( $i=0; $i<$length; $i++ ) {
		$page = & $pages[$i];
		// If one of the ancestor pages is excluded, add it to our exclude array
		if ( in_array( $page->ID, $delete_ids ) ) {
			// Finally, delete something(s)
			unset( $pages[$i] );
		}
	}

	// Re-index the array, for neatness
	// @todo SWFIXME: Is re-indexing the array going to create a memory optimisation problem for large arrays of WP post/page objects?
	if ( ! is_array( $pages ) ) $pages = (array) $pages;
	$pages = array_values( $pages );

	return $pages;
}

/**
 * Recur down an ancestor chain, checking if one is excluded
 *
 * @author Simon Wheatley
 *
 * @todo rewrite first line to avoid strict warning 'Only variables should be assigned by reference'
 * - let's just try it first without the reference... not clear what if anything will break...
 * (which is why this is a beta)
 *
 * @param object $page
 * @param array $excluded_ids
 * @param array $pages
 * @return boolean|int The ID of the "nearest" excluded ancestor, otherwise false
 */
function ep_ancestor_excluded( $page, $excluded_ids, $pages ) {
//	$parent = & ep_get_page( $page->post_parent, $pages );
	$parent = ep_get_page( $page->post_parent, $pages );
	// Is there a parent?
	if ( ! $parent )
		return false;

	// Is it excluded?
	if ( in_array( $parent->ID, $excluded_ids ) )
		return (int) $parent->ID;

	// Is it the homepage?
	if ( $parent->ID == 0 )
		return false;

	// Otherwise we have another ancestor to check
	return ep_ancestor_excluded( $parent, $excluded_ids, $pages );
}

/**
 * {no description}
 *
 * @author Simon Wheatley
 *
 * @param int	$page_id The ID of the WP page to search for
 * @param array $pages An array of WP page objects
 * @return boolean|object the page from the $pages array which corresponds to the $page_id
 */
function ep_get_page( $page_id, $pages ) {
	// PHP 5 would be much nicer here, we could use foreach by reference, ah well.
	$length = count($pages);
	for ( $i=0; $i<$length; $i++ ) {
		$page = & $pages[$i];
		if ( $page->ID == $page_id ) return $page;
	}
	// Unusual.
	return false;
}


/**
 * Is this page we're editing (defined by global $post_ID var) currently
 * NOT excluded (i.e. included), returns true if NOT excluded (i.e. included)
 * returns false is it IS excluded.
 * (Tricky this upside down flag business.)
 *
 * @author Simon Wheatley
 *
 * @return bool
 */
function ep_this_page_included() {
	global $post_ID;
	// New post? Must be included then.
	if ( ! $post_ID ) return true;
	$excluded_ids = ep_get_excluded_ids();
	// If there's no exclusion array, we can return true
	if ( empty($excluded_ids) ) return true;
	// Check if our page is in the exclusion array
	// The bang (!) reverses the polarity [1] of the boolean
	return ! in_array( $post_ID, $excluded_ids );
	// fn1. (of the neutron flow, ahem)
}

/**
 * Check the ancestors for the page we're editing (defined by global $post_ID var),
 * return the ID of the nearest one which is excluded (if any);
 *
 * @author Simon Wheatley, Juliette Reinders Folmer
 *
 * @return bool|int
 */
function ep_nearest_excluded_ancestor() {
	global $post_ID, $wpdb;
	// New post? No problem.
	if ( ! $post_ID ) return false;
	$excluded_ids = ep_get_excluded_ids();
	// Get all the pages/custom post type posts and avoid our own filter.
	$post_type = get_post_type( $post_ID );
	if( $post_type === false ) { $post_type = 'page'; }
	// Manually get all the pages, to avoid our own filter.
	$sql = $wpdb->prepare( "SELECT ID, post_parent FROM $wpdb->posts WHERE post_type = %s", $post_type );
	$pages = $wpdb->get_results( $sql );
	// Start recursively checking the ancestors
	$parent = ep_get_page( $post_ID, $pages );
	return ep_ancestor_excluded( $parent, $excluded_ids, $pages );
}

/**
 * Retrieve array of excluded ids
 *
 * @author Simon Wheatley
 *
 * @return array
 */
function ep_get_excluded_ids() {
	$exclude_ids_str = get_option( EP_OPTION_NAME );
	// No excluded IDs? Return an empty array
	if ( empty($exclude_ids_str) ) return array();
	// Otherwise, explode the separated string into an array, and return that
	return explode( EP_OPTION_SEP, $exclude_ids_str );
}

/**
 * This function gets all the exclusions out of the options table,
 * updates them, and re-saves them in the options table.
 * We're avoiding making this a postmeta (custom field) because we
 * don't want to have to retrieve meta for every page in order to
 * determine if it's to be excluded. Storing all the exclusions in
 * one row seems more sensible.
 *
 * @author Simon Wheatley, Will Earnhardt, Juliette Reinders Folmer
 * @version 2.0.0
 *
 * @todo further testing of permission checking for various roles, now only been tested as admin (which is definitely not enough)
 * @todo and while you're at it.... testing with various WP and PHP versions needed to determine current minimum WP version
 *
 * @param int $post_ID The ID of the WP page to exclude
 * @param object $post The post object
 * @return void
 */
function ep_update_exclusions( $post_ID, $post ) {
	// Bail on auto-save
	if( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		return;

	// Bail on initial concept save
	if( ( $post->post_status === 'auto-draft' && $post->post_title === __( 'Auto Draft' ) ) && ( $post->post_name === '' && $post->post_content === '' ) )
		return;


	// Make sure the ID of the post is added to our exclusion array, not the ID of a revision.
	// This keeps the excluded pages array smaller.
	if ( $the_post = wp_is_post_revision( $post_ID ) )
		$post_ID = $the_post;


	// If our current user can't edit this page/custom post type, bail
	$post_type_object = get_post_type_object( $post->post_type );
	if ( !current_user_can( $post_type_object->cap->edit_post, $post_ID ) )
		return;


	// Bang (!) to reverse the polarity of the boolean, turning include into exclude
	$exclude_this_page = ! (bool) @ $_POST['ep_this_page_included'];
	// @todo SWTODO: Also check for a hidden var, which confirms that this checkbox was present
	// If hidden var not present, then default to including the page in the nav (i.e. bomb out here rather
	// than add the page ID to the list of IDs to exclude)
	$ctrl_present = (bool) @ $_POST['ep_ctrl_present'];

	if ( ! $ctrl_present )
		return;

	$excluded_ids = ep_get_excluded_ids();
	// If we need to EXCLUDE the page from the navigation...
	if ( $exclude_this_page ) {
		// Add the post ID to the array of excluded IDs
		array_push( $excluded_ids, $post_ID );
		// De-dupe the array, in case it was there already
		$excluded_ids = array_unique( $excluded_ids );
	}
	// If we need to INCLUDE the page in the navigation...
	if ( ! $exclude_this_page ) {
		// Find the post ID in the array of excluded IDs
		$index = array_search( $post_ID, $excluded_ids );
		// Delete any index found
		if ( $index !== false ) unset( $excluded_ids[$index] );
	}
	$excluded_ids_str = implode( EP_OPTION_SEP, $excluded_ids );
	// Use built in WP function for updating/adding options
	// If option exists, it will update it, if not, it will add it
	update_option( EP_OPTION_NAME, $excluded_ids_str );
}

/**
 * Callback function for the metabox on the page edit screen.
 *
 * @author Simon Wheatley
 *
 * @return void
 */
function ep_admin_sidebar_wp25() {
	$nearest_excluded_ancestor = ep_nearest_excluded_ancestor();
	echo '	<div id="excludepagediv" class="new-admin-wp25">
		<div class="outer"><div class="inner">
		<p><label for="ep_this_page_included" class="selectit">
			<input
				type="checkbox"
				name="ep_this_page_included"
				id="ep_this_page_included" ' .
				( ep_this_page_included() ? 'checked="checked"' : '' ) . '
			 />' .
			__( 'Include this page in lists of pages', EP_TD ) . '</label>
			<input type="hidden" name="ep_ctrl_present" value="1" />
		</p>';
	if ( $nearest_excluded_ancestor !== false ) {
		echo '<p class="ep_exclude_alert"><em>' .
		sprintf( __( 'N.B. An ancestor of this page is excluded, so this page is too (<a href="%1$s" title="%2$s">edit ancestor</a>).', EP_TD), "post.php?action=edit&amp;post=$nearest_excluded_ancestor", __( 'edit the excluded ancestor', EP_TD ) ) .
		'</em></p>';
	}
	// If there are custom menus (WP 3.0+) then we need to clear up some
	// potential confusion here.
	if ( ep_has_menu() ) {
		echo '<p id="ep_custom_menu_alert"><em>';
		if ( current_user_can( 'edit_theme_options' ) )
			printf( __( 'N.B. This page can still appear in explicitly created <a href="%1$s">menus</a> (<a id="ep_toggle_more" href="#ep_explain_more">explain more</a>)', EP_TD),
				"nav-menus.php" );

		else
			_e( 'N.B. This page can still appear in explicitly created menus (<a id="ep_toggle_more" href="#ep_explain_more">explain more</a>)', EP_TD);

		echo '</em></p>';
		echo '<div id="ep_explain_more"><p>';
		if ( current_user_can( 'edit_theme_options' ) )
			printf( __( 'WordPress provides a simple function for you to maintain your site <a href="%1$s">menus</a>. If you create a menu which includes this page, the checkbox above will not have any effect on the visibility of that menu item.', EP_TD),
				"nav-menus.php" );

		else
			_e( 'WordPress provides a simple function for you to maintain the site menus, which your site administrator is using. If a menu includes this page, the checkbox above will not have any effect on the visibility of that menu item.', EP_TD);

		echo '</p><p>' .
		__( 'If you think you no longer need the Exclude Pages plugin you should talk to your WordPress administrator about disabling it.', EP_TD ) .
		'</p></div>';
	}
	echo '		</div><!-- .inner --></div><!-- .outer -->
	</div><!-- #excludepagediv -->';
}

/**
 * A conditional function to determine whether there are any menus
 * defined in this WordPress installation.
 *
 * @author Simon Wheatley
 *
 * @return bool Indicates the presence or absence of menus
 */
function ep_has_menu() {
	if ( ! function_exists( 'wp_get_nav_menus' ) )
		return false;

	$menus = wp_get_nav_menus();
	foreach ( $menus as $menu_maybe ) {
		if ( $menu_items = wp_get_nav_menu_items($menu_maybe->term_id) )
			return true;
	}
	return false;
}

/**
 * Hooks the WordPress admin_head action to inject some CSS.
 *
 * @author Simon Wheatley
 *
 * @return void
 */
function ep_admin_css() {
	echo <<<END
<style type="text/css" media="screen">
	.ep_exclude_alert { font-size: 11px; }
	.new-admin-wp25 { font-size: 11px; background-color: #fff; }
	.new-admin-wp25 .inner {  padding: 8px 12px; background-color: #EAF3FA; border: 1px solid #EAF3FA; -moz-border-radius: 3px; -khtml-border-bottom-radius: 3px; -webkit-border-bottom-radius: 3px; border-bottom-radius: 3px; }
	#ep_admin_meta_box .inner {  padding: inherit; background-color: transparent; border: none; }
	#ep_admin_meta_box .inner label { background-color: none; }
	.new-admin-wp25 .exclude_alert { padding-top: 5px; }
	.new-admin-wp25 .exclude_alert em { font-style: normal; }

	.ep_parent_excluded { opacity:0.3; filter:alpha(opacity=30); /* For IE8 and earlier */ }
	.fixed .column-inmenu { width: 4em; }
	#wpbody-content .quick-edit-row-page fieldset.inline-edit-inmenu { border-right: 1px solid #DFDFDF; }
	.inline-edit-row fieldset label.inline-edit-inmenu { margin: -8px 0 3px 0; }
	.column-inmenu input { display: none; }
</style>
END;
}

/**
 * Hooks the WordPress admin_head action to inject some JS.
 *
 * @author Simon Wheatley
 *
 * @return void
 */
function ep_admin_js() {
	echo <<<END
<script type="text/javascript">
//<![CDATA[
	jQuery( '#ep_explain_more' ).hide();
	jQuery( '#ep_toggle_more' ).click( function() {
		jQuery( '#ep_explain_more' ).toggle();
		return false;
	} );
//]]>
</script>
END;
}

/**
 * Hooks the WordPress admin_footer action to inject the quick edit script
 *
 * @author Juliette Reinders Folmer
 *
 * @return void
 */
function ep_admin_quickedit_js() {
	$types = get_post_types( array ( 'hierarchical' => true ), 'objects');
	$user = wp_get_current_user();
	$suffix = ( ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG === true ) ? '' : '.min' );

	# load only when editing a hierarchical post type
	if( ( ( isset( $_GET['page'] ) && array_key_exists( $_GET['page'], $types ) ) &&
		( ( isset( $user->allcaps[$types[$_GET['page']]->cap->edit_post] ) && $user->allcaps[$types[$_GET['page']]->cap->edit_post] === true ) || ( isset( $user->allcaps[$types[$_GET['page']]->cap->edit_posts] ) && $user->allcaps[$types[$_GET['page']]->cap->edit_posts] === true ) ) )
		||
		( ( isset( $_GET['post_type'] ) && array_key_exists( $_GET['post_type'], $types ) ) &&
		( ( isset( $user->allcaps[$types[$_GET['post_type']]->cap->edit_post] ) && $user->allcaps[$types[$_GET['post_type']]->cap->edit_post] === true ) || ( isset( $user->allcaps[$types[$_GET['post_type']]->cap->edit_posts] ) && $user->allcaps[$types[$_GET['post_type']]->cap->edit_posts] === true ) ) ) ) {
		echo '<script type="text/javascript" src="' . plugins_url( 'admin_quickedit'.$suffix.'.js', __FILE__ ) . '"></script>';
	}
}


/**
 * Add our ctrl to the list of controls which are NOT hidden
 *
 * @author Simon Wheatley
 *
 * @param $to_show
 * @return mixed
 */
function ep_hec_show_dbx( $to_show ) {
	array_push( $to_show, 'excludepagediv' );
	return $to_show;
}

// PAUSE & RESUME FUNCTIONS
function pause_exclude_pages() {
	remove_filter('get_pages','ep_exclude_pages');
}

function resume_exclude_pages() {
	add_filter('get_pages','ep_exclude_pages');
}

// INIT FUNCTIONS

/**
 * Add the main filter
 *
 * @author Simon Wheatley
 */
function ep_init() {
	// Call this function on the get_pages filter
	// (get_pages filter appears to only be called on the "consumer" side of WP,
	// the admin side must use another function to get the pages. So we're safe to
	// remove these pages every time.)
	add_filter('get_pages','ep_exclude_pages');
}

/**
 * Add actions and filters for when we're in the WordPress backend
 *
 * @author Simon Wheatley, Juliette Reinders Folmer, Will Earnhardt
 * @version 2.0.0
 */
function ep_admin_init() {
	// Add panels into the editing sidebar(s)
//	global $wp_version;
	// Add the meta box and quick edit box to every hierarchical post type.
	$types = get_post_types( array ( 'hierarchical' => true ), 'objects');
	$user = wp_get_current_user();
	foreach ($types as $post_type_object) {
		if( ( isset( $user->allcaps[$post_type_object->cap->edit_post] ) && $user->allcaps[$post_type_object->cap->edit_post] === true ) || ( isset( $user->allcaps[$post_type_object->cap->edit_posts] ) && $user->allcaps[$post_type_object->cap->edit_posts] === true ) ) {
			// Add meta box
			add_meta_box('ep_admin_meta_box', __( 'Exclude Pages', EP_TD ), 'ep_admin_sidebar_wp25', $post_type_object->name, 'side', 'low');

			// Add relevant actions and filters for quick edit box
			if( $post_type_object->name === 'page' ) {
				add_filter( 'manage_pages_columns', 'ep_custom_pages_columns' );
				add_action( 'manage_pages_custom_column', 'ep_fill_custom_column', 10, 2 );
			}
			else {
				add_filter( 'manage_edit-' . $post_type_object->name . '_columns', 'ep_custom_pages_columns' );
				// I haven't got a clue why this is not needed for the custom post types, but if I uncomment it, the column gets filled twice....
//				add_action( 'manage_' . $post_type_object->name . '_posts_custom_column', 'ep_fill_custom_column', 10, 2 );
			}
		}
	}
	
	// Add action to add edit code for quick edit box
	add_action( 'quick_edit_custom_box', 'ep_display_custom_quickedit_inmenu', 10, 2 );

	// Set the exclusion when the post is saved
	add_action('save_post', 'ep_update_exclusions', 10, 2);

	// Add the JS & CSS to the admin header
	add_action('admin_head-edit.php', 'ep_admin_css');
	add_action('admin_footer-edit.php', 'ep_admin_js');
	add_action('admin_footer-edit.php', 'ep_admin_quickedit_js');

	add_action('admin_head-post.php', 'ep_admin_css');
	add_action('admin_footer-post.php', 'ep_admin_js');

	$domain = dirname( plugin_basename( __FILE__ ) ) . '/locale/';
	load_plugin_textdomain( EP_TD, false, $domain );

	// Call this function on our very own hec_show_dbx filter
	// This filter is harmless to add, even if we don't have the
	// Hide Editor Clutter plugin installed as it's using a custom filter
	// which won't be called except by the HEC plugin.
	// Uncomment to show the control by default
	// add_filter('hec_show_dbx','ep_hec_show_dbx');
}



/**
 * Upgrade the plugin options if needed
 *
 * @author Juliette Reinders Folmer, Will Earnhardt
 * @since 2.0.0
 */
function ep_upgrade_options() {

	// New installation of the plugin, option upgrade not needed, just add version number
	if( get_option( EP_OPTION_NAME ) === false ) {
		update_option( EP_VERSION_OPTION_NAME, EP_VERSION );
		return;
	}


	$excluded_ids = ep_get_excluded_ids();

	/**
	 * Upgrades for any version of this plugin lower than x.x
	 * N.B.: Version nr has to be hard coded to be future-proof, i.e. facilitate
	 * upgrade routines for various versions
	 */
	/* Settings upgrade for version 2.0.0 */
	if( get_option( EP_VERSION_OPTION_NAME ) === false || version_compare( get_option( EP_VERSION_OPTION_NAME ), '2.0.beta.2', '<' ) ) {

		if( is_array( $excluded_ids ) && count( $excluded_ids ) > 0 ) {

			$proper_ids = array();

			foreach ( $excluded_ids as $id ) {
			// Check for revisions
				if ( get_post_type( $id ) !== 'revision' ) {
				$proper_ids[] = $id;
				}
			}

			// Update our excluded IDs list with the new list sans revisions
			$excluded_ids = $proper_ids;
		}
	}

	/* De-dupe the array, just in case and implode to string */
	$excluded_ids = array_unique( $excluded_ids );
	$excluded_ids_str = implode( EP_OPTION_SEP, $excluded_ids );

	/* Update the options */
	update_option( EP_OPTION_NAME, $excluded_ids_str );
	update_option( EP_VERSION_OPTION_NAME, EP_VERSION );
	return;
}


// OPTION UPGRADING
/* Check if we have any activation or upgrade actions to do */
if( get_option( EP_VERSION_OPTION_NAME ) === false || version_compare( EP_VERSION, get_option( EP_VERSION_OPTION_NAME ), '>' ) ) {
	add_action( 'init', 'ep_upgrade_options', 8 );
}
// Make sure that an upgrade check is done on (re-)activation as well.
register_activation_hook( __FILE__, 'ep_upgrade_options' );


// HOOK IT UP TO WORDPRESS
add_action( 'init', 'ep_init' );
add_action( 'admin_init', 'ep_admin_init' );


// FUNCTIONS TO ENABLE THE STATUS DISPLAY COLUMN IN THE OVERVIEW PAGE AND THE QUICK EDIT BOX

/**
 * Add an 'In Menu ?' column to the pages overview
 *
 * @author Juliette Reinders Folmer
 * @since 2.0.0
 *
 * @param	array	$columns	Current columns in overview table
 * @return	array
 */
function ep_custom_pages_columns( $columns ) {

	/** Add a 'In Menu' Column **/
	$myCustomColumns = array(
		'inmenu' => __( 'In Menu', EP_TD ),
	);
	$columns = array_merge( $columns, $myCustomColumns );

	return $columns;
}

/**
 * Fill the 'In Menu ?' column for each row with the exclude status
 *
 * @author Juliette Reinders Folmer
 * @since 2.0.0
 *
 * @param	string	$column 	Current column
 * @param	int 	$post_id	Current post id
 * @return	void
 */
function ep_fill_custom_column( $column, $post_id ) {
	global $wpdb;

	static $excluded_ids;
	static $pages;

	if( is_null( $excluded_ids ) )
		$excluded_ids = ep_get_excluded_ids();

	// Fill pages array while avoiding our own filters
	$post_type = get_post_type( $post_id );
	if( is_null( $pages ) ) {
		// Manually get all the pages, to avoid our own filter.
		$sql = $wpdb->prepare( "SELECT ID, post_parent FROM $wpdb->posts WHERE post_type = %s", $post_type );
		$pages = $wpdb->get_results( $sql );
	}

	switch ( $column ) {

		case 'inmenu':
			$inmenu = ( empty( $excluded_ids ) ? true : ( !in_array( $post_id, $excluded_ids ) ? true : false ) );

			if( true === $inmenu ) {
				$imgsrc = admin_url( 'images/yes.png' );
				$imgalt = __( 'Yes' );
				$checked = true;
			}
			else {
				$imgsrc = admin_url( 'images/no.png' );
				$imgalt = __( 'No' );
				$checked = false;
			}

			$parent = ep_get_page( $post_id, $pages );
			$ancestor_inmenu = ep_ancestor_excluded( $parent, $excluded_ids, $pages );

			echo '<img src="' . esc_url( $imgsrc ) . '" width="16" height="16" alt="' . esc_attr( $imgalt ) .
				'"'. ( ( $ancestor_inmenu !== false ) ? ' class="ep_parent_excluded"' : '' ) . ' />
				<input type="checkbox" readonly ' . checked( $checked, true, false ) . ' />';

			unset( $inmenu, $imgsrc, $imgalt, $checked, $page, $ancestor_inmenu );

			break;
	}
}


/**
 * Print out the quick edit tick-box
 *
 * @author Juliette Reinders Folmer
 * @since 2.0.0
 *
 * @param	string	$column_name
 * @param	string	$post_type
 * @return	void
 */
function ep_display_custom_quickedit_inmenu( $column_name, $post_type ) {
	echo '
	<fieldset class="inline-edit-col-left inline-edit-inmenu">
		<div class="inline-edit-col inline-edit-' . esc_attr( $column_name ) . '">';

	switch ( $column_name ) {
		case 'inmenu':
			echo '
			<label class="alignleft inline-edit-' . esc_attr( $column_name ) . '">
				<input name="ep_this_page_included" type="checkbox" />
				<input type="hidden" name="ep_ctrl_present" value="1" />
				<span class="checkbox-title">' . __( 'Include this page in lists of pages', EP_TD ) . '</span>
			</label>';
			break;
	}
	echo '
		</div>
	</fieldset>';
}

?>