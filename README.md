<div align="center">
    <h1>Dead Drop</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/kirilldakhniuk/dead-drop"><img src="https://img.shields.io/packagist/v/kirilldakhniuk/dead-drop.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/kirilldakhniuk/dead-drop"><img src="https://img.shields.io/packagist/php-v/kirilldakhniuk/dead-drop.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/kirilldakhniuk/dead-drop"><img src="https://badge.laravel.cloud/badge/kirilldakhniuk/dead-drop?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/kirilldakhniuk/dead-drop/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/kirilldakhniuk/dead-drop/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/kirilldakhniuk/dead-drop"><img src="https://img.shields.io/packagist/dt/kirilldakhniuk/dead-drop.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Test

## Installation

You can install the package via Composer:

```bash
composer require kirilldakhniuk/dead-drop
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="dead-drop"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="dead-drop-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="dead-drop-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="dead-drop-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="dead-drop-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="dead-drop-assets"
```

## Usage

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Dead Drop! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Kirill D.](https://github.com/kirilldakhniuk)
- [All Contributors](../../contributors)

## License

Dead Drop is open-sourced software licensed under the [MIT license](LICENSE.md).
