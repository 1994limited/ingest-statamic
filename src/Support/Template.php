<?php

namespace NineteenNinetyFour\Ingest\Support;

/**
 * Fills "{first_name} {last_name}" style templates from a feed item. Only
 * placeholders are replaced: feed values are never run as Antlers or PHP.
 */
class Template
{
    public static function render(string $template, array $item): string
    {
        return (string) preg_replace_callback('/\{([^{}]+)\}/', function ($m) use ($item) {
            $path = trim($m[1]);

            return Value::text(Path::get($item, $path));
        }, $template);
    }

    /** @return string[] */
    public static function paths(string $template): array
    {
        preg_match_all('/\{([^{}]+)\}/', $template, $m);

        return array_map('trim', $m[1]);
    }
}
