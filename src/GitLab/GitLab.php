<?php
declare(strict_types=1);

/**
 * GitLab Provider
 *
 * Handles update notifications from GitLab.
 *
 * @package MG\UpdateSync\GitLab
 */

namespace MG\UpdateSync\GitLab;

use MG\UpdateSync\AbstractProvider;

final class GitLab extends AbstractProvider {

    /**
     * Constructs the GitLab API URL using the Update URI.
     *
     * Expects $this->update_server to be in the format:
     * "https://gitlab.com/{owner}/{repo}"
     *
     * GitLab’s API requires the project path URL-encoded.
     *
     * @return string
     */
    protected function getApiUrl(): string {
        $parsed = parse_url( $this->update_server );
        if ( ! isset( $parsed['host'] ) || $parsed['host'] !== 'gitlab.com' ) {
            return $this->update_server;
        }
        $path = trim( $parsed['path'] ?? '', '/' );
        $encoded = urlencode( $path );
        // GitLab API to list releases (returns an array)
        return "https://gitlab.com/api/v4/projects/{$encoded}/releases";
    }

    /**
     * Processes the raw GitLab API response into a standardized format.
     *
     * GitLab returns an array of releases; we assume the first element is the latest.
     *
     * @param array $data Raw JSON response from GitLab.
     * @return void
     */
    protected function processApiData(array $data): void {
        // Assume the first release is the latest.
        $latest = is_array( $data ) ? reset( $data ) : [];
        $standard = new \stdClass();

        // Use tag_name as the version.
        $version = ltrim( $latest['tag_name'] ?? '', 'v' );
        $standard->version = $version;

        // Use the first asset link if available.
        if ( isset( $latest['assets']['links'] ) && is_array( $latest['assets']['links'] ) && ! empty( $latest['assets']['links'] ) ) {
            $asset = reset( $latest['assets']['links'] );
            $standard->download_link = $asset['url'] ?? '';
        } else {
            // Fallback: GitLab may not have an asset, so you could construct a download URL from the repository archive.
            // For example: https://gitlab.com/{owner}/{repo}/-/archive/{tag_name}/{repo}-{tag_name}.zip
            $standard->download_link = '';
        }

        $standard->tested = $version; // Placeholder
        $standard->requires = '';
        $standard->requires_php = '';
        $standard->slug = $this->slug;
        $standard->type = 'plugin';

        $this->api_data = $standard;
    }
}
