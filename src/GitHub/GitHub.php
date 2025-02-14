<?php
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
     * Returns the API URL for GitHub.
     *
     * @return string
     */
    protected function getApiUrl(): string {
        // Construct GitHub-specific API URL.
        return "{$this->update_server}/wp-json/git-updater/v1/update-api/?slug={$this->slug}";
    }
}
