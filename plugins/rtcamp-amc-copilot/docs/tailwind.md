# Tailwind CSS

Tailwind v4 is an optional feature, off by default. Enabling it adds a Tailwind entry stylesheet that the normal asset build compiles and the plugin enqueues on the frontend.

## Enable

```bash
npm run init -- --enable=tailwind --yes
npm install
```

Enabling makes these changes:

| Change | Detail |
| --- | --- |
| `src/css/tailwind.css` | Entry file: `@import "tailwindcss";` and `@source "../../";` (scans the plugin for class names). Edit it freely. |
| `postcss.config.js` | Re-exports `@rtcamp/tailwind-config/postcss`. |
| `package.json` | Adds `tailwindcss`, `@tailwindcss/postcss` and `@rtcamp/tailwind-config` as dev dependencies. |
| Main plugin file | Sets `ACME_CONTENT_FEATURES_ENABLE_TAILWIND` to `true`. |

## Build and enqueue

`src/css/tailwind.css` is picked up by `build:assets` like any other stylesheet and written to `assets/build/css/tailwind.css`. `inc/Core/Assets.php` enqueues it on the frontend, as the `acme-content-features-tailwind` handle, when Tailwind is enabled.

The decision is made at enqueue time, in this order:

1. The `acme_content_features_tailwind_enabled` filter, so a theme or another plugin can switch it per request.
2. Its default, the `ACME_CONTENT_FEATURES_ENABLE_TAILWIND` constant. Define the constant in `wp-config.php` to force it on or off for one environment.

```php
add_filter( 'acme_content_features_tailwind_enabled', '__return_false' );
```

Verify by adding a utility class (for example `text-red-600`) to a template, running `npm run build:dev`, and checking the class is in `assets/build/css/tailwind.css` and applied on the frontend.

## Disable

```bash
npm run init -- --disable=tailwind --yes
```

This sets the constant back to `false`, deletes `src/css/tailwind.css` and `postcss.config.js`, and removes the three dev dependencies from `package.json`. Run `npm install` afterwards to update `node_modules` and the lockfile.

Tailwind itself is documented at [tailwindcss.com](https://tailwindcss.com/docs); the shared PostCSS preset lives in [wp-tooling](https://github.com/rtCamp/wp-tooling).

