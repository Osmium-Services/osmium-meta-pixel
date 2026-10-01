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
                "pixelId": ""
            }
        }
        JSON;

    public function index(): void
    {
        $isPost = $this->isPost();
        if ($isPost) $this->handleSubmit();

        $this->data['admin']['config']['metaPixel'] = (array) MetaPixelConfig::get();
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

        $this->saveConfig($enabled, $pixelId);

        $this->admin->model->changelog->log(
            description: 'Updated Meta Pixel settings',
            recordType: 'settings',
        );

        MetaPixelConfig::clearCache();

        $_SESSION['meta_pixel_settings_saved'] = true;
        $this->redirect('settings/meta-pixel/');
    }

    private function saveConfig(bool $enabled, string $pixelId): void
    {
        $configExists = \file_exists(self::CONFIG_FILE_PATH);
        if (!$configExists) $this->ensureConfigDirectoryExists();

        $content = $configExists ? \file_get_contents(self::CONFIG_FILE_PATH) : self::DEFAULT_CONFIG;

        $jsonStart = \strpos(haystack: $content, needle: '{');
        $phpHeader = \substr(string: $content, offset: 0, length: $jsonStart);
        $data = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true) ?? [];

        $data['metaPixel'] = ['enabled' => $enabled, 'pixelId' => $pixelId];

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
