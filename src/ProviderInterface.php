<?php
/**
 * ProviderInterface
 *
 * Defines the interface for update providers.
 *
 * @package MG\UpdateSync
 */

namespace MG\UpdateSync;

interface ProviderInterface {
    /**
     * Runs the update notification process.
     *
     * @return void|\WP_Error
     */
    public function run();
}
