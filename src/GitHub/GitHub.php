<?php
declare(strict_types=1);

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
        $parsed = parse_url($this->update_server);
        if (!isset($parsed['host']) || $parsed['host'] !== 'github.com') {
            return $this->update_server;
        }
        $path = trim($parsed['path'] ?? '', '/');
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
        $version = ltrim($data['tag_name'] ?? '', 'v');
        $standard->version = $version;
        if (!empty($data['assets']) && is_array($data['assets'])) {
            $asset = reset($data['assets']);
            $standard->download_link = $asset['browser_download_url'] ?? $data['zipball_url'];
        } else {
            $standard->download_link = $data['zipball_url'] ?? '';
        }
        $standard->tested = $version;
        $standard->requires = '';
        $standard->requires_php = '';
        $standard->slug = $this->slug;
        $standard->type = 'plugin';
        $this->api_data = $standard;
    }

    /**
     * Supplies GitHub-specific headers when fetching API data.
     *
     * @param array $options Optional arguments for wp_remote_get.
     * @return \WP_Error|null
     */
    protected function fetchApiData(array $options = []): ?\WP_Error {
        $githubOptions = [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json'
            ]
        ];
        // Merge any passed options with GitHub-specific options.
        $options = array_merge_recursive($options, $githubOptions);
        return parent::fetchApiData($options);
    }
}
