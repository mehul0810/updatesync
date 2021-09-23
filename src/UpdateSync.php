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
	 * @param array $args List of arguments.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function __construct( $args ) {
		$defaults = [
			'file'    => '',
			'slug'    => '',
			'version' => '',
			'github'  => [
				'username'     => '',
				'repository'   => '',
				'access_token' => '',
			],
		];

		$args = wp_parse_args( $args, $defaults );

		$this->file_path    = $args['file'];
		$this->username     = $args['github']['username'];
		$this->repo         = $args['github']['repository'];
		$this->access_token = $args['github']['access_token'];
		$this->slug         = plugin_basename( $this->file_path );
		$this->version      = $args['version'];

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
	 * Check for updates.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return bool|object
	 */
	public function check_for_updates() {
		$update = false;

		// Get plugin data and GitHub release information.
		$this->init_plugin_data();
		$this->get_release_information();

		$can_update = version_compare( $this->api_response->tag_name, $this->version, '>' );

		if ( $can_update ) {
			$update = (object) array(
				'slug'          => $this->slug,
				'plugin'        => $this->slug,
				'new_version'   => $this->api_response->tag_name,
				'url'           => '',
				'package'       => $this->api_response->zipball_url,
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => '',
				'requires_php'  => '',
				'compatibility' => new \stdClass(),
			);
		}

		return $update;
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
	public function set_plugin_transient( $transient ) {
		$update = $this->check_for_updates();

		if ( $update ) {
			// Update is available.
			$transient->response[ $this->slug ] = $update;
		} else {
			// No update is available.
			$item = (object) array(
				'slug'          => $this->slug,
				'plugin'        => $this->slug,
				'new_version'   => $this->api_response->tag_name,
				'url'           => '',
				'package'       => '',
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => '',
				'requires_php'  => '',
				'compatibility' => new \stdClass(),
			);
			// Adding the "mock" item to the `no_update` property is required
			// for the enable/disable auto-updates links to correctly appear in UI.
			$transient->no_update[ $this->slug ] = $item;
		}

		return $transient;
	}

	/**
	 * Set plugin information to view plugin details.
	 *
	 * @param bool   $data     Plugin response data.
	 * @param string $action   Action.
	 * @param object $response Response.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return object
	 */
	public function set_plugin_information( $data, $action, $response ) {
		// Bailout, if `action` is not `plugin_information`.
		if ( 'plugin_information' !== $action ) {
			return $data;
		}

		// Bailout, if the plugin slug doesn't match.
		if (
			! isset( $response->slug ) ||
			$response->slug !== $this->slug
		) {
			return $data;
		}

		// Get plugin data & GitHub release information.
		$this->init_plugin_data();
		$this->get_release_information();

		// Update response with our plugin information.
		$response->last_updated      = $this->api_response->published_at;
		$response->slug              = $this->slug;
		$response->name              = $this->data['Name'];
		$response->version           = $this->api_response->tag_name;
		$response->author            = $this->data['AuthorName'];
		$response->homepage          = $this->data['PluginURI'];
		$response->short_description = $this->data['Description'];
		$response->requires_php      = $this->data['RequiresPHP'];
		$response->requires          = $this->data['Requires'];

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
	public function post_install( $true, $hook_extra, $result ) {
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
