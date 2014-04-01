<?php

/**
 * Plugin Name: Wordpress GitHub Stats Widget
 * Description: Provides template functions to show GitHub statistics
 * Author: Human Made Limited
 * Author URI: http://hmn.md/
 */

require_once dirname( __FILE__ ) . '/tlc-transients.php';
require_once dirname( __FILE__ ) . '/class.github.widget.php';

/**
 * CLASS to handle OAuth interfacing with Github
 */
class HMGithubOAuth {
	// The instance
	protected static $instance;

	// The current user
	protected static $user = null;

	// The github credentials
	protected static $token;
	protected static $is_authenticated = false;

	// Github API Settings
	protected static $gh_auth_url = 'https://github.com/login/oauth/authorize';
	protected static $gh_api_url = 'https://api.github.com';

	// Github API Responses
	protected static $gh_user = false;
	protected static $gh_organisations = false;
	protected static $gh_the_organisation;
	protected static $gh_repositories;
	protected static $total_stats;
	protected static $daily_stats;


	/**
	 * Let's kick everything off
	 */
	public function __construct() {
		global $current_user;
		get_currentuserinfo();
		self::$user = $current_user;
		self::$token = get_option( 'hm_personal_token', '' );
		self::$gh_the_organisation = get_option( 'hm_the_organisation', '' );

		// I need to always display the admin side of things
		add_action( 'show_user_profile', array( $this, 'add_oauth_link' ) );

		// And I need to always be able to store shit
		add_action( 'personal_options_update', array( $this, 'store_github_creds' ) );

		if( self::$token !== '' ) {
			// This fetches the user and the orgs
			self::get_basic_data();

			$authexpiry = 5 * MINUTE_IN_SECONDS;
			self::$is_authenticated = self::get_hm_option( 'hm_authenticated', array( $this, 'is_authenticated' ), false, $authexpiry );
			if(!self::$is_authenticated) {
				self::$is_authenticated = self::is_authenticated();
			}
		}

		// start empty, will be populated from transient
		self::$gh_repositories = array();

		// start empty, will be populated from transient
		self::$total_stats = array();

		// If I have the organisations selected, let's get the repository data
		if(!empty(self::$gh_the_organisation)) {
			foreach (self::$gh_the_organisation as $url) {
				// $url is the link to the organisation.
				self::$gh_repositories[$url] = self::get_hm_option( 'hm_repositories_in_org_' . md5($url), array( $this, 'get_repos' ), array( $url ) );
			}
		}


		/**
		 * Pyramid of DOOM
		 *
		 * For all the chosen organisations (if it's not empty), and for all the repositories within
		 * those organisations (if that is not false (pending), or empty), get the stats for them
		 */
		if(!empty( self::$gh_repositories) ) {

			foreach (self::$gh_repositories as $url => $repos) {

				// url is the organisation, repos is the repositories belonging to that organisation
				if(!empty($repos) && false !== $repos) {

					// to get the data for each individual repository, we need to iterate over them
					foreach ($repos as $repo) {

						// This contains the stats with timestamps
						self::$total_stats[$repo->url] = self::get_hm_option( 'hm_total_stats_' . md5($repo->url), array( $this, 'get_repo_stat' ), array( $repo->url ) );
					}
				}
			}
		}
	}


	/**
	 * Gets user and organisation data if personal token is set.
	 */
	private function get_basic_data() {

		// Let's get the user and store it if we haven't yet
		self::$gh_user = self::get_hm_option( 'hm_user', array( $this, 'get_user' ), false );
		if(!self::$gh_user) {
			self::$gh_user = self::get_user();
		}
		// Let's get all the organisations and store it if we haven't yet
		if(self::$gh_user) {
			self::$gh_organisations = self::get_hm_option( 'hm_organisations', array( $this, 'get_organisations' ) );
			if(!self::$gh_organisations) {
				self::$gh_organisations = self::get_organisations();
			}
		}
	}


	/**
	 * Gets all the repositories for an organisation. Handles paginated Github query
	 * @return array all the repository data
	 */
	public function get_repos( $url ) {

		if(empty($url)) {
			return false;
		}

		$repos = array();

		$page = 1;
		$morepages = true;
		while ( $morepages ) {
			$fetch = add_query_arg( array(
				'access_token' => self::$token,
				'page' => $page
			), $url );

			$response = wp_remote_get( $fetch );

			if ( is_wp_error( $response ) || 200 !== intval( $response['response']['code'] ) ) {
				return null;
			}

			$reparray = json_decode($response['body']);

			$repos = array_merge( $repos, $reparray );
			if ( count( $reparray ) < 30 ) {
				$morepages = false;
			}
			$page += 1;
		}
		return $repos;
	}


	/**
	 * Queries Github's API for a specific repository's statistics.
	 *
	 * Response might be 202, which means Github is still compiling data. I'm retrying in that case
	 *
	 * @param  string $url  the api url of the repo
	 * @return array/object       the commit history of the repo for the last 1 year
	 */
	public function get_repo_stat( $url ) {
		// construct the url for commit activity
		$url = $url . '/stats/commit_activity';

		// let's add the access token to the url
		$fetch = add_query_arg( array(
			'access_token' => self::$token
		), $url );

		// first try
		$response = wp_remote_get( $fetch );

		// in case Github's down
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$response = wp_remote_get( $fetch );

		// If it's an error, or we've tried for too long, exit
		if (
			is_wp_error( $response )
			|| 202 === intval( $response['response']['code'] )
		) {
			return false;
		}

		$repo_daily_stats = self::calculate_aggregate_daily_stats( json_decode( $response['body'] ) );

		return $repo_daily_stats;
	}


	/**
	 * Once we have all the commit stats of all the repositories, let's sanitize that, and put each commit data
	 * into the appropriately indexed daily key.
	 *
	 * We also need to cut off future events. (Github's returning future keys, assuming it's giving me a full
	 * year even if the repo is 4 months old.)
	 * @return array sanitized daily stats
	 */
	public function calculate_aggregate_daily_stats( $repostats ) {
		$d = 60 * 60 * 24;
		$_stats = array();

		foreach ($repostats as $week) {
			foreach ($week->days as $index => $day) {
				$_k = $week->week + ($index * $d);
				if(array_key_exists($_k, $_stats)) {
					$_stats[$_k] += $day;
				} else {
					$_stats[$_k] = $day;
				}
			}
		}

		foreach ($_stats as $key => $value) {
			if ($key > time() ) {
				unset( $_stats[$key] );
			}
		}

		return $_stats;
	}


	/**
	 * Wrapper around the tlc transient functionality
	 * @param  string  			$name       	name of the option / transient we're fetching
	 * @param  string/array  	$update     	name of the function to handle updating
	 * @param  int  			$expires    	how long the transient lives in seconds. default is a day
	 * @param  boolean 			$background 	whether to update in background
	 * @return mixed              				the data stored
	 */
	public function get_hm_option( $name, $update, $args = null, $background = true, $expires = 86400  ) {
		if ( $background ) {
			$option = tlc_transient( $name )
				->updates_with( $update, $args )
				->expires_in( $expires )
				->extend_on_fail( 5 )
				->background_only()
				->get();
		} else {
			$option = tlc_transient( $name )
				->updates_with( $update, $args )
				->expires_in( $expires )
				->get();
		}
		return $option;
	}


	/**
	 * Wrapper function to get user from cache / Github
	 * @return array the user data
	 */
	public function get_user() {
		return self::fetch_data( 'user' );
	}


	/**
	 * Wrapper function to get organisation from cache / Github
	 * @return array organisation data
	 */
	public function get_organisations() {
		return self::fetch_data( 'orgs' );
	}


	/**
	 * Delete all the important bits when purging.
	 */
	private function purge_settings() {
		delete_option( 'hm_user' );
		delete_option( 'hm_the_organisation' );
		delete_option( 'hm_organisations' );
		delete_option( 'hm_personal_token' );

		delete_transient( 'tlc__' . md5( 'hm_authenticated' ) );
		delete_transient( 'tlc__' . md5( 'hm_user' ) );
		delete_transient( 'tlc__' . md5( 'hm_organisations' ) );
		delete_transient( 'timeout_tlc__' . md5( 'hm_authenticated' ) );
		delete_transient( 'timeout_tlc__' . md5( 'hm_user' ) );
		delete_transient( 'timeout_tlc__' . md5( 'hm_organisations' ) );
		return;
	}


	/**
	 * Stores the client id and client secret on an update profile action
	 * @param  integer $user_id the current user id
	 * @return void
	 */
	public function store_github_creds( $user_id ) {
		if (self::$user->ID === $user_id && $_REQUEST['submit']) {

			if ($_REQUEST['hm_purge_creds'] === 'on' ) {
				return self::purge_settings();
			}

			if (array_key_exists( 'hm_personal_token', $_REQUEST ) ) {
				update_option('hm_personal_token', $_REQUEST['hm_personal_token']);
			}

			if (array_key_exists( 'hm_the_organisation', $_REQUEST ) ) {
				if( self::$gh_the_organisation !== $_REQUEST['hm_the_organisation']) {
					delete_transient( 'tlc__' . md5( 'hm_repositories' ) );
				}

				update_option('hm_the_organisation', $_REQUEST['hm_the_organisation']);
			}
		}
	}


	/**
	 * Responsible for outputting the extra fields into the profile page of user 1.
	 */
	public function add_oauth_link() {
		if (self::$user->ID === 1) {
			$nonce = wp_create_nonce( '_hm_github_oauth' );

			?>
			<h3>Github Auth Details</h3>
			<table class="form-table">
				<tbody>
					<tr>
						<th>
							<label for="hm_personal_token">Personal Token</label>
						</th>
						<td>
							<input id="hm_personal_token" name="hm_personal_token" type="text" class="regular-text" size="16" value="<?php echo self::$token; ?>" /><br>
							<span class="description">You'll need to register a new <a href="https://github.com/settings/applications">personal token</a> with repo, public_repo and read:org and paste the token here.</span>
						</td>
					</tr>

					<?php
					if ( self::$token ) {
						?>

						<tr>
							<th><label for="hm_the_organisation">Select an Organisation</label></th>
							<td>
								<?php
								if ( self::$gh_organisations ) {
									foreach ( self::$gh_organisations as $org) {
										$_v = $org->repos_url;
										?>
										<label for="hm_the_org_<?php echo $org->login; ?>">
											<input id="hm_the_org_<?php echo $org->login; ?>" type="checkbox" name="hm_the_organisation[]" value="<?php echo $_v; ?>" <?php self::multi_checked(self::$gh_the_organisation, $_v); ?>>
										<?php echo $org->login;?></label><br/>
										<?php
									}
								} else {
									?>
										<span class="description">Please wait, fetching data. Reload the page to try again.</span></td>
									<?php
								}
								?>
							</td>
						</tr>
						<?php
					}
					if ( self::$is_authenticated ) {
						?>
						<tr>
							<th><label>Authenticated?</label></th>
							<td>Yes</td>
						</tr>
						<?php
					} else {
						?>
						<tr>
							<th><label>Authenticated?</label></th>
							<td>Nope</td>
						</tr>
						<?php
					}
					?>
					<tr>
						<th><label for="hm_purge_creds">Purge settings?</label></th>
						<td>
							<input type="checkbox" name="hm_purge_creds" id="hm_purge_creds">
						</td>
					</tr>
				</tbody>
			</table>
			<?php
		}
	}


	/**
	 * Gets data from the Github API. Route depends on the $type being passed in.
	 * Values are from the user object. It will only be called if the access token
	 * is set.
	 * @param  string 			$type 		what we want to query for
	 * @return array/object       			decoded json response
	 */
	public function fetch_data( $type ) {

		$url = null;
		switch( $type ) {
			case 'user':
				$url = self::$gh_api_url . '/user';
				break;
			case 'orgs':
				$url = self::$gh_user->organizations_url;
				break;
			default:
				$url = false;
		}

		if(!$url) {
			return false;
		}


		$fetch = add_query_arg( array( 'access_token' => self::$token ), $url );

		$response = wp_remote_get( $fetch );
		if ( is_wp_error( $response ) || 200 !== intval( $response['response']['code'] ) ) {
			return null;
		}

		return json_decode( $response['body'] );
	}


	/**
	 * Check if we're still authenticated. Query the user. If 200, we're good, if anything
	 * else, the token was removed, etc. If wp_error, network problem.
	 * @return mixed booleam / wp error
	 */
	public function is_authenticated() {

		$url = self::$gh_api_url . '/user';
		$fetch = add_query_arg( array( 'access_token' => self::$token ), $url );
		$response = wp_remote_get( $fetch );

		// if it's not a 200, there's an auth problem
		if ( is_wp_error( $response ) || 200 !== intval ($response['response']['code'] ) ) {
			return false;
		}

		// otherwise we're good
		return true;
	}


	/**
	 * Method through which the widget can communicate with the internals of
	 * this class.
	 * @return array the daily statistics
	 */
	public function get_stats_for_widget( $orgs = array() ) {
		if( !is_array( $orgs ) ) {
			return false;
		}

		$temp = array();

		/**
		 * Second pyramid of DOOM
		 */
		if(empty(self::$gh_repositories) || !is_array(self::$gh_repositories)) {
			return $temp;
		}
		foreach (self::$gh_repositories as $org => $repositories) {
			// wp_die( es_preit( array( self::$gh_repositories ), false ) );
			if ( in_array( $org, $orgs ) ) {
				foreach ($repositories as $repo) {
					if (array_key_exists( $repo->url, self::$total_stats ) ) {
						if(empty(self::$total_stats[$repo->url])) {
							continue;
						}
						foreach (self::$total_stats[$repo->url] as $day => $commit) {
							if(!array_key_exists( $day, $temp ) ) {
								$temp[$day] = $commit;
							} else {
								$temp[$day] += $commit;
							}
						}
					}
				}
			}
		}

		return $temp;
	}


	/**
	 * Reduces the organisations array to show only the ones that are available
	 * @param  object $element an organisation's data
	 * @return boolean          whether it's in or out
	 */
	private function get_current_organisations( $element ) {
		if(!is_array(self::$gh_the_organisation)) {
			return false;
		}
		return in_array( $element->repos_url, self::$gh_the_organisation );
	}


	/**
	 * Returns all the organisations that the widget should be able to select
	 * @return array 				an array of objects
	 */
	public function get_orgs_for_widget() {
		$filtered_orgs = false;
		if(self::$gh_organisations && is_array(self::$gh_organisations)) {

			$filtered_orgs = array_filter( self::$gh_organisations, array( $this, 'get_current_organisations' ) );
		}
		return $filtered_orgs;
	}


	/**
	 * A helper function for multi checkboxes
	 * @param  array 			$is    		what we have stored in the database
	 * @param  string 			$input 		individual value of the checkbox
	 * @return void        					echoes
	 */
	public function multi_checked( $is, $input ) {
		if ( is_array( $is ) ) {
			if( in_array( $input, $is ) ) {
				echo ' checked="checked"';
			}
		}
	}


	/**
	 * Gets instance, and returns, or just returns the instance.
	 * @return object an instance of the class
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}
}

add_action( 'plugins_loaded', array( 'HMGithubOAuth', 'get_instance' ) );
