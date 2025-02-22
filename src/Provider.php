<?php
/**
 * Provider for handling automatic updates from GitHub releases.
 *
 * @package UpdateSync
 */

namespace UpdateSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Provider class for automatic updates.
 */
class Provider {

	/**
	 * GitHub repository owner.
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * GitHub repository name.
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Whether updates are allowed.
	 *
	 * @var bool
	 */
	private $can_update;

	/**
	 * Whether caching is enabled.
	 *
	 * @var bool
	 */
	private $cache;

	/**
	 * Cache duration in seconds.
	 *
	 * @var int
	 */
	private $cache_duration;

	/**
	 * Constructor.
	 *
	 * @param string $owner         GitHub repository owner.
	 * @param string $repo          GitHub repository name.
	 * @param bool   $can_update    Whether updates are allowed. Defaults to true.
	 * @param bool   $cache         Whether caching is enabled. Defaults to true.
	 * @param int    $cache_duration Cache duration in seconds. Defaults to 3600.
	 */
	public function __construct( $owner, $repo, $can_update = true, $cache = true, $cache_duration = 3600 ) {
		$this->owner          = $owner;
		$this->repo           = $repo;
		$this->can_update     = $can_update;
		$this->cache          = $cache;
		$this->cache_duration = $cache_duration;
	}

	/**
	 * Checks for an available update by querying the GitHub API.
	 *
	 * @return array|null Release data array if available, or null if no update or updates are disabled.
	 */
	public function check_for_update() {
		if ( ! $this->can_update ) {
			return null;
		}

		$release_data = $this->get_release_data();

		if ( empty( $release_data ) ) {
			return null;
		}

		// Add version comparison logic here if needed.
		return $release_data;
	}

	/**
	 * Retrieves release data from the GitHub API using WordPress HTTP API and transients.
	 *
	 * @return array|null Decoded release data or null on failure.
	 */
	private function get_release_data() {
		$transient_key = 'updatesync_' . md5( $this->owner . $this->repo );
		if ( $this->cache ) {
			$cached = get_transient( $transient_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', $this->owner, $this->repo );
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'User-Agent' => 'UpdateSync',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body         = wp_remote_retrieve_body( $response );
		$release_data = json_decode( $body, true );

		if ( $this->cache && ! empty( $release_data ) ) {
			set_transient( $transient_key, $release_data, $this->cache_duration );
		}

		return $release_data;
	}

	/**
	 * Downloads the release ZIP file from a given download URL.
	 *
	 * @param string $download_url The URL of the ZIP file.
	 * @param string $destination  The file path where the ZIP should be saved.
	 * @return bool Returns true on successful download, false otherwise.
	 */
	public function download_release_asset( $download_url, $destination ) {
		$response = wp_remote_get(
			$download_url,
			array(
				'headers'  => array(
					'User-Agent' => 'UpdateSync',
				),
				'stream'   => true,
				'filename' => $destination,
				'timeout'  => 300,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return true;
	}
}
