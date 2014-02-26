<?php
session_start();
/**
 * Plugin Name: Wordpress GitHub Stats Widget
 * Description: Provides template functions to show GitHub statistics
 * Author: Human Made Limited
 * Author URI: http://hmn.md/
 */
// phpinfo();
if( !function_exists('es_preit') ) {
	function es_preit( $obj, $echo = true ) {
		if( $echo ) {
			echo '<pre>';
			print_r( $obj );
			echo '</pre>';
		} else {
			return '<pre>' . print_r( $obj, true ) . '</pre>';
		}
	}
}

if( !function_exists('es_silent') ) {
	function es_silent( $obj ) {
	  	?>
	    <div style="display: none">
	        <pre><?php print_r( $obj ); ?></pre>
	    </div>
	    <?php
	}
}


require_once ('class.github.php');

/**
 * Add Github username field to the profile.
 */
function hmg_github_user_field( $contactmethods ) {
	$contactmethods['github'] = 'Github username';
	return $contactmethods;
}
add_filter( 'user_contactmethods', 'hmg_github_user_field' );


/**
 * Get an aggregate number of commits from all of a users repos for the last 30 days.
 *
 * @return [type] [description]
 */
function hmg_get_commits_for_day( $day ) {

	// No configuration set. :(
	if ( ! defined( 'HMG_USERNAME' ) || ! defined( 'HMG_PASSWORD' ) || ! defined( 'HMG_ORGANISATION' ) )
		return;

	set_time_limit(0);

	// The day is not over!
	if ( $day == strtotime( 'today') )
		return;

	// Create the days array. j
	$commits = array();

	$username = HMG_USERNAME;
	$password = HMG_PASSWORD;
	$organisation = HMG_ORGANISATION;

	$github = new HMGitHub( $username, $password );
	$github->organisation = $organisation;
	$github->authenticate();

	// We need to make sure there aren't any duplicate repos.
	$repos = array();
	foreach ( (array) $github->get_repos() as $repo )
		$repos[] = $repo->name;

	if ( empty( $repos ) )
		return null;

	foreach ( array_unique( $repos ) as $key => $repo ) {

		$branches = $github->get_branches( $repo );

		foreach ( $branches as $branch ) {

			$still_has_paginated_commits = true;

			$sha = null;

			while ( $still_has_paginated_commits ) {

				$response = $github->get_repo_commits( $repo, $branch->name, $sha ); //more paginated commits (sha defines start point of returned results)

				if ( ! $response )
					break;

				foreach ( $response as $commit ) {

					//If something is wrong, stop cycling
					if ( empty( $commit->sha ) || $sha == $commit->sha ) {

						$still_has_paginated_commits = false;
						break;
					}

					$sha = $commit->sha;
					$committed_date = strtotime( $commit->commit->author->date );

					if ( $committed_date >= $day + 60*60*24 || in_array( $commit->sha, $commits ) ) {

					//If we've gone too far back in time in the commit tree, stop cycling through the pagination
					} elseif ( $committed_date < $day ) {

						$still_has_paginated_commits = false;
						break;

					//If everything looks in order, add the commit to the count
					} else {

						$commits[] = $commit->sha;
					}

				}

				$still_has_paginated_commits = ( count( $response ) >= 30 && $still_has_paginated_commits ) ? true : false;
			}

		}
	}

	return array_unique( (array) $commits );

};


/**
 * Get Commits by day.
 *
 * Goes back as far as records began. Stored in an option.
 * Loops through the past 30 days, and fills in any gaps.
 *
 * @return array Commits by day. Key is commit day, value is array of commit SHAs.
 */
function hmg_update_commits_by_day() {

	//Period of time to check over. Make sure last 30 days is complete. Just in case one is incomplete.
	$day_to_check = strtotime( '30 days ago' );
	$commits = get_option( 'hmg_commits_by_day', array() );

	// Loop through the days, and check whether that days data exists. If not - get it.
	// Note only 1 day of data is collected each time this function is called.
	for ( $i = strtotime( 'yesterday' ); $i > $day_to_check; $i = $i - 60*60*24 ) {

		//take into account daylight savings, check whether that day exists
		if ( ! array_key_exists( $i, $commits ) && ! array_key_exists( $i + ( 60*60 ), $commits ) && ! array_key_exists( $i - ( 60*60 ), $commits ) ) {

			$response = hmg_get_commits_for_day( $i );

			if ( ! is_null( $response ) )
				$commits[$i] = $response;

			ksort( $commits );
			update_option( 'hmg_commits_by_day', $commits );

		}

	}

	return $commits;
}


/**
 * Format the commit data for display.
 *
 * 1 month of data, with only commit day and only commit count.
 *
 * @return array 30 days of commits, Key is timestamp of day, value is count of commits for that day.
 */
function hmg_get_formatted_commits_for_month() {

	$commits = hmg_update_commits_by_day();

	ksort( $commits );

	$commits = array_slice( array_reverse( $commits ), 0, 30 );

	foreach( $commits as $day => $day_commits )
		$r[ $day ] = count( $day_commits );

	if ( count( $r ) < 30 )
		$r = array_pad( $r, 30, 0);

	return $r;

}


/**
 * Helper function for getting formatted commits by month.
 * Uses tlc transiets to update the value in the background.
 *
 * @return array 30 days of commits, Key is timestamp of day, value is count of commits for that day.
 */
function hmg_get_formatted_commits_for_month_cached() {

	$timeout = 60*60*4; // 4 Hours

	$commits = (array) tlc_transient( 'hmg_formatted_commits_by_day' )
		->updates_with( 'hmg_get_formatted_commits_for_month' )
		->expires_in( $timeout )
		->background_only()
		->get();

	return $commits;

}


/**
 * Output the Commit count by day data variable script.
 * Inserted into the footer.
 * Used to build graphs.
 *
 * @return null
 */
function hmg_commits_by_day_script() {

	$commits = hmg_get_formatted_commits_for_month_cached();

	?>
		<script type="text/javascript">commits_cumulative = [<?php echo implode( ',', array_reverse( $commits ) ); ?>];</script>
	<?php
}
// add_action( 'wp_footer', 'hmg_commits_by_day_script' );


/**
 * Get the average number of commits per day over the last 30 days.
 *
 * @return int
 */
function hmg_commits_by_day_average() {

	$commits = hmg_get_formatted_commits_for_month_cached();
	return round( array_sum( $commits ) / count( $commits ), 1 );

}


/**
 * Get the current user info.
 * Wrapper for the api that caches the result in a transient for 1 day.
 *
 * @return object User/Org info.
 */
function hmg_get_user_info() {

	if ( ! defined( 'HMG_ORGANISATION' ) )
		return null;

	$username = HMG_ORGANISATION;
	$github = new HMGitHub( $username );
	return $github->get_user_info();

}


/**
 * Get the cached current user info.
 * hmg_get_user_info() is used by tlc transients to update the value in the background.
 *
 * @return object User/Org info.
 */
function hmg_get_cached_user_info() {

	if ( ! defined( 'HMG_ORGANISATION' ) )
		return null;

	$username = HMG_ORGANISATION;
	$info = (array) tlc_transient( 'hmg_get_user_info_' . $username )
		->updates_with( 'hmg_get_user_info' )
		->expires_in( 60 * 60 * 24 )
		->background_only()
		->get();

	return $info;

}


// $github = HMGithubOAuth::get_instance();

function add_oauth_link() {
	$bacon = md5('bacon');
	$_SESSION['bacon'] = $bacon;
	?>
	<h3>GitHub Authentication</h3>
	<label for="hmnmd-github-client">Client ID</label>
	<input id="hmnmd-github-client" type="text" class="js-github-clientid">
	<a href="https://github.com/login/oauth/authorize?client_id=2eb6275b2d66359b13ac&amp;state=<?php echo $bacon; ?>&amp;scope=repo">Get started with authentication</a>
	<?php
}
// add_action( 'show_user_profile', 'add_oauth_link' );


// add_action( 'init', 'parse_gets');
function parse_gets() {
	if($_SESSION['bacon'] === $_GET['state']) {
		$_SESSION['code'] = $_GET['code'];
	}

	// let's get the token
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
				'client_id' => '2eb6275b2d66359b13ac',
				'client_secret' => '569b087c47c31bd5ac3861ae5f3dac73a41dce2d',
				'code' => $_SESSION['code']
			),
			'cookies' => array()
	    )
	);
	$_SESSION['access_token'] = $_POST['access_token'];
	wp_die( es_preit( array( $response ), false ) );
}



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

	/**
	 * Let's kick everything off
	 */
	function __construct() {
		global $current_user;
		get_currentuserinfo();

		// wp_die( es_preit( array( $current_user ), false ) );
		self::$user = $current_user;
		self::$client_id = get_option( 'hm_client_id', '' );
		self::$client_secret = get_option( 'hm_client_secret', '' );
		self::$token = get_option( 'hm_token', '' );
		self::$errors = get_option( 'hm_last_error', false );

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
	 * Stores the client id and client secret on an update profile action
	 * @param  integer $user_id the current user id
	 * @return void
	 */
	public function store_github_creds( $user_id ) {
		if (self::$user->ID === $user_id) {
			$client_id = $_REQUEST['hm_client_id'];
			$client_secret = $_REQUEST['hm_client_secret'];
			if ($client_id !== '') {
				update_option('hm_client_id', $client_id);
			}
			if ($client_secret !== '') {
				update_option('hm_client_secret', $client_secret);
			}
		}
	}


	/**
	 * Responsible for grabbing the code returned from Github and POST exchanging it
	 * to an access token.
	 */
	public function exchange_code_for_token() {
		if (self::$user->ID === 1) {
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
			$response_array = wp_parse_args( $response['body'] );
			if ( array_key_exists( 'access_token', $response_array ) ) {
				delete_option( 'hm_last_error' );
				update_option( 'hm_token', $response_array['access_token'] );
			} elseif ( array_key_exists( 'error', $response_array ) ) {
				$reponse_string = '<p><pre>' . $response_array['error'] . ':</pre> ';
				$reponse_string .= $response_array['error_description'] . '. ';
				$reposne_string .= '<a target="_blank" href="' . $response_array['error_uri'] . '">Click for more info</a>.';
				update_option( 'hm_token', '' );
				update_option( 'hm_last_error', $response_string );
			}
		}
	}


	/**
	 * Responsible for outputting the extra fields into the profile page of user 1.
	 */
	public function add_oauth_link() {
		if (self::$user->ID === 1) {
			$_SESSION['hm_state'] = md5( time() . $client_secret );
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
							<span class="description">If this is here, you're good to go! :)</span></td>
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
					?>
				</tbody>
			</table>
			<?php
		}
	}


	/**
	 * Gets instance, and returns, or just returns the instance.
	 * @return object an instance of the class
	 */
	function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}
}
add_action( 'plugins_loaded', array( 'HMGithubOAuth', 'get_instance' ) );
