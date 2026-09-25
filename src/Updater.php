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
class Updater {
	/**
	 * Plugin Slug.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var string
	 */
	private string $slug = '';

	/**
	 * Plugin Data
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var array<string, string|bool>
	 */
	private array $data = [];

	/**
	 * GitHub Username
	 *
	 * Use the GitHub username where the plugin repository is hosted.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var string
	 */
	private string $username = '';

	/**
	 * GitHub Repository Name.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var string
	 */
	private string $repo = '';

	/**
	 * File of the plugin.
	 *
	 * Use `__FILE__` path of the plugin.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @var string
	 */
	private string $file = '';

	/**
	 * File path of the plugin.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @var string
	 */
	private string $file_path = '';

	/**
	 * GitHub API Result.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var UpdateSyncRelease|null
	 */
	private ?object $api_response = null;

	/**
	 * Whether release information has already been requested for this instance.
	 *
	 * @var bool
	 */
	private bool $release_checked = false;

	/**
	 * GitHub Access Token.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var string
	 */
	private string $access_token = '';

	/**
	 * Plugin Version.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var string
	 */
	private string $version = '';

	/**
	 * Can Update?
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @var bool
	 */
	private bool $can_update = false;

	/**
	 * Constructor for UpdateSync class.
	 *
	 * @param array<string, mixed> $args List of arguments.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	public function __construct( array $args ) {
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

		$args        = wp_parse_args( $args, $defaults );
		$github_args = is_array( $args['github'] ) ? $args['github'] : [];
		$github_args = wp_parse_args( $github_args, $defaults['github'] );

		$this->file         = is_string( $args['file'] ) ? $args['file'] : '';
		$this->username     = is_string( $github_args['username'] ) ? $github_args['username'] : '';
		$this->repo         = is_string( $github_args['repository'] ) ? $github_args['repository'] : '';
		$this->access_token = is_string( $github_args['access_token'] ) ? $github_args['access_token'] : '';
		$this->file_path    = plugin_basename( $this->file );
		$this->slug         = is_string( $args['slug'] ) ? $args['slug'] : '';
		$this->version      = is_string( $args['version'] ) ? $args['version'] : '';
		$this->can_update   = is_bool( $args['can_update'] ) && $args['can_update'];

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'set_plugin_transient' ] );
		add_filter( 'plugins_api', [ $this, 'set_plugin_information' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'normalize_plugin_source' ], 10, 4 );
		add_filter( 'http_request_args', [ $this, 'add_asset_request_headers' ], 10, 2 );
	}

	/**
	 * Get Installed Plugin Data.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @return void
	 */
	private function init_plugin_data(): void {
		$this->data = get_plugin_data( $this->file );
	}

	/**
	 * Read a string-valued plugin header.
	 *
	 * @param string $key Plugin header key.
	 * @return string
	 */
	private function get_plugin_data_value( string $key ): string {
		$value = $this->data[ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Get Plugin Latest Release Data from GitHub.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return void
	 */
	private function get_release_information(): void {
		if ( $this->release_checked ) {
			return;
		}
		$this->release_checked = true;

		if ( empty( $this->username ) || empty( $this->repo ) ) {
			return;
		}

		// GitHub API URL to fetch the latest release.
		$url  = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->username ),
			rawurlencode( $this->repo )
		);
		$args = [
			'timeout' => 10,
			'headers' => [
				'Accept' => 'application/vnd.github+json',
			],
		];

		// For private repository, we need access token to fetch data from GitHub.
		if ( ! empty( $this->access_token ) ) {
			$args['headers']['Authorization'] = "Bearer {$this->access_token}";
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		if (
			JSON_ERROR_NONE !== json_last_error() ||
			! $release instanceof \stdClass ||
			empty( $release->tag_name ) ||
			! is_string( $release->tag_name )
		) {
			return;
		}

		$assets = [];
		if ( isset( $release->assets ) && is_array( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( $asset instanceof \stdClass ) {
					$assets[] = $asset;
				}
			}
		}

		$this->api_response = (object) [
			'tag_name'     => $release->tag_name,
			'published_at' => isset( $release->published_at ) && is_string( $release->published_at ) ? $release->published_at : '',
			'body'         => isset( $release->body ) && is_string( $release->body ) ? $release->body : '',
			'assets'       => $assets,
		];
	}

	/**
	 * Get a ZIP asset URL for the latest release.
	 *
	 * Private-repository downloads use the authenticated GitHub asset API URL.
	 * The HTTP request filter adds credentials only to that repository's asset endpoint.
	 *
	 * @return string
	 */
	private function get_release_download_url(): string {
		if ( null === $this->api_response || empty( $this->api_response->assets ) ) {
			return '';
		}

		foreach ( $this->api_response->assets as $asset ) {
			if ( ! isset( $asset->name ) || ! is_string( $asset->name ) || ! preg_match( '/\.zip$/i', $asset->name ) ) {
				continue;
			}

			if ( ! empty( $this->access_token ) && isset( $asset->url ) && is_string( $asset->url ) ) {
				return $asset->url;
			}

			if ( isset( $asset->browser_download_url ) && is_string( $asset->browser_download_url ) ) {
				return $asset->browser_download_url;
			}
		}

		return '';
	}

	/**
	 * Add GitHub headers only when downloading this repository's release assets.
	 *
	 * @param array<string, mixed> $args HTTP request arguments.
	 * @param string               $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function add_asset_request_headers( array $args, string $url ): array {
		if ( empty( $this->username ) || empty( $this->repo ) ) {
			return $args;
		}

		$parsed_url = wp_parse_url( $url );
		$asset_path = strtolower(
			sprintf(
				'/repos/%s/%s/releases/assets/',
				rawurlencode( $this->username ),
				rawurlencode( $this->repo )
			)
		);
		if ( ! is_array( $parsed_url ) ) {
			return $args;
		}

		$request_path = isset( $parsed_url['path'] ) ? strtolower( $parsed_url['path'] ) : '';
		$asset_id     = 0 === strpos( $request_path, $asset_path ) ? substr( $request_path, strlen( $asset_path ) ) : '';

		if (
			! isset( $parsed_url['scheme'] ) ||
			'https' !== strtolower( $parsed_url['scheme'] ) ||
			! isset( $parsed_url['host'] ) ||
			'api.github.com' !== strtolower( $parsed_url['host'] ) ||
			( isset( $parsed_url['port'] ) && 443 !== $parsed_url['port'] ) ||
			! preg_match( '/^[0-9]+$/', $asset_id )
		) {
			return $args;
		}

		if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = [];
		}
		$args['headers']['Accept'] = 'application/octet-stream';

		if ( ! empty( $this->access_token ) ) {
			$args['headers']['Authorization'] = "Bearer {$this->access_token}";
		}

		return $args;
	}

	/**
	 * Store latest plugin version information in transient.
	 *
	 * @param \stdClass&object{checked?: mixed, response?: array<string, \stdClass>} $transient Transient details.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return \stdClass&object{checked?: mixed, response?: array<string, \stdClass>}
	 */
	public function set_plugin_transient( \stdClass $transient ): \stdClass {

		if (
			empty( $transient->checked ) ||
			! is_array( $transient->checked ) ||
			! array_key_exists( $this->file_path, $transient->checked ) ||
			! $this->can_update
		) {
			return $transient;
		}

		// Get plugin data and GitHub release information.
		$this->init_plugin_data();
		$this->get_release_information();

		$current_version = ! empty( $this->version ) ? $this->version : $this->get_plugin_data_value( 'Version' );
		if ( null === $this->api_response || empty( $current_version ) || empty( $this->api_response->tag_name ) ) {
			return $transient;
		}

		if ( version_compare( $current_version, $this->api_response->tag_name, '<' ) ) {
			$package = $this->get_release_download_url();
			if ( empty( $package ) ) {
				return $transient;
			}

			$response              = new \stdClass();
			$response->slug        = $this->slug;
			$response->plugin      = $this->file_path;
			$response->new_version = $this->api_response->tag_name;
			$response->package     = $package;

			if ( ! isset( $transient->response ) ) {
				$transient->response = [];
			}
			$transient->response[ $this->file_path ] = $response;
		}

		return $transient;
	}

	/**
	 * Set plugin information to view plugin details.
	 *
	 * @param false|\WP_Error|object $data     Plugin response data.
	 * @param string                  $action   Action.
	 * @param object{slug?: string}   $response Request arguments.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return false|\WP_Error|object
	 */
	public function set_plugin_information( $data, string $action, object $response ) {
		if ( is_object( $data ) ) {
			return $data;
		}

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
		if ( null === $this->api_response ) {
			return $data;
		}

		// Return a plugin-information object rather than mutating the query arguments.
		$plugin_info                    = new \stdClass();
		$description                    = $this->get_plugin_data_value( 'Description' );
		$plugin_info->last_updated      = $this->api_response->published_at ?? '';
		$plugin_info->slug              = $this->slug;
		$plugin_info->name              = $this->get_plugin_data_value( 'Name' );
		$plugin_info->version           = $this->api_response->tag_name;
		$plugin_info->author            = $this->get_plugin_data_value( 'AuthorName' );
		$plugin_info->homepage          = $this->get_plugin_data_value( 'PluginURI' );
		$plugin_info->short_description = $description;
		$plugin_info->requires_php      = $this->get_plugin_data_value( 'RequiresPHP' );
		$plugin_info->requires          = $this->get_plugin_data_value( 'RequiresWP' );
		$requires_plugins               = $this->get_plugin_data_value( 'RequiresPlugins' );
		if ( ! empty( $requires_plugins ) ) {
			$plugin_info->requires_plugins = $requires_plugins;
		}
		$plugin_info->sections = [
			'description' => wp_kses_post( $description ),
			'changelog'   => wp_kses_post( wpautop( $this->api_response->body ?? '' ) ),
		];

		// Add download link, if the plugin can be updated.
		if ( $this->can_update ) {
			$download_url = $this->get_release_download_url();
			if ( ! empty( $download_url ) ) {
				$plugin_info->download_link = $download_url;
			}
		}

		return $plugin_info;
	}

	/**
	 * Rename this plugin's extracted update directory before WordPress installs it.
	 *
	 * @param string|\WP_Error $source       Extracted package source.
	 * @param string               $remote_source Original extracted package directory.
	 * @param \WP_Upgrader         $upgrader      Upgrader instance.
	 * @param array<string, mixed> $hook_extra    Extra arguments passed to the filter.
	 * @return string|\WP_Error
	 */
	public function normalize_plugin_source( string|\WP_Error $source, string $remote_source, \WP_Upgrader $upgrader, array $hook_extra ): string|\WP_Error {
		global $wp_filesystem;

		if (
			$source instanceof \WP_Error ||
			! isset( $hook_extra['plugin'] ) ||
			$this->file_path !== $hook_extra['plugin']
		) {
			return $source;
		}

		$plugin_subdirectory = dirname( $this->file_path );
		if ( '.' === $plugin_subdirectory ) {
			return $source;
		}

		$source_directory = untrailingslashit( $source );
		if ( basename( $source_directory ) === $plugin_subdirectory ) {
			return $source;
		}

		$normalized_source = trailingslashit( dirname( $source_directory ) ) . $plugin_subdirectory;
		if (
			! is_object( $wp_filesystem ) ||
			! method_exists( $wp_filesystem, 'move' ) ||
			! $wp_filesystem->move( $source_directory, $normalized_source )
		) {
			return new \WP_Error(
				'updatesync_source_move_failed',
				__( 'UpdateSync could not prepare the plugin update package.', 'updatesync' )
			);
		}

		return trailingslashit( $normalized_source );
	}

	/**
	 * Get Download File.
	 *
	 * @param string $id   Asset ID.
	 * @param string $name Asset name, retained for backwards compatibility.
	 *
	 * @since  1.0.0
	 * @access public
	 *
	 * @return string
	 */
	public function get_download_file( string $id, string $name ): string {
		unset( $name );
		$this->get_release_information();

		if ( null === $this->api_response || empty( $this->api_response->assets ) ) {
			return '';
		}

		foreach ( $this->api_response->assets as $asset ) {
			$asset_id = $asset->id ?? null;
			if ( ( is_int( $asset_id ) || is_string( $asset_id ) ) && (string) $asset_id === $id ) {
				if ( ! empty( $this->access_token ) && isset( $asset->url ) && is_string( $asset->url ) ) {
					return $asset->url;
				}
				return isset( $asset->browser_download_url ) && is_string( $asset->browser_download_url ) ? $asset->browser_download_url : '';
			}
		}

		return '';
	}
}
