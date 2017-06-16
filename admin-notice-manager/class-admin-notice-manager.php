<?php
/**
 *
 * A class to handle the setting, display and removal of admin notices in Wordpress plugins.
 *
 */
class HEC_Admin_Notice_Manager {
	
	/**
	 * The unique identifier of this admin notice manager.
	 *
	 * @access		private
	 * @var			string	$plugin_name		The string used to uniquely identify this manager.
	 * 											Used as a prefix to keys when storing in usermeta.
	 */
	private static $manager_id;
	
	/**
	 * The version of this plugin.
	 *
	 * @access	private
	 * @var		string	$version	The current version of this plugin.
	 */
	private static $version;
	
	/**
	 * The url to the js directory.
	 *
	 * @access		private
	 * @var			string	$url_to_assets_dir		Url to the directory where the files admin-notice-manager.js and
	 *												admin-notice-manager.css are located. Must include trailing slash.
	 */
	private static $url_to_assets_dir;
	
	/**
	 * The text domain for translation.
	 *
	 * @access		private
	 * @var			string	$text_domain		Text domain for translation.
	 */
	private static $text_domain;
	
	/**
	 * Flag used to prevent more than one initialization.
	 *
	 * @access   private
	 * @var      array    $notices    Set to true the first time class is initialized.
	 */
	private static $inited = false;
	
	/**
	 * Notices.
	 *
	 * @access   private
	 * @var      array    $notices    User notices required or added during for this request.
	 */
	private static $notices = array();
	
	/**
	 * Opt out notices.
	 *
	 * @access   private
	 * @var      array    $opt_out_notices    Notices that are always displayed by default and are conditionally prevented from display.
	 */
	private static $opt_out_notices = array();
	
	/**
	 * Save notices flag.
	 *
	 * @access   private
	 * @var      bool    $save_notices    Set to true if notices must be saved to the database.
	 */
	private static $save_notices = false;
	
	/**
	 * Initialize manager.
	 * 
	 * @param	array			$args {
	 *		@type	string			$manager_id				Unique id for this manager. Used as a prefix for database keys.
	 *		@type	string			$url_to_assets_dir			Path to directory containing admin-notice-manager.js.
	 *		@type	string			$text_domain			Text domain for translation.
	 *		@type	string			$version				Plugin version.
	 *		@type	array			$opt_out_notices		Array of array of opt out notices with keys of notice id
	 * }
	 */
	public static function init( array $args ) {
	
		// Only run init once per request.
		if ( ! self::$inited ) {
			
			$defaults = array(
				'manager_id'		=>	'anm',
				'url_to_assets_dir'		=>	'',
				'text_domain'		=>	'default',
				'version'			=>	'',
				'opt_out_notices'	=> array()
			);
			
			$args = wp_parse_args( $args, $defaults );
			
			// Set manager id, text domain and version.
			self::$manager_id = empty( $args['manager_id'] ) ? 'anm' : $args['manager_id'];
			self::$text_domain = $args['text_domain'];
			self::$version = $args['version'];

			// Add hooks, but only if we are not uninstalling the plugin.
			if( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			
				// Add actions to display notices as needed.
				add_action( 'admin_notices', array( __CLASS__, 'display_notices' ) );
				
				// Add hooks to save notices.
				add_filter( 'wp_redirect', array( __CLASS__, 'save_notices' ) );
				add_action( 'shutdown', array( __CLASS__, 'save_notices' ) );
				
				// Add JS and ajax processing to handle dismissal of persistent notices.
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
				add_action( 'wp_ajax_' . self::$manager_id . '_dismiss_admin_notice', array( __CLASS__, 'dismiss_notice' ) );
				
				
				
				// Display error message if not initialized correctly
				add_action( 'admin_init', array( __CLASS__, 'check_manager_id' ) );
				
			}

			// Set url to js directory, adding trailing slash if needed. Defaults to this file's directory.
			if ( empty( $args['url_to_assets_dir'] ) ) {
				$args['url_to_assets_dir'] = plugin_dir_url( __FILE__ );
			}
			if ( '/' != substr( $args['url_to_assets_dir'], -1 ) ) {
				$args['url_to_assets_dir'] .= '/';
			}
			self::$url_to_assets_dir = $args['url_to_assets_dir'];
			
			// Set flag to show init has run.
			self::$inited = true;
			
		}
		
	}
	
	/**
	 * Check that manager id has been set and display error message to administrators if not.
	 *
	 * @hooked admin_init
	 */
	public static function check_manager_id() {
	
		// Display initialization error to administrators if needed.
		if ( current_user_can( 'manage_options' ) ) {
			if ( 'anm' == self::$manager_id || ( ! is_string( self::$manager_id ) && ! is_int( self::$manager_id ) ) ) {
				$notice = array(
					'id'			=>	'anm_initialization_warning',
					'message'		=>	sprintf( __( 'Admin Notice Manager: Unique identifier not set, so conflicts may occur. Please set a valid unique identifer when the %s class is initialized. This must be either a string or an integer.', self::$text_domain ), get_called_class() ),
					'type'			=>	'error',
					'user_ids'		=>	array( 'administrator' ),
					'persistent'	=>	true,
					'dismissable'	=>	false,
					'dismiss_all'	=>	false
				);
				self::add_opt_out_notice( $notice );
			}
		}
		
	}
	
	/**
	 * Enqueue scripts and styles.
	 *
	 * @param	string			$hook		Hook
	 * @hooked admin_enqueue_scripts
	 */
	public static function enqueue_scripts( $hook ) {
		// Note that handle is not specific to the manager id so if we are using two plugins with the notice manager files are only loaded once.
		wp_enqueue_script( 'admin-notice-manager-js', self::$url_to_assets_dir . 'admin-notice-manager.js', array( 'jquery' ), self::$version );
		wp_enqueue_style( 'admin-notice-manager-css', self::$url_to_assets_dir . 'admin-notice-manager.css', array(), self::$version );
	}
	
	/**
	 * Add a new notice.
	 *
	 * @param	array	$notice {
	 * 		@type	string			$id					Unique id for this notice. Default is hashed value of some of the notice parameters.
	 *													Setting an id is recommended however - otherwise non-unique ids are possible and may
	 *													cause unexpected deletion of notices. Updating messages when they are changed by the
	 *													developer gets fiddly too.
	 * 		@type	string			$message			Message to be displayed.
	 * 		@type	string			$wrap_tag			Tag to wrap message in. Default is 'p'. Set to empty string or false for no wrap.
	 * 		@type	string			$type				One of 'success', 'error', warning', 'info'. Default is 'error'.
	 * 		@type	array			$user_ids			Array of user ids or user roles for whom message should be displayed.
	 *													For example: array( 3, 'administrator', 55, 153, 'editors' ) will set the message
	 *													for users with ids of 3, 55 and 153, and for all users that are administrators or editors.
	 *													Default is current user id.										
	 * 		@type	array|string	$screen_ids			Array of screen ids on which message should be displayed.
	 * 													Set to empty array for all screens. If left unset the current screen is set if possible,
	 *													it is recommended to explicitly specify the desired screen rather than leaving unset.
	 *													If during testing the notice is set on a screen that is then not viewed because of a redirect
	 *													(e.g. options), changing the screen in the notice args will have no effect because the notice
	 *													has been stored in the db and will not be updated.
	 *													Default is empty array (all screens ) for one-time messages, and current screen for persistent.
	 * 		@type	array			$post_ids			Array of post ids on which message should be displayed. Empty array means all posts.
	 *													Default is all posts.
	 * 		@type	string			$persistent			True for persistent, false for one-time. Default is false.
	 * 		@type	bool			$dismissable		Whether notice is dismissable. Default is true.
	 * 		@type	bool			$no_js_dismissable	Whether to give option to dismiss notice if no js. Only applies when $dismissable is true.
	 *													Default is false. Caution should be used in setting this to true. The act of dismissing the
	 *													notice refreshes the screen so any changed data on screen will be lost. This could be extremely
	 *													frustrating for a user who has just entered or updated loads of data (e.g., when editing a post).
	 * 		@type	bool			$dismiss_all		Whether to delete notice for all users or just the user that has dismissed the notice.
	 *													Only applies when $dismissable is true. Default is false.
	 * }
	 * @return		array|WP_Error						Array of notices that have been set by user, or error if notice has failed.
	 */
	public static function add_notice( array $notice ) {
		
		$notice = self::parse_notice_args( $notice );
		
		if ( is_wp_error( $notice ) ) {
			return $notice;
		}
		
		// Convert any roles to user ids and unset notice parameter.
		$user_ids = self::parse_user_roles( $notice['user_ids'] );
		unset( $notice['user_ids'] );
		
		$notice_id = $notice['id'];
		unset( $notice['id'] );
		
		$notices = self::$notices;
		
		// Add new notices to existing notices.
		$new_notices = array();
		foreach ( $user_ids as $user_id ) {
			
			// Load user's current notices if not already set.
			if ( ! isset( $notices[ $user_id ] ) ) {
				$notices[ $user_id ] = get_user_meta( $user_id, self::$manager_id . '_admin_notices', true );
			}
			
			// Add notice to current notices (and to new notices) - but only if not already set.
			if ( ! isset( $notices[ $user_id ][ $notice_id ] ) ) {
				$new_notices[ $user_id ][ $notice_id ] = $notices[ $user_id ][ $notice_id ] = $notice;
			}
			
		}
		
		// Update notices and set to update db.
		self::$notices = $notices;
		self::$save_notices = true;

		return $new_notices;

	}
	
	/**
	 * Add a new opt out notice.
	 *
	 * @param	array				$notice 			Identical key-value pairs for @see add_notice, except for the following:
	 * 		@type	array			$user_ids			Empty array means all users. Default is all users (not current user).
	 * 		@type	array|string	$screen_ids			Default is all screens.
	 * 		@type	bool			$dismiss_all		Will always be false. For dismiss_all effect, make sure that notice is
	 *													not set in the first place (e.g., by setting a value in options table).
	 * @return		array|WP_Error						Notice that has been set by user, or error if notice has failed.
	 */
	public static function add_opt_out_notice( array $notice ) {
		
		// If not set, set user ids to empty array to indicate all users.
		if ( ! isset( $notice['user_ids'] ) ) {
			$notice['user_ids'] = array();
		}
		
		// If required, set default screen ids to all screens.
		if ( ! isset( $notice['screen_ids'] ) ) {
			$notice['screen_ids'] = array();
		}
		
		// Force dismiss_all to false
		$notice['dismiss_all'] = false;
		
		$notice = self::parse_notice_args( $notice );
		
		if ( is_wp_error( $notice ) ) {
			return $notice;
		}
		
		$notice_id = $notice['id'];
		unset( $notice['id'] );
		
		$notices = self::$opt_out_notices;
		
		// Add new notice to existing notices. NB this over-writes a notice with the same id.
		$notices[ $notice_id ] = $notice;
		
		// Update notices.
		self::$opt_out_notices = $notices;

		return $notice;

	}
	
	/**
	 * Add new opt out notices.
	 *
	 * @param		array	$notices 	Array of notices. If notice id is not set, key of array is used.
	 * @return		array				Array with keys as notice ids and values as either notice set or WP Error object.
	 */
	public static function add_opt_out_notices( array $notices ) {
		
		$results = array();
		
		foreach ( $notices as $key => $notice ) {
			if ( ! isset( $notice['id'] ) ) {
				$notice['id'] = $key;
			}
			$results[] = self::add_opt_out_notice( $notice );
		}
		
		return $results;
		
	}
	
	/**
	 * Parse notice args.
	 *
	 * @access	private
	 * @param	array			$notice		Array of user-set args
	 * @return	array|WP_Error				Notice with defaults validated and set as necessary, or WP_Error object if values not validated.
	 */
	private static function parse_notice_args( $notice ) {
		
		$errors = new WP_Error();
		
		if ( empty( $notice ) ) {
			$errors->add( 'empty', 'No data supplied' );
		} else {

			// Set the notice arguments using defaults where necessary. (user_ids should always be set for an opt out notice.)
			$defaults = array(
				'message'			=>	'',
				'wrap_tag'			=>	'p',
				'type'				=>	'error',
				'user_ids'			=>	array( get_current_user_id() ),
				'post_ids'			=>	array(),
				'persistent'		=>	false,
				'dismissable'		=>	true,
				'no_js_dismissable'	=>	false,
				'dismiss_all'		=>	false
			);
			
			$notice = wp_parse_args( $notice, $defaults );
			
			// Set the notice id if not already set.
			if ( ! isset( $notice['id'] ) ) {
				$notice['id'] = md5( $notice['message'].$notice['type'] );
			}
			
			// Set the screen ids if not already set. (Will always already be set for opt out notices.)
			if ( ! isset( $notice['screen_ids'] ) ) {
				
				// Default is generally all screens...
				$notice['screen_ids'] = array();
				
				// ...but for persistent notices we set default to current screen if we can
				if ( $notice['persistent'] && $screen_id = self::get_current_screen_id() ) {
					$notice['screen_ids'] = array( $screen_id );
				}
			}
			
			// If notice is not dismissable, set no-js-dismissable to false as well.
			if ( ! $notice['dismissable'] ) {
				$notice['no_js_dismissable'] = false;
			}

			$domain = self::$text_domain;
			
			// Validate all values.
			foreach ( $notice as $key => $value ) {
				
				switch ( $key ) {
					
					case 'id' :
						if ( ! is_string( $value ) && ! is_int( $value ) ) {
							$errors->add( 'type', __( 'ID provided is neither a string nor an integer.', $domain ) );
						}
						break;
						
					case 'message' :
						if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
							$errors->add( 'type', __( 'Invalid message - must be a string, integer or float.', $domain ) );
						}
						if ( empty( $value ) ) {
							$errors->add( 'empty', __( 'No message provided.', $domain ) );
						}
						break;
						
					case 'wrap_tag' :
						if ( ! is_string( $value ) ) {
							$errors->add( 'type', __( 'Invalid message - must be a string.', $domain ) );
						}
						break;
						
					case 'user_ids' :
						$error_message = __( 'Invalid user ids - must be an array containing integers or strings.', $domain );
						if ( ! is_array( $value ) ) {
							$errors->add( 'type', $error_message );
						} else {
							foreach( $value as $user_info ) {
								if ( ! is_int( $user_info ) && ! is_string( $user_info ) ) {
									$errors->add( 'type', $error_message );
									break;
								}
							}
						}
						break;
						
					case 'screen_ids' :
						$error_message = sprintf(
							__( 'Invalid screen ids - must either be an empty array, or an array of strings.', $domain ),
							'current'
						);
						if ( ! is_array( $value ) ) {
							$errors->add( 'type', $error_message );
						} else {
							foreach( $value as $screen_id ) {
								if ( ! is_string( $screen_id ) ) {
									$errors->add( 'type', $error_message );
									break;
								}
							}
						}
						break;
						
					case 'post_ids' :
						$error_message = __( 'Invalid post ids - must either be an empty array or an array of integers.', $domain );
						if ( ! is_array( $value ) ) {
							$errors->add( 'type', $error_message );
						} else {
							foreach( $value as $post_id ) {
								if ( ! is_int( $post_id ) ) {
									$errors->add( 'type', $error_message );
									break;
								}
							}
						}
						break;

					case 'persistent' :
					case 'dismissable' :
					case 'dismiss_all' :
						if ( ! is_bool( $value ) ) {
							$errors->add( 'type', sprintf( __( 'Invalid value for %s - must be boolean.', $domain ), $key ) );
						}
						break;
					
				}
				
			}

		}

		// Give the result.
		if ( ! empty( $errors->get_error_codes() ) ) {
			$errors->add_data( $notice, 'notice_data_provided_for_validation' );
			return $errors;
		} else {
			return $notice;
		}
	}
	
	/**
	 * Parse user ids, converting roles to user ids.
	 *
	 * @access	private
	 * @return	array	$user_ids		Parsed user ids
	 */
	private static function parse_user_roles( array $user_ids ) {
	
		// Convert user roles to user ids and add them.
		if ( ! empty( $user_ids ) )	{	
		
			$user_roles = array();
			foreach ( $user_ids as $key => $user_info ) {
				if ( is_string( $user_info ) ) {
					$user_roles[] = $user_info;
					unset( $user_ids[ $key ] );
				}
			}
			if ( ! empty( $user_roles ) ) {
				$args = array(
					'count_total'	=>	false,
					'fields'		=>	'ID',
					'role__in'		=>	$user_roles
				);
				$role_user_ids = get_users( $args );
				
				if ( ! empty( $role_user_ids ) ) {
					$user_ids = array_unique( array_merge( $user_ids, $role_user_ids ) );
				}
				
			}
			
		}
		
		return $user_ids;
		
	}

	
	/**
	 * Get current screen id.
	 *
	 * @access	private
	 * @return	int	$screen_id		id of current screen, 0 if not available
	 */
	private static function get_current_screen_id() {
		$screen_id = 0;
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( ! empty( $screen ) ) {
				$screen_id = $screen->id;
			}
		}
		return $screen_id;
	}
	
	/**
	 * Returns html for a dismiss on redirect link.
	 *
	 * @param	array	$args {
	 * 		@type		string		$link			Html to display as link.
	 * 		@type		string		$redirect_url	Redirect url. Set as empty string for no redirect. Default is no redirect.
	 * 		@type		array		$classes		Array of classes for the button. Default is array( anm-link ) which styles as a link.
	 * }
	 */
	public static function dismiss_on_redirect_link( array $args ) {
		
		$defaults = array(
			'content'	=>	'Undefined',
			'redirect'	=>	'',
			'classes'	=>	array( 'anm-link' )
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		$classes = array( 'anm-dismiss' );
		if ( ! empty( $args['classes'] ) && is_array( $args['classes'] ) ) {
			$classes = array_merge( $args['classes'], $classes );
		}
		$classes = implode( ' ', $classes );
		
		// Add button with value of redirect url.
		return '<button type="submit" class="' . $classes . '" name="anm-redirect" value="' . esc_attr( $args['redirect'] ) . '">' . $args['content'] . '</button>';
		
	}
	
	/**
	 * Returns html for button that triggers a specific action hook.
	 *
	 * @param	array	$args {
	 * 		@type		string		$content		Html to display as button / link content.
	 * 		@type		string		$event			String to identify dismiss event. The action triggered will be
	 *												"{$manager_id}_user_notice_dismissed_{$notice_id}_{$event}" and the dismissing
	 *												user id is passed as an argument to the action. Leave unset for no specific action to be fired.
	 * 		@type		array		$classes		Array of classes for the button. Default is array( anm-link ) which styles as a link.
	 * }
	 */
	public static function dismiss_event_button( array $args ) {
		
		$defaults = array(
			'content'	=>	'Undefined',
			'event'		=>	'',
			'classes'	=>	array( 'anm-link' )
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		$classes = array( 'anm-dismiss', 'anm-event' );
		if ( ! empty( $args['classes'] ) && is_array( $args['classes'] ) ) {
			$classes = array_merge( $args['classes'], $classes );
		}
		$classes = implode( ' ', $classes );
		
		// Add button with value of redirect url.
		return '<button type="submit" class="' . $classes . '" name="anm-event" value="' . esc_attr( $args['event'] ) . '">' . $args['content'] . '</button>';
		
	}
	
	/**
	 * Saves notices to the database.
	 *
	 * @hooked		wp_redirect, shutdown
	 * @param		string		$location		Redirect location (only used by wp_redirect filter).
	 * @return		string		$location		Redirect location.
	 */
	public static function save_notices( $location = '' ) {
		
		if ( self::$save_notices ) {
		
			$notices = self::$notices;
			
			foreach ( $notices as $user_id => $user_notices ) {
				
				// Update or delete user meta as needed.
				if ( empty( $user_notices ) ) {
					delete_user_meta( $user_id, self::$manager_id . '_admin_notices' );
				} else {
					update_user_meta( $user_id, self::$manager_id . '_admin_notices', $user_notices );
				}
				
			}
			
			self::$save_notices = false;
		
		}
		
		return $location;
		
	}
	
	/**
	 * Amend added notice.
	 *
	 * Use when changing any of the parameters of a notice (including id) so that updated notice will be displayed for any users.
	 * who have previously had the notice set for them.
	 * @param		string		$old_notice_id		Current ID of notice to be amended.
	 * @param		array		$new_notice			Args of new notice to replace old. Leaving user_ids unset means that the notice will
	 *												be updated for any user that had the old notice. If user_ids is set, then only
	 *												users that had the old notice and are also permitted to view the new notice will get
	 *												the updated notice. The notice will be removed for any user that is not permitted to view it.
	 * @return		(void)|WP_Error					Returns WP_Error object if $new_notice could not be parsed.
	 */
	public static function amend_added_notice( $old_notice_id, $new_notice ) {
		
		// If not set, set user ids to empty array to indicate that any user that had the old notice should have the new notice.
		if ( ! isset( $new_notice['user_ids'] ) ) {
			$new_notice['user_ids'] = array();
		}
		
		$new_notice = self::parse_notice_args( $new_notice );
		
		if ( is_wp_error( $new_notice ) ) {
			return $new_notice;
		}
		
		$new_notice_id = $new_notice['id'];
		unset( $new_notice['id'] );

		// Convert any roles to user ids and unset notice parameter.
		$new_notice_user_ids = self::parse_user_roles( $new_notice['user_ids'] );
		unset( $new_notice['user_ids'] );
		
		$user_ids = self::get_users_with_added_notices();
		
		if ( ! empty( $user_ids ) ) {

			foreach ( $user_ids as $user_id ) {
				
				// Remove the old notice without firing action hook.
				$removed = self::delete_added_user_notice( $old_notice_id, $user_id, $event = false, $prevent_action = true );
				
				// Add in new notice if it should be displayed to this user.
				if ( $removed ) {
					if ( empty( $new_notice_user_ids ) || in_array( $user_id, $new_notice_user_ids ) ) {
						$notices = self::$notices;
						$notices[ $user_id ][ $new_notice_id ] = $new_notice;
						self::$notices = $notices;
					}
				}

			}
			
		}
		
	}
	
	/**
	 * Amend opt out dismissal notice id.
	 *
	 * Use when changing any of the id of an opt out notice so that corresponding dimissals stored in usermeta are updated.
	 * @param		string		$old_notice_id		Current ID of notice to be amended.
	 * @param		string		$new_notice_id		New ID of notice to be amended.
	 */
	public static function amend_opt_out_dismissal_notice_id( $old_notice_id, $new_notice_id ) {
		
		$user_ids = self::get_users_with_opt_out_dismissals();
		
		if ( ! empty( $user_ids ) ) {
			foreach ( $user_ids as $user_id ) {
				
				$dismissed_notice_ids = get_user_meta( $user_id, self::$manager_id . '_opt_out_notice_dismissals', true );
				$key = array_search( $old_notice_id, $dismissed_notice_ids );
				
				if ( $key !== false ) {
					// Update the notice id.
					$dismissed_notice_ids[ $key ] = $new_notice_id;;
					update_user_meta( $user_id, self::$manager_id . '_opt_out_notice_dismissals', $dismissed_notice_ids );
				}
				
			}
		}
		
	}
	
	/**
	 * Display user notices.
	 *
	 * @hooked admin_notices
	 */
	public static function display_notices() {
		
		$screen_id = self::get_current_screen_id();
		$user_id = get_current_user_id();
		
		self::display_opt_out_notices( $screen_id, $user_id );
		
		self::display_added_notices( $screen_id, $user_id );
		
	}
	
	/**
	 * Display opt out notices.
	 *
	 * @access	private
	 * @param		int		$screen_id		Current screen id (0 if not known).
	 * @param		int		$user_id		Current user id.
	 */
	private static function display_opt_out_notices( $screen_id, $user_id ) {
		
		global $post;
		$post_id = is_object( $post ) ? $post->ID : 0;
		
		$notices = self::$opt_out_notices;
		
		if ( ! empty( $notices ) ) {
			
			foreach ( $notices as $notice_id => $notice ) {

				$notice['id'] = $notice_id;
				
				// If screen ids have been specified, check whether notice should be displayed
				if ( ! empty( $notice['screen_ids'] ) ) {
					if ( ! in_array( $screen_id, $notice['screen_ids'] ) ) {
						continue;
					}
				}
				
				// If post ids have been specified, check whether notice should be displayed
				if ( ! empty( $notice['post_ids'] ) ) {
					if ( ! in_array( $post_id, $notice['post_ids'] ) ) {
						continue;
					}
				}
				
				// If user ids have been specified, check whether notice should be displayed to this user
				if ( ! empty( $notice['user_ids'] ) ) {
					
					// Convert roles to user ids
					$user_ids = self::parse_user_roles( $notice['user_ids'] );
					
					if ( ! in_array( $user_id, $user_ids ) ) {
						continue;
					}
					
				}
				
				// Check whether user has already dismissed this notice
				$opt_out_dismissals = get_user_meta( $user_id, self::$manager_id . '_opt_out_notice_dismissals', true );
				if ( ! empty( $opt_out_dismissals ) ) {
					if ( in_array( $notice['id'], $opt_out_dismissals ) ) {
						continue;
					}
				}
				
				// Display the notice with option to filter display.
				if ( apply_filters( self::$manager_id . '_display_opt_out_notice', true, $notice ) ) {
					self::display_notice( $notice );
					// Remove notice once viewed if this is a one-time notice.
					if ( ! $notice['persistent'] ) {
						self::dismiss_opt_out_notice( $notice['id'], $user_id );
					}
				}
				
			}
			
		}
		
	}
	
	/**
	 * Display added user notices.
	 *
	 * @access	private
	 * @param		int		$screen_id		Current screen id (0 if not known).
	 * @param		int		$user_id		Current user id.
	 */
	private static function display_added_notices( $screen_id, $user_id ) {
		
		global $post;
		$post_id = is_object( $post ) ? $post->ID : 0;
		
		$notices = self::$notices;

		// Get user notices if not already loaded.
		if ( ! isset( $notices[ $user_id ] ) ) {
			$notices[ $user_id ] = get_user_meta( $user_id, self::$manager_id . '_admin_notices', true );
		}

		if ( ! empty( $notices[ $user_id ] ) ) {
			
			foreach ( $notices[ $user_id ] as $id => $notice ) {

				if ( empty( $notice['screen_ids'] ) || in_array( $screen_id, $notice['screen_ids'] ) ) {
					if ( empty( $notice['post_ids'] ) || in_array( $post_id, $notice['post_ids'] ) ) {
						
						// Display the notice.
						$notice['id']= $id;
						self::display_notice( $notice );
						
						// Remove notice once viewed if this is a one-time notice.
						if ( ! $notice['persistent'] ) {
							unset( $notices[ $user_id ][ $id ] );
							self::$save_notices = true; // Set flag to save notices.
						}
						
					}
				}

			}
			
			// Update the notices with any changes and save.
			if ( self::$save_notices ) {
				self::$notices = $notices;
			}
			
		}

	}
	
	/**
	 * Display notice.
	 *
	 * @access	private
	 * @param		array	$notice		array of notice parameters.
	 */
	private static function display_notice( $notice ) {

		// Add classes to the notice container as needed.
		$container_classes = array(
			'notice',
			'notice-' . esc_attr( $notice['type'] ),
		);
		if ( $notice['dismissable'] ) {
			$container_classes[] = 'is-dismissible';
		}
		if ( $notice['persistent'] || $notice['dismiss_all'] ) {
			$container_classes[] = 'notice-manager-ajax';
		}
		
		if ( ! empty( $notice['wrap_tag'] ) ) {
			$notice['message'] = '<' . $notice['wrap_tag'] . '>' . $notice['message'] . '</' . $notice['wrap_tag'] . '>';
		}
		
		// Display the notice.
		?>
		<div class="<?php echo implode( ' ', $container_classes ); ?>">
			<form class="anm-form" action="<?php echo admin_url( 'admin-ajax.php?action=' . self::$manager_id . '_dismiss_admin_notice' ); ?>" method="post">
				<?php if ( in_array( 'notice-manager-ajax', $container_classes ) ) { ?>
				<input type="hidden" class="anm-id" value="<?php echo esc_attr( self::$manager_id ); ?>" />
				<input type="hidden" class="anm-notice-id" name="noticeID" value="<?php echo esc_attr( self::$manager_id . '-' . $notice['id'] ); ?>" />
				<noscript><input type="hidden" name="anm-no-js" value="1" /></noscript>
				<?php wp_nonce_field( self::$manager_id . '_dismiss_admin_notice', 'nonce-anm-' . self::$manager_id . '-' . $notice['id'] );
				}
				if ( $notice['no_js_dismissable'] ) {
				?><noscript><table><tr><td style="width: 100%"></noscript><?php
				}
				echo $notice['message'];
				if ( $notice['no_js_dismissable'] ) {
				?>
				<noscript>
					</td>
					<td>
						<button type="submit" class="notice-dismiss"><span class="screen-reader-text"><?php _e( 'Dismiss this notice.' ); ?></span></button>
					</td></tr></table>
				</noscript>
				<?php } ?>
			</form>
		</div>
		<?php
	}
	
	/**
	 * Process ajax call to dismiss notice.
	 */
	public static function dismiss_notice() {

		if ( isset( $_POST['noticeID'] ) ) {

			$notice_id = sanitize_text_field( $_POST['noticeID'] );
		
			// Check nonce.
			check_ajax_referer( self::$manager_id . '_dismiss_admin_notice', 'nonce-anm-' . $notice_id );
			
			// Sanitize message ID after stripping off the '[manager_id]-'.
			$notice_id = str_replace( self::$manager_id . '-', '', sanitize_text_field( $_POST['noticeID'] ) );

			// Get notice info.
			if ( $user = wp_get_current_user() ) {
			
				// Get event if there was one.
				$event = isset( $_POST['anm-event'] ) ? sanitize_text_field( $_POST['anm-event'] ) : false;
				
				// Delete added notice if required
				$user_notices = get_user_meta( $user->ID, self::$manager_id . '_admin_notices', true );
				
				if ( isset( $user_notices[ $notice_id ] ) ) {

					// Delete notice (and trigger event for this user).
					self::delete_added_user_notice( $notice_id, $user->ID, $event );
					
					// Delete from all other users if required.
					if ( $user_notices[ $notice_id ]['dismiss_all'] ) {
						self::delete_added_notice_from_all_users( $notice_id );
					}
					
				}
				
				// Dismiss opt out notice if required
				self::dismiss_opt_out_notice( $notice_id, $user->ID, $event );
			}

		}
		
		if ( isset( $_POST['anm-no-js'] ) ) {
			
			// If a redirect has been set, use it.
			if ( isset( $_POST['anm-redirect'] ) ) {
				if ( ! empty( $_POST['anm-redirect'] ) ) {
					wp_safe_redirect( $_POST['anm-redirect'] );
					exit();
				}
			}
			
			// If not redirected, go back to where we came from.
			wp_safe_redirect( wp_get_referer() );
			exit();
		}
		
		wp_die();
		
	}
	
	/**
	 * Add dismissal to a user for an opt out notice.
	 *
	 * @access	private
	 * @param		string		$notice_id		Unique ID of message.
	 * @param		int			$user_id		User id for whom message should be dismissed.
	 * @return		bool		$dismissed		True if notice was dismissed successfully.
	 */
	private static function dismiss_opt_out_notice( $notice_id, $user_id, $event = false ) {
		
		$dismissed = false;
		if ( ! $notices = get_user_meta( $user_id, self::$manager_id . '_opt_out_notice_dismissals', true ) ) {
			$notices = array();
		}
		
		if ( ! in_array( $notice_id, $notices ) ) {
			$notices[] = $notice_id;
			$dismissed = update_user_meta( $user_id, self::$manager_id . '_opt_out_notice_dismissals', $notices );
		}
		
		// Allow for other actions on dismissal of notice.
		if ( $dismissed ) {
			do_action( self::$manager_id . '_user_notice_dismissed_' . $notice_id, $user_id );
			if ( $event ) {
				do_action( self::$manager_id . '_user_notice_dismissed_' . $notice_id . '_' . $event, $user_id );
			}
		}
		
		return $dismissed;
		
	}
	
	/**
	 * Delete added notice from user.
	 *
	 * @param		string		$notice_id		Unique ID of message.
	 * @param		int			$user_id		User id from whom message should be deleted.
	 * @param		string		$event			If set, then an action is fired on deletion in the form
	 *											{self::$manager_id}_user_notice_dismissed_{$notice_id}_{$event}
	 * @param		bool		$prevent_action	Whether to prevent firing of action.
	 * @return		bool		$removed		True if removed successfully.
	 */
	public static function delete_added_user_notice( $notice_id, $user_id, $event = false, $prevent_action = false ) {
		
		$removed = false;
		
		$notices = self::$notices;
		
		// Load user's current notices if not already set.
		if ( ! isset( $notices[ $user_id ] ) ) {
			$notices[ $user_id ] = get_user_meta( $user_id, self::$manager_id . '_admin_notices', true );
		}
		
		// If notice exists for this user...
		if ( isset( $notices[ $user_id ][ $notice_id ] ) ) {
			
			// Remove notice.
			unset( $notices[ $user_id ][ $notice_id ] );
			$removed = true;
			
			// Set to save notices.
			self::$notices = $notices;
			self::$save_notices = true;
		}
		
		// Allow for other actions on dismissal of notice.
		if ( $removed && ! $prevent_action ) {
			do_action( self::$manager_id . '_user_notice_dismissed_' . $notice_id, $user_id );
			if ( $event ) {
				do_action( self::$manager_id . '_user_notice_dismissed_' . $notice_id . '_' . $event, $user_id );
			}
		}

		return $removed;
		
	}
	
	/**
	 * Delete added notice from all users.
	 *
	 * @param		string		$notice_id		Unique ID of message.
	 */
	public static function delete_added_notice_from_all_users( $notice_id ) {
		
		// Get all users with notices.
		$user_ids = self::get_users_with_added_notices();

		// Remove notice from each user.
		if ( ! empty( $user_ids ) ) {

			foreach ( $user_ids as $user_id ) {
				self::delete_added_user_notice( $notice_id, $user_id );
			}
			
		}
		
		// Allow for other actions on dismissal of notice from all users.
		do_action( self::$manager_id . '_notice_dismissed_all_users_' . $notice_id );
		
	}
	
	/**
	 * Get all user ids with added notices.
	 *
	 * @access	private
	 * @return		array		Array of user ids.
	 */
	private static function get_users_with_added_notices() {
	
		// Get all user ids with notices stored in db.
		$args = array(
			'meta_query'	=>	array(
									array(
										'key'			=>	self::$manager_id . '_admin_notices',
										'compare'		=>	'!=',
										'value'			=>	''
									)
								),
			'fields'		=>	'ID'
		);
		$user_ids = get_users( $args );
		
		// Check self::$notices for users that were not identified by the db check as notices may have been added and not yet saved to db.
		$notices = self::$notices;
		if ( ! empty( $notices ) ) {
			foreach ( $notices as $user_id => $notice ) {
				
				// Add user id if not already there
				if ( ! in_array( $user_id, $user_ids ) ) {
					$user_ids[] = $user_id;
				}

			}
		}
		
		return $user_ids;
		
	}
	
	/**
	 * Get all user ids with opt out dismissals.
	 *
	 * @access	private
	 * @return		array		Array of user ids.
	 */
	private static function get_users_with_opt_out_dismissals() {
	
		$args = array(
			'meta_query'	=>	array(
									array(
										'key'			=>	self::$manager_id . '_opt_out_notice_dismissals',
										'compare'		=>	'!=',
										'value'			=>	''
									)
								),
			'fields'		=>	'ID'
		);

		return get_users( $args );
		
	}
	
	/**
	 * Delete all notices from a user.
	 *
	 * @param		int			$user_id		User id from whom notices should be deleted.
	 */
	public static function delete_added_user_notices( $user_id ) {

		$notices = self::$notices;
		
		// Remove notices.
		$notices[ $user_id ] = array();

		// Set to save notices.
		self::$notices = $notices;
		self::$save_notices = true;
		
	}
	
	/**
	 * Remove all added notices from all users.
	 */
	public static function remove_all_added_notices() {
		self::$notices = array();
		delete_metadata( 'user', 0, self::$manager_id . '_admin_notices', false, true );
	}
	
	/**
	 * Remove opt out dismissals from a user.
	 *
	 * @param		int		$user_id		User ID from whom to remove dismissals. Set to 0 for all users.
	 * @param		array	$notice_ids		Array of notice ids to remove
	 */
	public static function remove_opt_out_dismissals( $user_id = 0, array $notice_ids ) {
		
		if ( ! empty( $notice_ids ) ) {
			
			if ( $dismissed_notice_ids = get_user_meta( $user_id, self::$manager_id . '_opt_out_notice_dismissals', true ) ) {
				
				$update = false;
				foreach ( $notice_ids as $notice_id ) {
					if ( $matched_key = array_search( $notice_id, $dismissed_notice_ids ) !== false ) {
						unset( $dismissed_notice_ids[ $matched_key ] );
						$update = true;
					}
				}
				
				if ( $update ) {
					if ( empty( $dismissed_notice_ids ) ) {
						delete_user_meta( $user_id, self::$manager_id . '_opt_out_notice_dismissals' );
					} else {
						update_user_meta( $user_id, self::$manager_id . '_opt_out_notice_dismissals', $dismissed_notice_ids );
					}
				}
				
			}
			
		}
		
		delete_metadata( 'user', $user_id, self::$manager_id . '_opt_out_notice_dismissals', false, true );
	}
	
	/**
	 * Remove all opt out dismissals from a user.
	 *
	 * @param		int		$user_id		User ID from whom to remove dismissals. Set to 0 for all users.
	 */
	public static function remove_all_opt_out_dismissals( $user_id = 0 ) {
		delete_metadata( 'user', $user_id, self::$manager_id . '_opt_out_notice_dismissals', false, true );
	}
	
	/**
	 * Remove all data from the database. (Can be called on plugin uninstall, for example.)
	 */
	public static function remove_all_data() {
		self::remove_all_added_notices();
		self::remove_all_opt_out_dismissals();
	}

}