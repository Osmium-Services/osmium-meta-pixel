<?php

declare(strict_types=1);

namespace Osmium\Services\MetaPixel\Models;

/**
 * Meta Pixel configuration helper.
 *
 * Loads this service's own settings file so theme blocks don't hardcode
 * them, matching the file-based config convention used by the other
 * services (app/config/services/{id}.json.php).
 */
class MetaPixelConfig
{
    private static ?object $config = null;
    private static string $configPath = 'app/config/services/meta-pixel.json.php';

    /**
     * Falls back to defaults (disabled, no pixel ID) if the config file is
     * missing, so installing this service stays inert until someone visits
     * its settings page and saves a pixel ID.
     */
    public static function get(): object
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        $configFile = self::$configPath;

        $configExists = \file_exists($configFile);
        if (!$configExists) {
            self::$config = self::defaults();
            return self::$config;
        }

        $content = \file_get_contents($configFile);
        $jsonStart = \strpos(haystack: $content, needle: '{');

        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) {
            self::$config = self::defaults();
            return self::$config;
        }

        $json = \substr(string: $content, offset: $jsonStart);
        $decoded = \json_decode($json);

        self::$config = (object) ((array) ($decoded->metaPixel ?? []) + (array) self::defaults()); // Older saved files lack the newer keys

        return self::$config;
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    /**
     * A Meta Pixel ID is 15-16 digits. Anything else is rejected so a typo
     * (or a pasted snippet) can't end up inside the page's inline script.
     */
    public static function isValidPixelId(string $pixelId): bool
    {
        return \preg_match(pattern: '/^\d{15,16}$/', subject: $pixelId) === 1;
    }

    /**
     * The site's environment from core's config file. Hook handlers run with
     * no page context ($site), so they read it here. Empty when unreadable,
     * which callers treat as production-like only after checking dev/staging.
     */
    public static function siteEnvironment(): string
    {
        $file = 'app/config/config.json.php';
        if (!\file_exists($file)) return '';

        $content = (string) \file_get_contents($file);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        if ($jsonStart === false) return '';

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));

        return (string) ($decoded->site->environment ?? '');
    }

    private static function defaults(): object
    {
        return (object) [
            'enabled' => false,
            'pixelId' => '',
            'shopEvents' => false,
            'serverEvents' => false,
            'accessToken' => '',
            'testEventCode' => '',
        ];
    }
}
