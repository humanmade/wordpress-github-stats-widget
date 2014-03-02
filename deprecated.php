<?php
/**
 * Class that contains the entire functionality
 * - OAuth authentication for a user
 * - organisation, repo and stat syncing
 * - cache handling
 */
class HMGitHub {

    public  $username;
    private $token;
    public  $method;
    public  $github;

    function __construct( $username, $secret = null, $method = '' ) {

        $this->username = $username;
        $this->secret = $secret;
        $this->method = $method;

        $this->api_base_url  = 'https://api.github.com';
        $this->request_args = array();

        $this->request_args['timeout'] = 30;

    }

    /**
     * Get a response from the API
     *
     * Note that this is not used for pagingation of commits.
     * You must provide the commit SHA for the first commit of the page as a query arg.
     *
     * @param  string $url The request url
     * @param  int $page Used for pagination of comments etc.
     * @return [type]      [description]
     */
    // public function get( $url, $page = 1 ) {

    //  if( $page > 1 )
    //      $url = add_query_arg( 'page', $page, $url );

    //  if( isset( $this->per_page ) )
    //      $url = add_query_arg( 'per_page', $this->per_page, $url );

    //  $response = wp_remote_request( $url, $this->request_args );

    //  if ( is_wp_error( $response ) || '200' != $response['response']['code'] )
    //      return null;

    //  return json_decode( $response['body'] );

    // }


    /**
     * Get a user information.
     *
     * @param  string $username the username to return info for.
     * @return object user info
     */
    // public function get_user_info( $username = null ) {

    //  if( is_null( $username ) )
    //      $username = $this->username;

    //  if ( ! empty( $this->organisation ) )
    //      $url = $this->api_base_url . '/orgs/' . $this->organisation;

    //  elseif ( $this->is_authenticated() && $username == $this->username )
    //      $url = $this->api_base_url . '/user';

    //  else
    //      $url = $this->api_base_url . '/users/' . $username;

    //  $this->user_info = $this->get( $url );

    //  return $this->user_info;

    // }


    /**
     * Get all user repositories.
     *
     * @param  string $username optionally pass a different username.
     * @return object User Repositories
     */
    public function get_repos( $username = null ) {

        if( is_null( $username ) )
            $username = $this->username;

        if ( ! empty( $this->organisation ) )
            $url = $this->api_base_url . '/orgs/' . $this->organisation . '/repos';

        elseif ( $this->is_authenticated() )
            $url = $this->api_base_url . '/user/repos';

        else
            $url = $this->api_base_url . '/users/' . $username . '/repos';

        $theres_more_repos = true;
        $repos = array();
        $page = 0;

        while ( $theres_more_repos ) {

            $paginated_result = $this->get( $url . '?page=' . $page );

            $repos = array_merge( $paginated_result, $repos );

            $page++;

            $theres_more_repos = ( count($paginated_result ) >= 30 ) ? true : false;
        }

        return $this->user_repos = $repos;
    }

    /**
     * Get all commits for a repository.
     *
     * @param  string  $repo_name The name of the repository
     * @param  integer $page      Page. Note use $this->per_page to set number of results per page.
     * @return object             Full Commit information.
     */
    public function get_repo_commits( $repo_name, $branch = null, $sha = null ) {

        if ( ! empty( $this->organisation ) )
            $repo_owner = $this->organisation;

        else
            $repo_owner = $this->username;

        $url = $this->api_base_url . '/repos/' . $repo_owner . '/' . $repo_name . '/commits';

        if ( ! is_null( $sha ) )
            $url = add_query_arg( 'sha', $sha, $url );

        $data = $this->get( $url );

        return $data;
    }

    public function get_branches( $repo_name ) {

        if ( ! empty( $this->organisation ) )
            $repo_owner = $this->organisation;

        else
            $repo_owner = $this->username;

        $branches = $this->get( $this->api_base_url . '/repos/' . $repo_owner . '/' . $repo_name . '/branches' );

        if ( ! is_array( $branches ) )
            $branches = array( $branches );

        return $branches;
    }

    /**
     * Authenticate a user.
     *
     * @return bool success/faliure.
     */
    // public function authenticate() {

    //  if ( empty( $this->username ) || empty( $this->secret ) )
    //      return;

    //  $this->request_args['headers'] = array( 'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->secret ) );

    // }


    // private function is_authenticated() {

    //  return isset( $this->request_args['headers']['Authorization'] );

    // }






}
/**
 * Add Github username field to the profile.
 */
// function hmg_github_user_field( $contactmethods ) {
//  $contactmethods['github'] = 'Github username';
//  return $contactmethods;
// }
// add_filter( 'user_contactmethods', 'hmg_github_user_field' );


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
add_action( 'wp_footer', 'hmg_commits_by_day_script' );


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
// function hmg_get_user_info() {

//  if ( ! defined( 'HMG_ORGANISATION' ) )
//      return null;

//  $username = HMG_ORGANISATION;
//  $github = new HMGitHub( $username );
//  return $github->get_user_info();

// }


/**
 * Get the cached current user info.
 * hmg_get_user_info() is used by tlc transients to update the value in the background.
 *
 * @return object User/Org info.
 */
// function hmg_get_cached_user_info() {

//  if ( ! defined( 'HMG_ORGANISATION' ) )
//      return null;

//  $username = HMG_ORGANISATION;
//  $info = (array) tlc_transient( 'hmg_get_user_info_' . $username )
//      ->updates_with( 'hmg_get_user_info' )
//      ->expires_in( 60 * 60 * 24 )
//      ->background_only()
//      ->get();

//  return $info;

// }


// public function store_repositories() {
//      $d = 60 * 60 * 24;
//      $repositories = self::fetch_data( 'repos' );
//      // wp_die( es_preit( array( 'derp' ), false ) );


//      $_stats = array();

//      // oh god the horror
//      foreach ($repositories as $repo) {
//          $repostats = self::fetch_data( 'stats', $repo->url . '/stats/commit_activity' );
//          foreach ($repostats as $week) {
//              foreach ($week->days as $index => $day) {
//                  $_stats[$week->week + ($index * $d)] += $day;
//              }
//          }
//      }
//  }
//
//
//
    // public function hmg_get_cached_user_info() {
    //  if ( !self::is_authenticated() ) {
    //      return null;
    //  }

    //  $info = (array) tlc_transient( 'hmg_get_user_info_' . self::$user )
    //      ->updates_with( array( $this, 'hmg_get_user_info' ) )
    //      ->expires_in( 60 * 60 * 24 )
    //      ->background_only()
    //      ->get();

    //  return $info;

    // }

    /**
     * Get the current user info.
     * Wrapper for the api that caches the result in a transient for 1 day.
     *
     * @return object User/Org info.
     */
    // function hmg_get_user_info() {



    //  $username = HMG_ORGANISATION;
    //  $github = new HMGitHub( $username );
    //  return $github->get_user_info();

    // }


