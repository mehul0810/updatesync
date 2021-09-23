<?php
/**
 * UpdateSync | Updater class to update WordPress plugins.
 *
 * @since   1.0.0
 * @package UpdateSync
 * @author  Mehul Gohil <hello@mehulgohil.com>
 */

namespace UpdateSync;

// Bailout, if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Class UpdateSync
 *
 * This updater class will handle all the automatic updates thingy
 * for the WordPress plugins hosted via GitHub.
 *
 * @since 1.0.0
 */
class UpdateSync {
	/**
	 * Plugin Slug.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var $slug
	 */
	private $slug;

	/**
	 * Plugin Data
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var $data
	 */
	private $data;

	/**
	 * GitHub Username
	 *
	 * Use the GitHub username where the plugin repository is hosted.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var $username
	 */
	private $username;

	/**
	 * GitHub Repository Name.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var $repo
	 */
	private $repo;

	/**
	 * File path of the plugin.
	 *
	 * Use `__FILE__` path of the plugin.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @var $file_path
	 */
	private $file_path;

	/**
	 * GitHub API Result.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var $api_response
	 */
	private $api_response;

	/**
	 * GitHub Access Token.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var $access_token
	 */
	private $access_token;

	/**
	 * Constructor for UpdateSync class.
	 *
	 * @param string $file_path    File path of the plugin.
	 * @param string $username     GitHub Username.
	 * @param string $repo         Github Repository.
	 * @param string $access_token GitHub Access Token.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function __construct( $file_path, $username, $repo, $access_token = '' ) {
		$this->file_path    = $file_path;
		$this->username     = $username;
		$this->repo         = $repo;
		$this->access_token = $access_token;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'set_plugin_transient' ] );
		add_filter( 'plugins_api', [ $this, 'set_plugin_information' ], 10, 3 );
		add_filter( 'upgrader_post_install', [ $this, 'post_install' ], 10, 3 );
	}

	/**
	 * Get Installed Plugin Data.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @return void
	 */
	private function init_plugin_data():void {
		$this->slug = plugin_basename( $this->file_path );
		$this->data = get_plugin_data( $this->file_path );
	}

	/**
	 * Get Plugin Latest Release Data from GitHub.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	private function get_release_information():void {
		// Make sure you fetch release information once.
		if ( ! empty( $this->api_response ) ) {
			return;
		}

		// GitHub API URL to fetch the latest release.
		$url = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases/latest";

		// For private repository, we need access token to fetch data from GitHub.
		if ( ! empty( $this->access_token ) ) {
			$url = add_query_arg(
				[
					'access_token' => $this->access_token,
				],
				$url
			);
		}

		$response      = wp_remote_get( $url );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $response_code ) {
			$this->api_response = json_decode( wp_remote_retrieve_body( $response ) );
		}
	}

	/**
	 * Store latest plugin version information in transient.
	 *
	 * @param object $transient Transient details.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return object
	 */
	public function set_plugin_transient( object $transient ):object {
		// Check whether the transient is checked or not.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Get plugin data and GitHub release information.
		$this->init_plugin_data();
		$this->get_release_information();

		// Check the versions if we need to do an update
		$can_update = version_compare( $this->api_response->tag_name, $transient->checked[$this->slug] );

		if ( $can_update ) {
			$package = $this->api_response->zipball_url;

			// For private repository, we need access token to fetch data from GitHub.
			if ( ! empty( $this->access_token ) ) {
				$package = add_query_arg(
					[
						'access_token' => $this->access_token,
					],
					$package
				);
			}

			// Setup transient data.
			$transient_data                     = new \stdClass();
			$transient_data->slug               = $this->slug;
			$transient_data->new_version        = $this->api_response->tag_name;
			$transient_data->url                = $this->data['PluginURI'];
			$transient_data->package            = $package;
			$transient->response[ $this->slug ] = $transient_data;
		}

		return $transient;
	}

	/**
	 * Set plugin information to view plugin details.
	 *
	 * @param bool   $false    Plugin Information.
	 * @param string $action   Action.
	 * @param object $response Response.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return object
	 */
	public function set_plugin_information( $false, $action, $response ): object {
		// Get plugin data & GitHub release information.
		$this->init_plugin_data();
		$this->get_release_information();

		// Bailout, if no data.
		if ( empty( $response->slug ) || $response->slug != $this->slug ) {
			return false;
		}

		// Update response with our plugin information.
		$response->last_updated = $this->api_response->published_at;
		$response->slug         = $this->slug;
		$response->plugin_name  = $this->data['Name'];
		$response->version      = $this->api_response->tag_name;
		$response->author       = $this->data['AuthorName'];
		$response->homepage     = $this->data['PluginURI'];

		// Get download link from GitHub data.
		$download_link = $this->api_response->zipball_url;

		// For private repository, we need access token to fetch data from GitHub.
		if ( ! empty( $this->access_token ) ) {
			$download_link = add_query_arg(
				[
					'access_token' => $this->access_token,
				],
				$download_link
			);
		}

		$response->download_link = $download_link;

		return $response;
	}

	/**
	 * Install the plugin once the ZIP is ready.
	 *
	 * @param bool  $true True.
	 * @param array $hook_extra Hook Extra.
	 * @param array $result Response.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return array
	 */
	public function post_install( $true, $hook_extra, array $result ):array {
		global $wp_filesystem;

		// Get plugin data.
		$this->init_plugin_data();

		// Check if the plugin was previously activated.
		$was_activated = is_plugin_active( $this->slug );

		// Get plugin directory.
		$plugin_dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->slug );

		// Move plugin ZIP to destination.
		$wp_filesystem->move( $result['destination'], $plugin_dir );
		$result['destination'] = $plugin_dir;

		// Re-activate plugin, if required.
		if ( $was_activated ) {
			activate_plugin( $this->slug );
		}

		return $result;
	}
}
