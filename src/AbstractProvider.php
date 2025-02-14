<?php
/**
 * AbstractProvider
 *
 * Contains common functionality for update providers.
 *
 * @package MG\UpdateSync
 */

namespace MG\UpdateSync;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Abstract class for update providers.
 */
abstract class AbstractProvider implements ProviderInterface {

    /**
     * File path relative to the plugin/theme.
     *
     * @var string
     */
    protected string $file;

    /**
     * Plugin or theme slug.
     *
     * @var string
     */
    protected string $slug;

    /**
     * Local version number.
     *
     * @var string
     */
    protected string $local_version;

    /**
     * Object containing API update data.
     *
     * @var \stdClass
     */
    protected \stdClass $api_data;

    /**
     * Update server URL.
     *
     * @var string|null
     */
    protected ?string $update_server;

    /**
     * Constructor.
     *
     * Initializes file path, slug, and update server details by reading file headers.
     *
     * @param string $file_path Absolute file path of plugin/theme.
     */
    public function __construct( string $file_path ) {
        // Determine the slug from the file path.
        $this->slug = basename( dirname( $file_path ) );

        // If the file is functions.php then assume the style.css in the same directory.
        if ( str_ends_with( $file_path, 'functions.php' ) ) {
            $this->file = $this->slug . '/style.css';
            $file_path  = dirname( $file_path ) . '/style.css';
        } else {
            $this->file = $this->slug . '/' . basename( $file_path );
        }

        // Retrieve file header data.
        $file_data = get_file_data(
            $file_path,
            [
                'Version'   => 'Version',
                'UpdateURI' => 'Update URI',
            ]
        );

        $this->local_version = $file_data['Version'];
        $this->update_server = $file_data['UpdateURI'];
    }

    /**
     * Runs the update process.
     *
     * Checks page context, validates the update server, fetches API data, and loads hooks.
     *
     * @return void|\WP_Error
     */
    public function run() {
        global $pagenow;

        // Only run on relevant admin pages.
        if ( ! $this->isAllowedPage( $pagenow ) ) {
            return;
        }

        // Validate that an update server is defined.
        if ( $error = $this->validateUpdateServer() ) {
            return $error;
        }

        // Fetch update API data.
        if ( $error = $this->fetchApiData() ) {
            return $error;
        }

        // Register WordPress hooks.
        $this->loadHooks();
    }

    /**
     * Checks if the current page is allowed to trigger an update check.
     *
     * @param string $pagenow Current admin page.
     * @return bool True if allowed; otherwise false.
     */
    protected function isAllowedPage( string $pagenow ): bool {
        $pages            = [ 'update-core.php', 'update.php', 'plugins.php', 'themes.php' ];
        $view_details     = [ 'plugin-install.php', 'theme-install.php' ];
        $autoupdate_pages = [ 'admin-ajax.php', 'index.php', 'wp-cron.php' ];
        $allowed = array_merge( $pages, $view_details, $autoupdate_pages );
        return in_array( $pagenow, $allowed, true );
    }

    /**
     * Validates that the update server is set.
     *
     * @return \WP_Error|null Returns a WP_Error if missing; otherwise null.
     */
    protected function validateUpdateServer(): ?\WP_Error {
        if ( null === $this->update_server ) {
            return new \WP_Error( 'no_domain', 'No update server domain' );
        }
        return null;
    }

    /**
     * Fetches update data from the remote update server.
     *
     * Retrieves a transient-cached response or makes a remote request if necessary.
     *
     * @return \WP_Error|null Returns a WP_Error on failure; otherwise null.
     */
    protected function fetchApiData(): ?\WP_Error {
        $url = $this->getApiUrl();
        $transient_key = "updatesync_{$this->file}";

        // Attempt to get cached API data.
        $response = get_site_transient( $transient_key );
        if ( ! $response ) {
            $response = wp_remote_post( $url );
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( null === $decoded || empty( $decoded ) || isset( $decoded['error'] ) ) {
                return new \WP_Error( 'non_json_api_response', 'Poorly formed JSON', $response );
            }

            $this->api_data = (object) $decoded;
            $this->api_data->file = $this->file;

            // Cache the API response for 5 minutes.
            set_site_transient( $transient_key, $this->api_data, 5 * \MINUTE_IN_SECONDS );
        } else {
            if ( isset( $response->error ) ) {
                return new \WP_Error( 'repo-no-exist', 'Specified repo does not exist' );
            }
            $this->api_data = $response;
        }

        return null;
    }

    /**
     * Registers all necessary hooks for the update process.
     *
     * @return void
     */
    public function loadHooks(): void {
        $type = $this->api_data->type;

        add_filter( 'upgrader_source_selection', [ $this, 'upgraderSourceSelection' ], 10, 4 );
        add_filter( "{$type}s_api", [ $this, 'repoApiDetails' ], 99, 3 );
        add_filter( "site_transient_update_{$type}s", [ $this, 'updateSiteTransient' ], 15, 1 );
        if ( ! is_multisite() ) {
            add_filter( 'wp_prepare_themes_for_js', [ $this, 'customizeThemeUpdateHtml' ] );
        }
        add_filter(
            'upgrader_pre_download',
            function () {
                add_filter( 'http_request_args', [ $this, 'addAuthHeader' ], 15, 2 );
                return false;
            }
        );
    }

    /**
     * Renames the source folder for plugin or theme upgrades.
     *
     * @param string                          $source        Original source path.
     * @param string                          $remote_source Remote source path.
     * @param \Plugin_Upgrader|\Theme_Upgrader $upgrader      Upgrader instance.
     * @param array|null                      $hook_extra    Additional hook data.
     * @return string|\WP_Error New source path or WP_Error on failure.
     */
    public function upgraderSourceSelection( string $source, string $remote_source, $upgrader, $hook_extra = null ): string|\WP_Error {
        global $wp_filesystem;
        $new_source = $source;

        if ( isset( $hook_extra['action'] ) && 'install' === $hook_extra['action'] ) {
            return $source;
        }

        if ( $upgrader instanceof \Plugin_Upgrader && isset( $hook_extra['plugin'] ) ) {
            $slug = dirname( $hook_extra['plugin'] );
            $new_source = trailingslashit( $remote_source ) . $slug;
        }

        if ( $upgrader instanceof \Theme_Upgrader && isset( $hook_extra['theme'] ) ) {
            $slug = $hook_extra['theme'];
            $new_source = trailingslashit( $remote_source ) . $slug;
        }

        if ( basename( $source ) === $slug ) {
            return $source;
        }

        if ( trailingslashit( strtolower( $source ) ) !== trailingslashit( strtolower( $new_source ) ) ) {
            $wp_filesystem->move( $source, $new_source, true );
        }

        return trailingslashit( $new_source );
    }

    /**
     * Injects update API details into the WordPress API.
     *
     * @param mixed      $result   Existing API result.
     * @param string     $action   Requested action.
     * @param \stdClass  $response Repository API response.
     * @return \stdClass|bool
     */
    public function repoApiDetails( $result, string $action, \stdClass $response ): \stdClass|bool {
        if ( "{$this->api_data->type}_information" !== $action ) {
            return $result;
        }

        if ( $response->slug !== $this->api_data->slug ) {
            return $result;
        }

        return $this->api_data;
    }

    /**
     * Updates the site transient with the latest update information.
     *
     * @param \stdClass $transient Update transient object.
     * @return \stdClass Updated transient.
     */
    public function updateSiteTransient( $transient ): \stdClass {
        if ( ! is_object( $transient ) ) {
            $transient = new \stdClass();
        }

        $response = [
            'slug'                => $this->api_data->slug,
            $this->api_data->type => ( 'theme' === $this->api_data->type ) ? $this->api_data->slug : $this->api_data->file,
            'url'                 => $this->api_data->url ?? $this->api_data->slug,
            'icons'               => (array) $this->api_data->icons,
            'banners'             => $this->api_data->banners,
            'branch'              => $this->api_data->branch,
            'type'                => "{$this->api_data->git}-{$this->api_data->type}",
            'update-supported'    => true,
            'requires'            => $this->api_data->requires,
            'requires_php'        => $this->api_data->requires_php,
        ];

        if ( 'theme' === $this->api_data->type ) {
            $response['theme_uri'] = $response['url'];
        }

        if ( version_compare( $this->api_data->version, $this->local_version, '>' ) ) {
            $response_api_checked = [
                'new_version'  => $this->api_data->version,
                'package'      => $this->api_data->download_link,
                'tested'       => $this->api_data->tested,
                'requires'     => $this->api_data->requires,
                'requires_php' => $this->api_data->requires_php,
            ];
            $response = array_merge( $response, $response_api_checked );
            $response = 'plugin' === $this->api_data->type ? (object) $response : $response;
            $key      = 'plugin' === $this->api_data->type ? $this->api_data->file : $this->api_data->slug;
            $transient->response[ $key ] = $response;
        } else {
            $response = 'plugin' === $this->api_data->type ? (object) $response : $response;
            $transient->no_update[ $this->api_data->file ] = $response;
        }

        return $transient;
    }

    /**
     * Adds authentication headers to the download request.
     *
     * @param array  $args Array of HTTP request arguments.
     * @param string $url  Download URL.
     * @return array Modified HTTP request arguments.
     */
    public function addAuthHeader( array $args, string $url ): array {
        if (
            property_exists( $this->api_data, 'auth_header' )
            && str_contains( $url, $this->api_data->slug )
        ) {
            $args = array_merge( $args, $this->api_data->auth_header );
        }
        return $args;
    }

    /**
     * Customizes the theme update messaging for single site installations.
     *
     * @param array $prepared_themes Array of themes prepared for update.
     * @return array Modified themes array.
     */
    public function customizeThemeUpdateHtml( array $prepared_themes ): array {
        $theme = $this->api_data;

        if ( 'theme' !== $theme->type ) {
            return $prepared_themes;
        }

        if ( ! empty( $prepared_themes[ $theme->slug ]['hasUpdate'] ) ) {
            $prepared_themes[ $theme->slug ]['update'] = $this->appendThemeActionsContent( $theme );
        } else {
            $prepared_themes[ $theme->slug ]['description'] .= $this->appendThemeActionsContent( $theme );
        }

        return $prepared_themes;
    }

    /**
     * Generates the HTML content for theme update notifications.
     *
     * @param \stdClass $theme Theme object.
     * @return string HTML content for the update message.
     */
    protected function appendThemeActionsContent( \stdClass $theme ): string {
        $details_url       = $this->getThemeDetailsUrl( $theme );
        $nonced_update_url = $this->getNoncedUpdateUrl( $theme );
        $current           = get_site_transient( 'update_themes' );

        ob_start();
        if ( isset( $current->response[ $theme->slug ] ) ) {
            ?>
            <p>
                <strong>
                    <?php
                    printf(
                        esc_html__( 'There is a new version of %s available.', 'updatesync' ),
                        esc_attr( $theme->name )
                    );
                    printf(
                        ' <a href="%s" class="thickbox open-plugin-details-modal" title="%s">',
                        esc_url( $details_url ),
                        esc_attr( $theme->name )
                    );
                    if ( ! empty( $current->response[ $theme->slug ]['package'] ) ) {
                        printf(
                            esc_html__( 'View version %1$s details%2$s or %3$supdate now%2$s.', 'updatesync' ),
                            isset( $theme->remote_version ) ? esc_attr( $theme->remote_version ) : '',
                            '</a>',
                            sprintf(
                                '<a aria-label="' . esc_html__( 'Update %s now', 'updatesync' ) . '" id="update-theme" data-slug="' . esc_attr( $theme->slug ) . '" href="' . esc_url( $nonced_update_url ) . '">',
                                esc_attr( $theme->name )
                            )
                        );
                    } else {
                        printf(
                            esc_html__( 'View version %1$s details%2$s.', 'updatesync' ),
                            isset( $theme->remote_version ) ? esc_attr( $theme->remote_version ) : '',
                            '</a>'
                        );
                        printf(
                            esc_html__( '%1$sAutomatic update is unavailable for this theme.%2$s', 'updatesync' ),
                            '<p><i>',
                            '</i></p>'
                        );
                    }
                    ?>
                </strong>
            </p>
            <?php
        }
        return trim( (string) ob_get_clean() );
    }

    /**
     * Generates the theme details URL.
     *
     * @param \stdClass $theme Theme object.
     * @return string URL for theme information.
     */
    private function getThemeDetailsUrl( \stdClass $theme ): string {
        return esc_attr(
            add_query_arg(
                [
                    'tab'       => 'theme-information',
                    'theme'     => $theme->slug,
                    'TB_iframe' => 'true',
                    'width'     => 270,
                    'height'    => 400,
                ],
                self_admin_url( 'theme-install.php' )
            )
        );
    }

    /**
     * Generates a nonced URL for updating the theme.
     *
     * @param \stdClass $theme Theme object.
     * @return string Nonced update URL.
     */
    private function getNoncedUpdateUrl( \stdClass $theme ): string {
        return wp_nonce_url(
            esc_attr(
                add_query_arg(
                    [
                        'action' => 'upgrade-theme',
                        'theme'  => rawurlencode( $theme->slug ),
                    ],
                    self_admin_url( 'update.php' )
                )
            ),
            'upgrade-theme_' . $theme->slug
        );
    }

    /**
     * Returns the API URL specific to the provider.
     *
     * @return string
     */
    abstract protected function getApiUrl(): string;
}
