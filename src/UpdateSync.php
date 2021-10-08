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
	 * File of the plugin.
	 *
	 * Use `__FILE__` path of the plugin.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @var $file
	 */
	private $file;

	/**
	 * File path of the plugin.
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
	 * Plugin Version.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var $version
	 */
	private $version;

	/**
	 * Can Update?
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var $can_update
	 */
	private $can_update;

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
			'file'       => '',
			'slug'       => '',
			'version'    => '',
			'github'     => [
				'username'     => '',
				'repository'   => '',
				'access_token' => '',
			],
			'can_update' => false,
		];

		$args = wp_parse_args( $args, $defaults );

		$this->file         = $args['file'];
		$this->username     = $args['github']['username'];
		$this->repo         = $args['github']['repository'];
		$this->access_token = $args['github']['access_token'];
		$this->file_path    = plugin_basename( $this->file );
		$this->slug         = $args['slug'];
		$this->version      = $args['version'];
		$this->can_update   = $args['can_update'];

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
		$this->data = get_plugin_data( $this->file );
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
			$response = wp_remote_get(
				$url,
				[
					'headers' => [
						'Accept'        => 'application/vnd.github.v3+json',
						'Authorization' => "token {$this->access_token}",
					],
				]
			);

		} else {
			$response = wp_remote_get( $url );
		}

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
	public function set_plugin_transient( $transient ) {

		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Get plugin data and GitHub release information.
		$this->init_plugin_data();
		$this->get_release_information();

		if(
			$this->api_response &&
			version_compare( $this->version, $this->api_response->tag_name, '<' )
			// version_compare( $this->api_response->requires, get_bloginfo( 'version' ), '<' ) &&
			// version_compare( $this->api_response->requires_php, PHP_VERSION, '<' )
		) {
			$response              = new \stdClass();
			$response->slug        = $this->slug;
			$response->plugin      = $this->file_path;
			$response->new_version = $this->api_response->tag_name;
			$response->package     = $this->can_update ?
				$this->get_download_file( $this->api_response->assets[0]->id, $this->api_response->assets[0]->name ) :
				'';

			$transient->response[ $this->file_path ] = $response;
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
			$response->slug !== $this->file_path
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
		$was_activated = is_plugin_active( $this->file_path );

		// Get plugin directory.
		$plugin_dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->file_path );

		// Move plugin ZIP to destination.
		$wp_filesystem->move( $result['destination'], $plugin_dir );
		$result['destination'] = $plugin_dir;

		// Re-activate plugin, if required.
		if ( $was_activated ) {
			activate_plugin( $this->file_path );
		}

		return $result;
	}

	/**
	 * Get Download File.
	 *
	 * @param string $id   Asset ID.
	 * @param string $name Asset Name.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return string
	 */
	public function get_download_file( $id, $name ):string {
		$url      = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases/assets/{$id}";
		$response = wp_remote_get(
			$url,
			[
				'headers' => [
					'Authorization' => "token {$this->access_token}",
					'Accept'        => 'application/octet-stream',
				],
			]
		);

		$response_body = wp_remote_retrieve_body( $response );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $response_code && ! is_wp_error( $response_body ) ) {
			$file = wp_upload_bits( $name, null, $response_body );

			return ! empty( $file['url'] ) ? $file['url'] : '';
		}

		return '';
	}
}
