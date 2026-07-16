# Filament Media Manager

[![Latest Version on Packagist](https://img.shields.io/packagist/v/marvelous-digital/filament-media-manager.svg?style=flat-square)](https://packagist.org/packages/marvelous-digital/filament-media-manager)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/marvelous-digital/filament-media-manager/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/marvelous-digital/filament-media-manager/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/marvelous-digital/filament-media-manager/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/marvelous-digital/filament-media-manager/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/marvelous-digital/filament-media-manager.svg?style=flat-square)](https://packagist.org/packages/marvelous-digital/filament-media-manager)

A media library plugin for Filament: upload and organize high-fidelity media in nested folders.

## Installation

You can install the package via composer:

```bash
composer require marvelous-digital/filament-media-manager
```

> [!IMPORTANT]
> If you have not set up a custom theme and are using Filament Panels follow the instructions in the [Filament Docs](https://filamentphp.com/docs/4.x/styling/overview#creating-a-custom-theme) first.

After setting up a custom theme add the plugin's views to your theme css file or your app's css file if using the standalone packages.

```css
@source '../../../../vendor/marvelous-digital/filament-media-manager/resources/**/*.blade.php';
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="filament-media-manager-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="filament-media-manager-config"
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="filament-media-manager-views"
```

## Usage

Full usage documentation will be added as the plugin's features are implemented.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Raphael Baumann](https://github.com/marvelous-digital)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
