# Proiect.ro for WordPress

Sends leads from a WordPress site to a [Proiect.ro](https://proiect.ro) workspace — through a local
outbox that retries until every lead is delivered. Ships as the `proiectro` plugin on WordPress.org.

- **Settings › Proiect.ro** — App URL, workspace path, API key (create-only, expiring — the connection
  test tells you when a key can do more than that), default lead list and source.
- **Outbox** — every submission is stored first, sent immediately, retried with backoff on failure,
  visible and retryable from the admin.
- **Intake** — Contact Form 7, WPForms and Gravity Forms bridges (tick the forms under Settings ›
  Proiect.ro › Forms), the `[proiectro_lead_form]` shortcode, and
  `do_action( 'proiectro_capture_lead', $fields, $source )` for anything else. Field aliases
  (`your-name`, `company`, `message` …) are mapped onto the lead; anything else is kept in the
  lead's notes.

See `readme.txt` for the WordPress.org listing text.

## Development

```
find . -name '*.php' -print0 | xargs -0 -n1 php -l   # syntax
```

The plugin is plain PHP 7.4+ with WordPress core APIs only — no Composer, no bundled HTTP library.
CI syntax-checks on PHP 7.4–8.3 and runs the WordPress Plugin Check.

## License

GPLv2 or later.
