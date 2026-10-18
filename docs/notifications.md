# Notifications

## Who hears

- **Email**: the addresses under **Notification emails** in the [settings](configuration.md), or `INGEST_NOTIFY_MAIL` (comma-separated). Sent with the site's mail settings.
- **Slack**: an incoming webhook URL in `INGEST_SLACK_WEBHOOK_URL`.

## When

Each feed chooses under **Schedule & alerts**:

| | YAML |
|---|---|
| A run fails | `notify.on: [failed]` |
| The safety check stops a run | `aborted` |
| Some items fail | `errors` |
| The feed hasn't run successfully for N hours | `notify.stale_after_hours: 26` |

New feeds tell you about failures and safety stops.

## Once per problem

A feed that runs every 15 minutes and keeps failing sends **one** message, then one more when it works again ("Supplier catalogue is working again"). A feed that's gone quiet sends one message per period. Dry runs never notify.

## The dashboard

Add the widget to `config/statamic/cp.php` to see feeds that need attention, and what runs next:

```php
'widgets' => [
    ['type' => 'ingest_feed_health', 'width' => 50],
],
```

It also says when the scheduler isn't running.

## Events

To send alerts somewhere else, listen for `RunFailed`, `RunAborted` and `RunCompleted`. See [the PHP API](php-api.md#events).
