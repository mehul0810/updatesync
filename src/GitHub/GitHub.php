<?php
declare(strict_types=1);

/**
 * GitHub Provider
 *
 * Handles update notifications from GitHub.
 *
 * @package MG\UpdateSync\GitHub
 */

namespace MG\UpdateSync\GitHub;

use MG\UpdateSync\AbstractProvider;

final class GitHub extends AbstractProvider {

    /**
     * Constructs the GitHub API URL using the Update URI from the plugin header.
     *
     * Expects $this->update_server to be in the format:
     * "https://github.com/{owner}/{repo}"
     *
     * @return string
     */
    protected function getApiUrl(): string {
        $parsed = parse_url( $this->update_server );
        if ( ! isset( $parsed['host'] ) || $parsed['host'] !== 'github.com' ) {
            // If the host is not GitHub, fall back to the provided URL.
            return $this->update_server;
        }
        $path = trim( $parsed['path'] ?? '', '/' );
        // Construct the API URL to get the latest release.
        return "https://api.github.com/repos/{$path}/releases/latest";
    }

    /**
     * Processes the raw GitHub API response into a standardized format.
     *
     * @param array $data Raw JSON response from GitHub.
     * @return void
     */
    protected function processApiData(array $data): void {
        $standard = new \stdClass();

        // Use tag_name as the new version, removing a leading "v" if present.
        $version = ltrim( $data['tag_name'] ?? '', 'v' );
        $standard->version = $version;

        // Choose the download link: if assets exist, use the first asset's browser_download_url; otherwise, use zipball_url.
        if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
            $asset = reset( $data['assets'] );
            $standard->download_link = $asset['browser_download_url'] ?? $data['zipball_url'];
        } else {
            $standard->download_link = $data['zipball_url'] ?? '';
        }

        // Set additional standardized properties. Adjust these as needed.
        $standard->tested = $version;  // Placeholder: assume tested with current version.
        $standard->requires = '';       // Optional: add if needed.
        $standard->requires_php = '';   // Optional: add if needed.
        $standard->slug = $this->slug;
        // Indicate the provider type.
        $standard->type = 'plugin';

        $this->api_data = $standard;
    }

    /**
     * Override fetchApiData to supply GitHub-specific headers.
     *
     * @return \WP_Error|null
     */
    protected function fetchApiData(): ?\WP_Error {
        $options = [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json'
            ]
        ];
        return parent::fetchApiData( $options );
    }
}
