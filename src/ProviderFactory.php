<?php
/**
 * ProviderFactory
 *
 * Factory class to instantiate the appropriate update provider.
 *
 * @package MG\UpdateSync
 */

namespace MG\UpdateSync;

use MG\UpdateSync\GitHub\GitHub;
use MG\UpdateSync\GitLab\GitLab;

class ProviderFactory {
    /**
     * Creates an instance of a provider.
     *
     * @param string $provider 'github' or 'gitlab'
     * @param string $file_path Absolute file path of plugin/theme.
     * @return ProviderInterface
     */
    public static function create( string $provider, string $file_path ): ProviderInterface {
        switch ( strtolower( $provider ) ) {
            case 'gitlab':
                return new GitLab( $file_path );
            case 'github':
            default:
                return new GitHub( $file_path );
        }
    }
}
