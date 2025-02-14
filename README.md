# UpdateSync

**UpdateSync is a composer package to help developers to integrate within their WordPress plugin to simplify their automatic updates directly from their code hosting providers repository.

## Overview

UpdateSync is a modular composer package that helps developers to integrate automatic updates to their WordPress plugins and themes from various code hosting providers. Currently, it supports both GitHub and GitLab. Users can choose the provider they want to use, and additional providers can be integrated in the future.

## Features

- **Modular Architecture:** Common interface and abstract provider for shared functionality.
- **Multiple Provider Support:** Separate implementations for GitHub and GitLab.
- **Optimized Performance:** Caches API responses using WordPress transients.
- **WordPress Integration:** Hooks into WordPress update system.
- **PHP 8.1 Compatible:** Utilizes modern PHP practices.
- **PSR-4 Autoloading:** Easily integrated via Composer.

## Installation

1. Install via Composer:
    ```bash
    composer require mehul0810/updatesync
    ```
2. Include the Composer autoloader in your WordPress project:
    ```php
    require_once __DIR__ . '/vendor/autoload.php';
    ```
3. Instantiate the provider using the factory:
    ```php
    use MG\UpdateSync\ProviderFactory;

    // To use GitHub update notifications:
    $provider = ProviderFactory::create('github', __FILE__);

    // To use GitLab update notifications:
    $provider = ProviderFactory::create('gitlab', __FILE__);

    // Run the provided. Always use only one provider at a time.
    $provider->run();
    ```

## Contributing

Contributions are welcome! Please follow these guidelines:
- Fork the repository and create a new branch for your feature or bug fix.
- Adhere to [WordPress Coding Standards](https://make.wordpress.org/core/handbook/best-practices/coding-standards/) (WPCS/PHPCS).
- Write clear, descriptive commit messages and update documentation as needed.
- Submit a pull request with your changes.

## License

This project is licensed under the GPLv3. See the [LICENSE](LICENSE) file for details.
