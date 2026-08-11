## Development

When starting the dev server, use background mode:

```
astro dev --background
```

Manage the background server with `astro dev stop`, `astro dev status`, and `astro dev logs`.

## Contact forms (lead capture)

The forms POST to `lead-capture.php` / `real-estate-lead.php` (PHP + SQLite CRM in
`public/crm`). `astro dev` serves those files as raw text — PHP never runs. To test
forms locally, serve the production build through PHP:

```
npm run serve:php        # builds, registers the site API key, serves dist on :8000
```

Then open `http://localhost:8000`. reCAPTCHA auto-swaps to Google's always-pass
test key on `localhost`, so the full submit works without console setup.

On a live PHP host (e.g. Hostinger), after deploying `dist/`:

```
cd <webroot>
php crm/bin/register-site.php     # creates DB + registers the site API key (idempotent)
```

Set `DB_PATH` in `crm/.env` to an absolute path outside the web root on live hosts,
so the SQLite DB is never downloadable. `public/crm/.env` and the DB are gitignored.

## Blog data source (WordPress via same-origin proxy)

Blog/insight content is read live from the WordPress REST API
(`https://chimpzlab.com/chimpzlab-old/wp-json/wp/v2/insights`) — WordPress is the
single source of truth (no JSON snapshots). `public/wp-insights.js` fetches through
the same-origin PHP proxy `public/wp-proxy.php` first, then falls back to the direct
WP API when the proxy is unavailable (e.g. `astro dev`, where public PHP files are
served as raw text and the JSON probe fails).

The proxy exists because some hosts (e.g. Hostinger CDN-based demo hosts) inject a
strict Content-Security-Policy that blocks cross-origin calls to `chimpzlab.com`;
`wp-proxy.php` relays both the WP API and WP media images through the site's own
origin so blogs/images keep working without hosting-config changes. It never stores
or edits content. Requires PHP on the host (Hostinger, shared/demo plans).

## Documentation

Full documentation: https://docs.astro.build

Consult these guides before working on related tasks:

- [Adding pages, dynamic routes, or middleware](https://docs.astro.build/en/guides/routing/)
- [Working with Astro components](https://docs.astro.build/en/basics/astro-components/)
- [Using React, Vue, Svelte, or other framework components](https://docs.astro.build/en/guides/framework-components/)
- [Adding or managing content](https://docs.astro.build/en/guides/content-collections/)
- [Adding styles or using Tailwind](https://docs.astro.build/en/guides/styling/)
- [Supporting multiple languages](https://docs.astro.build/en/guides/internationalization/)
