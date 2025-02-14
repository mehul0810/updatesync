<?php
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
     * Returns the API URL for GitLab.
     *
     * @return string
     */
    protected function getApiUrl(): string {
        // Construct GitLab-specific API URL.
        return "{$this->update_server}/wp-json/gitlab-updater/v1/update-api/?slug={$this->slug}";
    }
}
