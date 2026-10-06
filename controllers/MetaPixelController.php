<?php

declare(strict_types=1);

namespace Osmium\Services\MetaPixel\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\MetaPixel\Models\MetaPixelConfig;

/**
 * Meta Pixel settings controller - a full-page form POST/redirect flow,
 * matching the Google Analytics service.
 *
 * Routes:
 *   - index() → /admin/settings/meta-pixel/  (GET shows the form, POST saves it)
 */
class MetaPixelController extends AdminController
{
    private const CONFIG_FILE_PATH = 'app/config/services/meta-pixel.json.php';
    private const DEFAULT_CONFIG = <<<'JSON'
        <?php exit(); ?>
        {
            "metaPixel": {
                "enabled": false,
                "pixelId": "",
                "shopEvents": false,
                "serverEvents": false,
                "accessToken": "",
                "testEventCode": ""
            }
        }
        JSON;

    public function index(): void
    {
        $isPost = $this->isPost();
        if ($isPost) $this->handleSubmit();

        $config = (array) MetaPixelConfig::get();
        $config['accessTokenSet'] = ($config['accessToken'] ?? '') !== '';
        unset($config['accessToken']); // The saved token never reaches the page
        $this->data['admin']['config']['metaPixel'] = $config;
        $this->data['admin']['settingsSaved'] = $_SESSION['meta_pixel_settings_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['meta_pixel_settings_error'] ?? false;
        unset($_SESSION['meta_pixel_settings_saved'], $_SESSION['meta_pixel_settings_error']);

        $this->setView('meta-pixel/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) {
            $_SESSION['meta_pixel_settings_error'] = 'Invalid form submission. Please try again.';
            $this->redirect('settings/meta-pixel/');
        }

        $enabled = isset($_POST['enabled']);
        $pixelId = \trim($_POST['pixel_id'] ?? '');

        $pixelIdValid = MetaPixelConfig::isValidPixelId($pixelId);
        $needsValidId = $enabled || $pixelId !== '';
        if ($needsValidId && !$pixelIdValid) {
            $_SESSION['meta_pixel_settings_error'] = 'Pixel ID must be 15-16 digits.';
            $this->redirect('settings/meta-pixel/');
        }

        $shopEvents = isset($_POST['shop_events']);
        $serverEvents = isset($_POST['server_events']);
        $postedToken = \trim($_POST['access_token'] ?? '');
        $accessToken = $postedToken === '' ? (string) (MetaPixelConfig::get()->accessToken ?? '') : $postedToken; // Blank keeps the stored secret
        $testEventCode = \trim($_POST['test_event_code'] ?? '');

        $tokenValid = \preg_match('/^[A-Za-z0-9_\-]*$/', $accessToken) === 1; // Meta tokens are letters, digits, _ and -
        if (!$tokenValid) {
            $_SESSION['meta_pixel_settings_error'] = 'Access token has characters Meta tokens do not use. Paste only the token.';
            $this->redirect('settings/meta-pixel/');
        }

        $testCodeValid = \preg_match('/^[A-Za-z0-9]*$/', $testEventCode) === 1;
        if (!$testCodeValid) {
            $_SESSION['meta_pixel_settings_error'] = 'Test event code is letters and digits only (for example TEST12345).';
            $this->redirect('settings/meta-pixel/');
        }

        if ($serverEvents && ($accessToken === '' || !$pixelIdValid)) {
            $_SESSION['meta_pixel_settings_error'] = 'Server events need a valid Pixel ID and an access token.';
            $this->redirect('settings/meta-pixel/');
        }

        $this->saveConfig(
            enabled: $enabled,
            pixelId: $pixelId,
            shopEvents: $shopEvents,
            serverEvents: $serverEvents,
            accessToken: $accessToken,
            testEventCode: $testEventCode,
        );

        $this->admin->model->changelog->log(
            description: 'Updated Meta Pixel settings',
            recordType: 'settings',
        );

        MetaPixelConfig::clearCache();

        $_SESSION['meta_pixel_settings_saved'] = true;
        $this->redirect('settings/meta-pixel/');
    }

    private function saveConfig(
        bool $enabled,
        string $pixelId,
        bool $shopEvents,
        bool $serverEvents,
        string $accessToken,
        string $testEventCode,
    ): void
    {
        $configExists = \file_exists(self::CONFIG_FILE_PATH);
        if (!$configExists) $this->ensureConfigDirectoryExists();

        $content = $configExists ? \file_get_contents(self::CONFIG_FILE_PATH) : self::DEFAULT_CONFIG;

        $jsonStart = \strpos(haystack: $content, needle: '{');
        $phpHeader = \substr(string: $content, offset: 0, length: $jsonStart);
        $data = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true) ?? [];

        $data['metaPixel'] = [
            'enabled' => $enabled,
            'pixelId' => $pixelId,
            'shopEvents' => $shopEvents,
            'serverEvents' => $serverEvents,
            'accessToken' => $accessToken,
            'testEventCode' => $testEventCode,
        ];

        $newJson = \json_encode(
            value: $data,
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::CONFIG_FILE_PATH, $phpHeader . $newJson . "\n");
    }

    private function ensureConfigDirectoryExists(): void
    {
        $dir = \dirname(self::CONFIG_FILE_PATH);
        $alreadyExists = \is_dir($dir);
        if (!$alreadyExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);
    }

    private function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}
