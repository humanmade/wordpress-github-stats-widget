<?php
/**
 * Plugin Name: Wordpress GitHub Stats Widget
 * Description: Provides template functions to show GitHub statistics
 * Author: Human Made Limited
 * Author URI: http://hmn.md/
 */

require_once dirname( __FILE__ ) . '/utils.php';
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
	protected static $client_id;
	protected static $client_secret;
	protected static $token;
	protected static $errors;

	// Github API Settings
	protected static $gh_auth_url = 'https://github.com/login/oauth/authorize';
	protected static $gh_api_url = 'https://api.github.com';

	// Github API Responses
	protected static $gh_user;
	protected static $gh_organisations;
	protected static $gh_the_organisation;
	protected static $gh_repositories;
	protected static $is_authenticated;
	protected static $total_stats;
	protected static $daily_stats;


	/**
	 * Let's kick everything off
	 */
	public function __construct() {
		global $current_user;
		get_currentuserinfo();

		// how often should we check for authorized status
		$authexpiry = 30 * MINUTE_IN_SECONDS;


		self::$user = $current_user;
		self::$client_id = get_option( 'hm_client_id', '' );
		self::$client_secret = get_option( 'hm_client_secret', '' );
		self::$token = get_option( 'hm_token', '' );
		self::$errors = get_option( 'hm_last_error', false );
		self::$gh_user = self::get_hm_option( 'hm_user', array( $this, 'get_user' ) );
		self::$gh_organisations = self::get_hm_option( 'hm_organisations', array( $this, 'get_organisations' ), false );
		self::$gh_the_organisation = get_option( 'hm_the_organisation', '' );
		self::$gh_repositories = self::get_hm_option( 'hm_repositories_' . self::$gh_the_organisation, array( $this, 'get_repos' ) );
		self::$is_authenticated = self::get_hm_option( 'hm_authenticated', array( $this, 'is_authenticated' ), true, $authexpiry );

		// only run these when there's a point in running them
		if(self::$gh_repositories) {
			self::$total_stats = self::get_hm_option( 'hm_total_stats', array( $this, 'get_stats' ) );
		}

		if(self::$total_stats) {
			self::$daily_stats = self::get_hm_option( 'hm_daily_stats', array( $this, 'calculate_aggregate_daily_stats'));
		}

		/**
		 * User related bits
		 */
		// Output the necessary fields
		add_action( 'show_user_profile', array( $this, 'add_oauth_link' ) );

		// Save the client ID and client secrets if userid = 1
		add_action( 'personal_options_update', array( $this, 'store_github_creds' ) );

		// Capture the code and exchange for tokens
		add_action( 'init', array( $this, 'exchange_code_for_token' ) );
	}


	/**
	 * Get the last year's commit activity for each repository we have stored
	 * @return array see above line
	 */
	public function get_stats() {
		// placeholder array
		$stats = array();
		foreach (self::$gh_repositories as $repository) {
			$stats[$repository->name] = tlc_transient( 'hm_stats_' . md5($repository->name ) )
				->updates_with( array( $this, 'get_repo_stat' ), array( $repository->url, md5($repository->name ) ) )
				->expires_in( 86400 )
				->background_only()
				->get();
		}
		return $stats;
	}

	/**
	 * Incremental sleep seconds. This is used in case Github returns with a 202 instead of a 200
	 * when asking for commit statistics
	 * @param  integer $step larger than 1
	 * @return integer       still larger than 1
	 */
	function timeout_secs( $step ) {
		// force it to be an integer
		$step = intval( $step );

		// totally arbitrary
		if ( $step < 0 ) {
			$step = 4;
		}

		$base = 1.4;
		return ceil( pow( $base, $step ) );
	}


	/**
	 * Queries Github's API for a specific repository's statistics.
	 *
	 * Response might be 202, which means Github is still compiling data. I'm retrying in that case
	 *
	 * @param  string $url  the api url of the repo
	 * @param  string $name the name of the repo
	 * @return array/object       the commit history of the repo for the last 1 year
	 */
	public function get_repo_stat( $url, $name ) {
		// construct the url for commit activity
		$url = $url . '/stats/commit_activity';

		// needed in case we need to sleep()
		$step = 0;

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

		// As long as Github's working on it...
		while( 202 === intval( $response['response']['code'] ) ) {
			$sleepfor = self::timeout_secs($step);
			wp_mail('gabor@javorszky.co.uk', 'sleeping', 'sleeping for ' . $sleepfor . ' seconds while fetching ' . $url);
			$step++;
			sleep($sleepfor);
			$response = wp_remote_get( $fetch );

			if ( is_wp_error( $response ) ) {
				return null;
			}
		}

		return json_decode( $response['body'] );
	}


	/**
	 * Once we have all the commit stats of all the repositories, let's sanitize that, and put each commit data
	 * into the appropriately indexed daily key.
	 *
	 * We also need to cut off future events. (Github's returning future keys, assuming it's giving me a full
	 * year even if the repo is 4 months old.)
	 * @return array sanitized daily stats
	 */
	public function calculate_aggregate_daily_stats() {
		$d = 60 * 60 * 24;
		$_stats = array();

		foreach (self::$total_stats as $reponame => $repostats) {
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
		}

		// Kill the future
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
	public function get_hm_option( $name, $update, $background = true, $expires = 86400  ) {
		if ( $background ) {
			$option = tlc_transient( $name )
				->updates_with( $update )
				->expires_in( $expires )
				->background_only()
				->get();
		} else {
			$option = tlc_transient( $name )
				->updates_with( $update )
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
	 * Gets all the repositories for an organisation. Handles paginated Github query
	 * @return array all the repository data
	 */
	public function get_repos() {
		// If we don't have an organisation, let's not return anything
		if (!isset(self::$gh_the_organisation ) ) {
			return false;
		}

		$url = self::$gh_the_organisation;
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
	 * Stores the client id and client secret on an update profile action
	 * @param  integer $user_id the current user id
	 * @return void
	 */
	public function store_github_creds( $user_id ) {
		if (self::$user->ID === $user_id) {

			if (array_key_exists( 'hm_purge_creds', $_REQUEST ) && $_REQUEST['hm_purge_creds'] === 'on' ) {

				delete_option( 'hm_client_id' );
				delete_option( 'hm_client_secret' );
				delete_option( 'hm_token' );
				delete_option( 'hm_user' );
				delete_option( 'hm_last_error' );
				delete_option( 'hm_the_organisation' );
				delete_option( 'hm_organisations' );
				return;
			}
			if (array_key_exists( 'hm_client_id', $_REQUEST ) ) {
				update_option('hm_client_id', $_REQUEST['hm_client_id']);
			}


			if (array_key_exists( 'hm_client_secret', $_REQUEST ) ) {
				update_option('hm_client_secret', $_REQUEST['hm_client_secret']);
			}


			if (array_key_exists( 'hm_the_organisation', $_REQUEST ) ) {
				if(self::$gh_the_organisation !== $_REQUEST['hm_the_organisation'] ) {

				}
				update_option('hm_the_organisation', $_REQUEST['hm_the_organisation']);
			}
		}
	}


	/**
	 * Responsible for grabbing the code returned from Github and POST exchanging it
	 * to an access token.
	 */
	public function exchange_code_for_token() {
		if (self::$user->ID === 1 && array_key_exists('code', $_GET) && array_key_exists('state', $_GET) ) {
			$code = $_GET['code'];
			$state = $_GET['state'];
			if ( $_SESSION['hm_state'] !== $state || !$code ) {
				return;
			}

			$response = wp_remote_post(
				'https://github.com/login/oauth/access_token',
				array(
					'method' => 'POST',
					'timeout' => 45,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking' => true,
					'headers' => array(),
					'body' => array(
						'client_id' => self::$client_id,
						'client_secret' => self::$client_secret,
						'code' => $_GET['code']
					),
					'cookies' => array()
			    )
			);

			// if Github is down or there's another problem
			if ( is_wp_error( $response ) ) {
				return null;
			}

			$response_array = wp_parse_args( $response['body'] );

			// If there's an access token in there
			if ( array_key_exists( 'access_token', $response_array ) ) {

				// Let's store the token
				self::$token = $response_array['access_token'];

				// Let's do some housekeeping first
				delete_option( 'hm_last_error' );
				update_option( 'hm_token', $response_array['access_token'] );

				// Let's get the user connected to the token
				$user_data = self::fetch_data( 'user' );

				self::$gh_user = $user_data;

				// Let's get the organisations the user belongs to
				$organisations = self::fetch_data( 'organizations_url' );
				self::$gh_organisations = $organisations;

				update_option( 'hm_organisations', $organisations );
				update_option( 'hm_user', $user_data );

			// If there's an error in there
			} elseif ( array_key_exists( 'error', $response_array ) ) {

				$reponse_string = '<p><pre>' . $response_array['error'] . ':</pre> ';
				$reponse_string .= $response_array['error_description'] . '. ';
				$reposne_string .= '<a target="_blank" href="' . $response_array['error_uri'] . '">Click for more info</a>.';
				delete_option( 'hm_token' );
				delete_option( 'hm_user' );
				delete_option( 'hm_organisations' );
				update_option( 'hm_last_error', $response_string );
				self::$token = undefined;
			}
		}
	}


	/**
	 * Responsible for outputting the extra fields into the profile page of user 1.
	 */
	public function add_oauth_link() {
		if (self::$user->ID === 1) {
			$_SESSION['hm_state'] = md5( time() . self::$client_secret );
			?>
			<h3>Github Auth Details</h3>
			<table class="form-table">
				<tbody>
					<tr>
						<th>
							<label for="hm_client_id">Client ID</label>
						</th>
						<td>
							<input id="hm_client_id" name="hm_client_id" type="text" class="regular-text" size="16" value="<?php echo self::$client_id; ?>" /><br>
							<span class="description">You'll need to register a new <a href="https://github.com/settings/applications">developer application in Github</a>, and get the Client ID and Client secret to these two fields.</span>
						</td>
					</tr>
					<tr>
						<th><label for="hm_client_secret">Client secret</label></th>
						<td>
							<input id="hm_client_secret" name="hm_client_secret" type="text" class="regular-text" size="16" value="<?php echo self::$client_secret; ?>" />
						</td>
					</tr>
					<?php
					if ( self::$token ) {
						?>
						<tr>
							<th><label>Token</label></th>
							<td><input type="text" value="<?php echo self::$token; ?>" disabled="disabled" class="regular-text" size="16"  /><br>
							<span class="description">Welcome, <?php echo self::$gh_user->name; ?>! You have successfully authenticated.</span></td>
						</tr>
						<tr>
							<th><label for="hm_the_organisation">Select an Organisation</label></th>
							<td>
								<select name="hm_the_organisation" id="hm_the_organisation">
									<option value="0">Please select one</option>
									<?php
									foreach ( self::$gh_organisations as $org) {
										$_v = $org->repos_url;
										?>
										<option value="<?php echo $_v; ?>" <?php selected( self::$gh_the_organisation, $_v ); ?>><?php echo $org->login; ?></option>
										<?php
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="hm_purge_creds">Purge settings?</label></th>
							<td>
								<input type="checkbox" name="hm_purge_creds" id="hm_purge_creds">
							</td>
						</tr>
						<?php
					}
					if( self::$errors ) {
						?>
						<tr>
							<th><label>Error</label></th>
							<td><?php echo self::$errors; ?> You need to reauthorize.</td>
						</tr>
						<?php
					}
					if( self::$client_id !== '' && self::$client_secret !== '' && ( self::$token === '' || self::$errors ) ) {
						?>
						<tr>
							<th><label>Authorize Github</label></th>
							<td>
								<a href="https://github.com/login/oauth/authorize?client_id=<?php echo self::$client_id; ?>&amp;state=<?php echo $_SESSION['hm_state']; ?>&amp;scope=repo">Get started with authentication</a>
							</td>
						</tr>
						<?php
					}

					if ( self::$is_authenticated ) {
						?>
						<tr>
							<th><label for="">Authenticated?</label></th>
							<td>Yes</td>
						</tr>
						<?php
					} else {
						?>
						<tr>
							<th><label for="">Authenticated?</label></th>
							<td>Nope</td>
						</tr>
						<?php
					}

					if ( self::$gh_repositories ) {
						?>
						<tr>
							<td><label>Repositories</label></td>
							<td>
								<p>
									<?php

									foreach (self::$gh_repositories as $repo) {
										echo $repo->name . '<br />';
									}
									?>
								</p>
							</td>
						</tr>
						<?php
					} else {
						?>
						<tr>
							<th><label for="">Repositories</label></th>
							<td>
								<p>Fetching the data from Github, it might take a while.</p>
							</td>
						</tr>
						<?php
					}
					?>
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
	public function get_stats_for_widget() {
		return self::$daily_stats;
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
