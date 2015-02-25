<?php
/*
Plugin Name: BP pushState
Description: Use HTML5's history.pushState() to view BuddyPress single pages with AJAX without fully reloading the page!
Author:      r-a-y
Author URI:  http://profiles.wordpress.org/r-a-y
Version:     0.1-alpha
License:     GPLv2 or later
*/

add_action( 'bp_loaded', array( 'BP_pushState', 'init' ) );

/**
 * Proof of concept of the HTML5 History API working with BuddyPress.
 *
 * Only works for the bp-legacy template pack and on BP single pages for main
 * nav items.  No support for sub-nav items yet.  No support for older
 * browsers that do not support history.pushState().  Well-coded member
 * plugins should function just fine.
 *
 * Known issues - General:
 * - Plugins relying on is_admin() to load up code on the frontend will fail.
 *   This is due to AJAX returning is_admin() to true.  Plugins will need to
 *   reapply their code using the "bp_pushstate_{$component}_before_buffer"
 *   hook.
 * - Plugins relying on the 'wp_footer' hook will need to reapply their code
 *   using the "bp_pushstate_{$component}_during_buffer" hook.
 *
 * Known issues - bbPress Groups:
 * - If you're logged in and click on the "Forum" tab and then click on another
 *   nav tab that relies on JS (Home, Members, Send Invites), the JS will not
 *   work until you refresh the page.  This is due to a conflict with TinyMCE
 *   and BP's JS.
 * - TinyMCE currently works with a big hack.  Read the "General" issues
 *   section above for a rundown.
 */
class BP_pushState {
	/**
	 * Static init method.
	 */
	public static function init() {
		return new self();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_bp_pushstate',        array( $this, 'process_pushstate' ) );
		add_action( 'wp_ajax_nopriv_bp_pushstate', array( $this, 'process_pushstate' ) );

		add_action( 'wp_footer', array( $this, 'inline_js' ) );
	}

	/**
	 * AJAX listener to process history pushstate changes.
	 */
	public function process_pushstate() {
		$linkid  = explode( '-', $_REQUEST['linkid'] );
		$href    = $_REQUEST['href'];
		$group   = '';

		if ( 'user' === $linkid[0] ) {
			$component = 'members';
			$slug = $tpart = $linkid[1];
		} else {
			$component = 'groups';
			$slug = $tpart = $linkid[0];
		}

		/** members component *************************************************/

		if ( 'xprofile' === $slug ) {
			$slug = $tpart = 'profile';
		}

		// all non-core nav items will use the 'plugins' template part
		// we're omitting legacy forums from the core check
		if( 'members' === $component && false === in_array( $slug, array( 'activity', 'blogs', 'groups', 'friends', 'messages', 'notifications', 'settings', 'profile' ), true ) ) {
			$tpart = 'plugins';
		}

		/** groups component **************************************************/

		if ( 'groups' === $component ) {
			// slug adjustments
			if ( 'invite' === $slug ) {
				$slug = $tpart = 'send-invites';
			}
			if ( 'request' === $slug ) {
				$slug = $tpart = 'request-membership';
			}

			// global adjustments
			global $groups_template;

			// avoid notices on the "Home" activity template part
			$groups_template = new stdClass;
			$groups_template->group = buddypress()->groups->current_group;

			// admin page requires the 'action_variables' property set
			if ( 'admin' === $slug ) {
				buddypress()->action_variables = array( 'edit-details' );
			}
		}

		// all non-core nav items will use the 'plugins' template part
		if ( 'groups' === $component && false === in_array( $slug, array( 'home', 'members', 'send-invites', 'request-membership', 'admin' ), true ) ) {
			$tpart = 'plugins';
		}

		/** plugin-specific ***************************************************/

		// bbPress
		if ( ( 'members' === $component && 'forums' === $slug ) || ( 'groups' === $component && 'nav' === $linkid[0] && 'forum' && $linkid[1] ) ) {
			// bbPress requires defining the 'WP_USE_THEMES' constant so template parts
			// will load.  See the bottom portion of bbp_locate_template().
			define( 'WP_USE_THEMES', true );

			// group-specific
			if ( 'groups' == $component ) {
				// ugly hack to get TinyMCE's inline JS working...
				// we have to fake the is_admin() call to false
				// @todo perhaps move this out so every plugin can use it?
				$GLOBALS['current_screen'] = new WP_No_Admin;

				// support GD bbPress attachments
				//
				// their code does a check on is_admin() early to determine whether to load up
				// their frontend code.  since AJAX returns is_admin() to true, their code
				// fails to load.  so we manually boot up their frontend code here.
				if ( ! empty( $GLOBALS['gdbbpress_attachments'] ) ) {
					require_once( GDBBPRESSATTACHMENTS_PATH . 'code/attachments/front.php' );
					$GLOBALS['gdbbpress_attachments_front']->load();
				}
			}
		}

		// BP Follow
		if ( 'members' === $component && count( $linkid ) > 3 ) {
			$tpart = 'plugins';
		}

		// plugins usually have to hook into 'bp_screens' to run their globals and
		// other routines via BP_Component. run it here to ensure compatibility.
		if ( 'plugins' === $tpart ) {
			do_action( 'bp_screens' );
		}

		/** object buffer *****************************************************/

		// hook to do stuff before object buffer
		do_action( "bp_pushstate_{$component}_before_buffer", $slug, $href, $group );

		// filter the template part name
		$tpart = apply_filters( "bp_pushstate_{$component}_tpart_name", "{$component}/single/{$tpart}", $tpart, $href, $group );

		$result = array();

		// Start the buffer!
		ob_start();

		// filter for plugins to override this entire process
		$content = apply_filters( "bp_pushstate_{$component}_content", '', $slug, $href, $group );
		if ( ! empty( $content ) ) {
			echo $content;

		// use the default templates
		} else {
			// groups - fugly, pt. i
			// the group homepage requires some logic copied from /groups/single/home.php
			if ( 'groups' === $component && 'home' === $slug ) {
				if ( bp_group_is_visible() ) {

					// Use custom front if one exists
					$custom_front = bp_locate_template( array( 'groups/single/front.php' ), false, true );
					if     ( ! empty( $custom_front   ) ) : load_template( $custom_front, true );

					// Default to activity
					elseif ( bp_is_active( 'activity' ) ) : bp_get_template_part( 'groups/single/activity' );

					// Otherwise show members
					elseif ( bp_is_active( 'members'  ) ) : bp_groups_members_template_part();

					endif;

				} else {

					do_action( 'bp_before_group_status_message' ); ?>

					<div id="message" class="info">
						<p><?php bp_group_status_message(); ?></p>
					</div>

					<?php do_action( 'bp_after_group_status_message' );

				}

			// sigh... another fudged workaround
			} elseif ( 'groups' === $component && 'members' == $slug ) {
				bp_groups_members_template_part();

			// everything else - load up the template part
			} else {
				bp_get_template_part( $tpart );
			}
		}

		// hook to echo stuff during buffer
		do_action( "bp_pushstate_{$component}_during_buffer", $slug, $href, $group );

		// groups - fugly, pt. ii
		// bbPress hack to get TinyMCE working... also slows down the network
		// request due to the redownloading of all the JS assets every time...
		if ( 'groups' === $component && 'forum' === $slug ) {
			// force jquery-ui-core to never load
			// reduces the number of scripts to load
			wp_scripts()->remove( 'jquery-ui-core' );

			// suppress notices due to forced asset removal and call wp_footer
			@do_action( 'wp_footer' );
		}

		// save object buffer
		$result['contents'] = ob_get_contents();

		// et fini.
		ob_end_clean();

		// Yes, we even support page titles!
		// @todo The separator and title format is hard-coded currently...
		// @todo Look into add_theme_support( 'title_tag' )
		$result['page_title'] = apply_filters( "bp_pushstate_{$component}_title", wp_title( '|', false ), $slug, $group );

		// @todo support dynamically switching out the RSS links?
		//$result['rss'] = '';

		exit( json_encode( $result ) );
	}

	/**
	 * Magic JS so HTML History API will work with BuddyPress!
	 */
	public function inline_js() {
		// not theme compat? stop now!
		if ( ! bp_use_theme_compat_with_current_theme() ) {
			return;
		}

		if ( ! is_buddypress() ) {
			return;
		}

		if ( defined( 'DOING_AJAX' ) ) {
			return;
		}
	?>

		<script type="text/javascript">
		jQuery(function($) {
			// an alias for $.getScript, but with caching
			loadScript = function( url ) {
				$.ajax({
					type: "GET",
					url: url,
					dataType: "script",
					cache: true
				});
			};

			loadTemplatePart = function( href, link ) {
				$.post( ajaxurl, {
					href: href,
					linkid: link.attr('id'),
					action: 'bp_pushstate',
				},
				function(response) {
					// get rid of any notices
					var message = $('#message');
					if ( message.length ) {
						message.fadeOut(300);
					}

					// AJAX goodness!
					$( '#item-body' ).hide().html( response.contents ).fadeIn(300);
					document.title = response.page_title;

					// nav highlighting
					$( '#object-nav li.current' ).removeClass( 'current selected ');
					link.parent().addClass( 'current selected' );

					// this hack is necessary so BP's JS will run after AJAX...
					// this will have to do until we refactor BP's JS
					loadScript( '<?php echo bp_get_theme_compat_url() . 'js/buddypress.js'; ?>' );
				}, 'json' );
			};

			// i'm the backwards man. i can go backwards as fast as you can.
			// this handles clicking on the 'back' and 'forward' buttons in your browser
			$(window).on("popstate", function(e) {
				var navLink = $("#object-nav a[href='" + e.currentTarget.location.href + "']");
				if ( navLink.length ) {
					loadTemplatePart( e.currentTarget.location.href, navLink );
				}
			});

			// only use pushState on main nav items for now
			$(document).on("click", "#object-nav a", function() {
				var link = $(this),
					href = link.attr('href');

				if ( href.indexOf(document.domain) > -1 || href.indexOf(':') === -1 ) {
					history.pushState({}, '', href);
					loadTemplatePart( href, link );

					return false;
				}
			});
		});
		</script>

	<?php
	}

}

if ( ! class_exists( 'WP_No_Admin' ) ) :
/**
 * Loophole class to fake {@link is_admin()} to false during AJAX requests.
 *
 * Since a WP AJAX request always defaults is_admin() to true, we initialize
 * this class as the $current_screen global to fake is_admin() to false.
 *
 * @see is_admin()
 */
class WP_No_Admin {
	/**
	 * Hack method to fake $current_screen->in_admin().
	 */
	public function in_admin() {
		return false;
	}
}
endif;