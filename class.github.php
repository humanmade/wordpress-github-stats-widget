<?php

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
	public function get( $url, $page = 1 ) {

		if( $page > 1 )
			$url = add_query_arg( 'page', $page, $url );

		if( isset( $this->per_page ) )
			$url = add_query_arg( 'per_page', $this->per_page, $url );

		$response = wp_remote_request( $url, $this->request_args );

		if ( is_wp_error( $response ) || '200' != $response['response']['code'] )
			return null;

		return json_decode( $response['body'] );

	}


	/**
	 * Get a user information.
	 *
	 * @param  string $username the username to return info for.
	 * @return object user info
	 */
	public function get_user_info( $username = null ) {

		if( is_null( $username ) )
			$username = $this->username;

		if ( ! empty( $this->organisation ) )
			$url = $this->api_base_url . '/orgs/' . $this->organisation;

		elseif ( $this->is_authenticated() && $username == $this->username )
			$url = $this->api_base_url . '/user';

		else
			$url = $this->api_base_url . '/users/' . $username;

		$this->user_info = $this->get( $url );

		return $this->user_info;

	}


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
	public function authenticate() {

		if ( empty( $this->username ) || empty( $this->secret ) )
			return;

		$this->request_args['headers'] = array( 'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->secret ) );

	}


	private function is_authenticated() {

		return isset( $this->request_args['headers']['Authorization'] );

	}






}