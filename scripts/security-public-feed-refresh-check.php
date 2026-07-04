<?php
/**
 * Static regression check for SEC-20260613-0003.
 *
 * Public product and funnel feed handlers must not expose a refresh argument or
 * pass caller input into feed regeneration. Authenticated regenerate handlers
 * remain responsible for deliberate cache bypass.
 */

$root = dirname(__DIR__);
$checks = [
    [
        'path' => $root . '/includes/Rest/ProductFeedEndpoint.php',
        'feed_class' => 'ProductDataFeed',
        'method' => 'serveFeed',
    ],
    [
        'path' => $root . '/includes/Rest/FunnelFeedEndpoint.php',
        'feed_class' => 'FunnelDataFeed',
        'method' => 'serveFeed',
    ],
];

foreach ($checks as $check) {
    $source = file_get_contents($check['path']);
    if ($source === false) {
        fwrite(STDERR, "Could not read {$check['path']}\n");
        exit(1);
    }

    if (!preg_match('/public static function ' . preg_quote($check['method'], '/') . '\([^)]*\)\s*\{(?P<body>.*?)\n    \}/s', $source, $match)) {
        fwrite(STDERR, "Could not locate {$check['method']} in {$check['path']}\n");
        exit(1);
    }

    $body = $match['body'];
    if (strpos($body, "get_param('refresh')") !== false || strpos($body, 'get_param("refresh")') !== false) {
        fwrite(STDERR, "{$check['path']} public feed handler still reads refresh input\n");
        exit(1);
    }

    if (!preg_match('/' . preg_quote($check['feed_class'], '/') . '::generateFeed\(\$format,\s*false\)/', $body)) {
        fwrite(STDERR, "{$check['path']} public feed handler must call generateFeed with force=false\n");
        exit(1);
    }

    if (!preg_match('/register_rest_route\([^;]+\/regenerate[^;]+permission_callback\'\s*=>\s*\[self::class,\s*\'checkAdminPermission\'\]/s', $source)) {
        fwrite(STDERR, "{$check['path']} regenerate route is missing the admin permission callback\n");
        exit(1);
    }
}

echo "public feed refresh bypass disabled; authenticated regenerate routes remain guarded\n";
