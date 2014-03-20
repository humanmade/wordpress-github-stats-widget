<?php

/**
 * Plugin Name: Wordpress GitHub Stats Widget
 * Description: Provides template functions to show GitHub statistics
 * Author: Human Made Limited
 * Author URI: http://hmn.md/
 */

// require_once dirname( __FILE__ ) . '/utils.php';
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
		self::$gh_the_organisation = get_option( 'hm_the_organisation', '' );


		// cached, might change
		self::$gh_user = self::get_hm_option( 'hm_user', array( $this, 'get_user' ), false );
		self::$gh_organisations = self::get_hm_option( 'hm_organisations', array( $this, 'get_organisations' ), false );
		self::$is_authenticated = self::get_hm_option( 'hm_authenticated', array( $this, 'is_authenticated' ), false, $authexpiry );

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
	 * Gets all the repositories for an organisation. Handles paginated Github query
	 * @return array all the repository data
	 */
	public function get_repos( $url ) {
		// If we don't have an organisation, let's not return anything
		// if (empty(self::$gh_the_organisation ) ) {
		// 	return false;
		// }

		if(empty($url)) {
			return false;
		}
		// wp_die( es_preit( array( $url ), false ) );

		$repos = array();

		// foreach (self::$gh_the_organisation as $url) {
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
		// }
		// wp_die( es_preit( array( $repos ), false ) );
		return $repos;
	}


	/**
	 * Queries Github's API for a specific repository's statistics.
	 *
	 * Response might be 202, which means Github is still compiling data. I'm retrying in that case
	 *
	 * @param  string $url  the api url of the repo
	 * todelete@param  string $name the name of the repo
	 * @return array/object       the commit history of the repo for the last 1 year
	 */
	public function get_repo_stat( $url ) {
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

		// foreach (self::$total_stats as $reponame => $repostats) {
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
		// }

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

				delete_transient( 'tlc__' . md5( 'hm_authenticated' ) );
				delete_transient( 'tlc__' . md5( 'hm_user' ) );
				delete_transient( 'tlc__' . md5( 'hm_organisations' ) );

				return;
			}
			if (array_key_exists( 'hm_client_id', $_REQUEST ) ) {
				update_option('hm_client_id', $_REQUEST['hm_client_id']);
			}


			if (array_key_exists( 'hm_client_secret', $_REQUEST ) ) {
				update_option('hm_client_secret', $_REQUEST['hm_client_secret']);
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
	 * Responsible for grabbing the code returned from Github and POST exchanging it
	 * to an access token.
	 */
	public function exchange_code_for_token() {
		if (self::$user->ID === 1 && array_key_exists('code', $_GET) && array_key_exists('state', $_GET) ) {
			$code = $_GET['code'];
			$state = $_GET['state'];
			if( !wp_verify_nonce( $state, '_hm_github_oauth' ) ) {
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
			$nonce = wp_create_nonce( '_hm_github_oauth' );

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
							<td>
								<input type="text" value="<?php echo self::$token; ?>" disabled="disabled" class="regular-text" size="16"  /><br>
								<?php
								if ( self::$gh_user ) {
									?>
									<span class="description">Welcome, <?php echo self::$gh_user->name; ?>! You have successfully authenticated.</span></td>
									<?php
								} else {
									?>
									<span class="description">Please reload the page!</span></td>
									<?php
								}
								?>
						</tr>
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
								<a href="https://github.com/login/oauth/authorize?client_id=<?php echo self::$client_id; ?>&amp;state=<?php echo $nonce; ?>&amp;scope=repo">Get started with authentication</a>
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
					<?php
					// For debugging purposes only
					if ( 1 == 2 ) {
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
	public function get_stats_for_widget( $orgs = array() ) {
		if( !is_array( $orgs ) ) {
			return false;
		}

		$temp = array();

		/**
		 * Second pyramid of DOOM
		 * @var [type]
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

		return in_array( $element->repos_url, self::$gh_the_organisation );
	}


	/**
	 * Returns all the organisations that the widget should be able to select
	 * @return array 				an array of objects
	 */
	public function get_orgs_for_widget() {
		$filtered_orgs = false;
		if(self::$gh_organisations) {

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
		if( in_array( $input, $is ) ) {
			echo ' checked="checked"';
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
