# Security

A feed is someone else's data arriving on your site on a timer. Ingest treats it that way.

## Requests can't reach your own network

Feed URLs, pagination links, OAuth token URLs and image downloads all go through the same check:

- only `http` and `https`;
- no credentials in the URL (use the feed's authentication settings);
- the host's addresses must all be public: loopback, private networks, link-local (including cloud metadata at `169.254.169.254`), carrier-grade NAT and reserved ranges are refused, IPv4 and IPv6;
- the address that was checked is the one connected to, so a DNS answer can't change in between;
- every redirect is checked again (three at most).

An intranet ERP on purpose? Allow its host:

```env
INGEST_ALLOWED_HOSTS=erp.internal.example,*.lan.example
```

Files on the server can only be read from folders listed in `ingest.server_paths`.

## Limits

Timeouts (30 seconds; 10 to connect), a size limit on feeds (200 MB) and downloads (20 MB), and a page limit on pagination (500).

## Parsing

XML is parsed without network access, DTD loading or entity expansion, so external entities (XXE) and entity bombs are inert. A web page where a feed should be is refused rather than imported.

## HTML from feeds

Rich text (Bard, and Markdown with HTML in it) is sanitised with an allow-list: no scripts, iframes, forms, event-handler attributes or `javascript:` links. Plain text fields have HTML removed, because Antlers prints `{{ title }}` as it is. Turning that off is a choice per field (`html: trusted`), for feeds you control.

## Files from feeds

Downloads are checked by their content, not the server's word, against an allow-list of types (images and PDF). SVG is refused unless you add it, since it can carry scripts. Uploaded feed files are kept privately in `storage`, never in a public folder.

## Secrets

Tokens, passwords, keys and client secrets are never stored with a feed. Feeds hold `env:NAME` references; the values stay in `.env`. The form refuses a secret typed in directly and shows only whether a variable is set. Webhook tokens are stored as hashes and shown once.

## No code from the Control Panel

Templates in mappings fill in values (`{first_name}`); they never run Antlers. Transforms that would render templates or read files aren't available. PHP hooks are classes in your app, chosen by name.

## Users

A users feed can't create super admins, set passwords, give roles and groups that aren't on the allow-list, or change an existing administrator. See [Users](destinations.md#users).

## Permissions

Viewing, running, editing feeds and undoing runs are separate permissions, so editors can watch feeds without changing them. A feed writes on its editor's behalf, so setting one up also needs the permissions to make those changes by hand: editing and creating entries in its collection, editing its taxonomy's terms or its global set, or editing, creating and assigning roles to users.

## Error messages

Messages from failed requests have query strings and credentials taken out of URLs before they're shown, stored or emailed, so an API key sent as a query parameter never reaches the run log or a notification.

## Reporting a problem

Email [hello@1994.co.uk](mailto:hello@1994.co.uk). Please don't open a public issue for a security problem.
