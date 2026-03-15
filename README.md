# PerryLabs SEO

Lightweight WordPress SEO plugin -- meta tags, schema markup, XML sitemaps, breadcrumbs, redirects with 404 logging. No bloat.

## Features

- **Meta Tags** -- custom title, description, canonical URL, and robots directives per post/page
- **Open Graph & Twitter Cards** -- automatic social sharing markup
- **XML Sitemap** -- dynamic sitemap with custom rewrite endpoint
- **JSON-LD Schema** -- structured data for Organization, Article, Event, Person, WebPage, and WebSite (with SearchAction)
- **Breadcrumbs** -- semantic breadcrumb navigation with JSON-LD markup; template tag `perrylabs_seo_breadcrumbs()`
- **301/302 Redirects** -- redirect manager with CSV import/export
- **404 Logging** -- automatic logging of 404 hits for redirect discovery
- **Per-Post Meta Box** -- edit SEO title, description, and robots settings from the post editor
- **Global Settings Page** -- site-wide defaults and configuration

## Requirements

- WordPress 5.0+
- PHP 8.0+

## Installation

### Upload manually

1. Download or clone this repository.
2. Copy the `perrylabs-seo` folder into `wp-content/plugins/`.
3. Activate the plugin from **Plugins** in the WordPress admin.

### Git submodule

From the root of your WordPress project:

```bash
git submodule add git@github.com:Jasonmperry/perrylabs-seo.git wp-content/plugins/perrylabs-seo
```

Then activate the plugin in the WordPress admin.

## Usage

### Breadcrumbs

Add breadcrumbs to any template:

```php
<?php perrylabs_seo_breadcrumbs(); ?>
```

### Options helper

Retrieve a plugin option programmatically:

```php
$value = perrylabs_seo_get_option( 'key', 'default' );
```

## License

GPL-2.0-or-later
