# UpdateSync

UpdateSync is a Composer package that adds GitHub release updates for a WordPress plugin. It uses WordPress's plugin update and plugin information hooks.

## Requirements

- PHP 8.1 or later
- A WordPress plugin in its own directory, with a version in its main plugin header
- A GitHub repository with published releases
- A ZIP release asset containing the plugin

## Installation

Install the package in the WordPress project that contains your plugin:

```bash
composer require mehul0810/updatesync
```

Load the Composer autoloader and create an updater in the plugin's main PHP file:

```php
use UpdateSync\Updater;

require_once __DIR__ . '/vendor/autoload.php';

new Updater(
	[
		'file'       => __FILE__,
		'slug'       => 'my-plugin',
		'version'    => '1.0.0',
		'github'     => [
			'username'     => 'github-owner',
			'repository'   => 'my-plugin',
			'access_token' => '', // Leave empty for public repositories.
		],
		'can_update' => true,
	]
);
```

Keep `version` in sync with the plugin header. The updater reads the latest published GitHub release and compares its tag with this version. When `can_update` is enabled, it uses a ZIP release asset to provide the update package. The asset name must end in `.zip`, and its archive must contain the plugin files at the root of the extracted directory. UpdateSync renames that directory to the installed plugin folder before WordPress installs it. `can_update` defaults to `false`; set it to `true` to offer updates.

For a private repository, provide a GitHub token with access to the repository. Store it in server configuration or another secret store; do not commit it in the plugin source. UpdateSync sends that token only to the matching GitHub release asset API endpoint. The archive is downloaded by WordPress and is not copied into the site's public uploads directory.

## Development

Install the development tools and run the configured checks:

```bash
composer install
composer run phpcs
composer run phpstan
composer audit
```

The development dependencies are not required at runtime.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
