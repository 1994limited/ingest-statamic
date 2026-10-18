<?php

namespace NineteenNinetyFour\Ingest\Webhooks;

use Illuminate\Support\Str;
use NineteenNinetyFour\Ingest\Feeds\Feed;

/**
 * Webhook tokens. Only a hash is stored with the feed; the token itself
 * is shown once, when it's made. Duplicating a feed never copies it.
 */
class Token
{
    /**
     * Give the feed a new token and return it (to show once).
     */
    public static function issue(Feed $feed): string
    {
        $token = 'ing_'.Str::random(40);
        $feed->set('webhook.token_hash', hash('sha256', $token));

        return $token;
    }

    public static function revoke(Feed $feed): void
    {
        $feed->set('webhook.token_hash', null);
    }

    public static function enabled(Feed $feed): bool
    {
        return (bool) $feed->get('webhook.token_hash');
    }

    public static function matches(Feed $feed, ?string $token): bool
    {
        $hash = $feed->get('webhook.token_hash');

        return is_string($hash) && $hash !== '' && is_string($token) && $token !== '' && hash_equals($hash, hash('sha256', $token));
    }
}
