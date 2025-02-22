<?php
/**
 * Plugin Update Notifier.
 *
 * Hooks into WordPress’s update mechanism to display update notifications.
 *
 * @package UpdateSync
 */

namespace UpdateSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Updater class.
 */
class Updater {

	/**
	 * Plugin file (relative to the plugins directory).
	 *
	 * @var string
	 */
	private $plugin_file;

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
	 * Current plugin version.
	 *
	 * @var string
	 */
	private $current_version;

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
	 * @param string $plugin_file     Plugin file relative to the plugins directory (e.g., my-plugin/my-plugin.php).
	 * @param string $owner           GitHub repository owner.
	 * @param string $repo            GitHub repository name.
	 * @param string $current_version Current version of the plugin.
	 * @param bool   $can_update      Whether updates are allowed. Defaults to true.
	 * @param bool   $cache           Whether caching is enabled. Defaults to true.
	 * @param int    $cache_duration  Cache duration in seconds. Defaults to 3600.
	 */
	public function __construct( $plugin_file, $owner, $repo, $current_version, $can_update = true, $cache = true, $cache_duration = 3600 ) {
		$this->plugin_file     = $plugin_file;
		$this->owner           = $owner;
		$this->repo            = $repo;
		$this->current_version = $current_version;
		$this->can_update      = $can_update;
		$this->cache           = $cache;
		$this->cache_duration  = $cache_duration;

		// Hook into the update mechanism.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
	}

	/**
	 * Checks for plugin updates and injects update data into the transient.
	 *
	 * @param object $transient The update plugins transient.
	 * @return object Modified update plugins transient.
	 */
	public function check_update( $transient ) {
		// Ensure the transient contains plugin data.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$provider = new Provider( $this->owner, $this->repo, $this->can_update, $this->cache, $this->cache_duration );
		$release  = $provider->check_for_update();

		// Proceed only if a valid release is found with a tag_name.
		if ( $release && isset( $release['tag_name'] ) ) {
			$new_version = ltrim( $release['tag_name'], 'v' );
			// Compare versions: if current version is less than new version, add update notification.
			if ( version_compare( $this->current_version, $new_version, '<' ) ) {
				$package = isset( $release['assets'][0]['browser_download_url'] ) ? $release['assets'][0]['browser_download_url'] : '';

				// Inject update data into the transient.
				$transient->response[ $this->plugin_file ] = (object) array(
					'slug'        => dirname( $this->plugin_file ),
					'plugin'      => $this->plugin_file,
					'new_version' => $new_version,
					'url'         => $release['html_url'],
					'package'     => $package,
				);
			}
		}

		return $transient;
	}
}
