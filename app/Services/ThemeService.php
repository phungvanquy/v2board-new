<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class ThemeService
{
    private $path;
    private $theme;

    public function __construct($theme)
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string) $theme)) {
            abort(500, 'Invalid theme name');
        }
        $this->theme = $theme;
        $this->path = public_path('theme/');
    }

    public function init()
    {
        $themeConfigFile = $this->path . "{$this->theme}/config.json";
        if (!File::exists($themeConfigFile)) {
            abort(500, "Theme [{$this->theme}] does not exist");
        }
        $themeConfig = json_decode(File::get($themeConfigFile), true);
        if (!isset($themeConfig['configs']) || !is_array($themeConfig['configs'])) {
            abort(500, "Theme [{$this->theme}] has an invalid config.json");
        }
        $configs = $themeConfig['configs'];
        $data = [];
        foreach ($configs as $config) {
            $data[$config['field_name']] = isset($config['default_value']) ? $config['default_value'] : '';
        }

        $data = var_export($data, true);
        try {
            if (!File::put(base_path() . "/config/theme/{$this->theme}.php", "<?php\n return $data ;")) {
                abort(500, "Failed to initialize theme [{$this->theme}]");
            }
        } catch (\Exception $e) {
            abort(500, 'Please check the permissions of the V2Board directory');
        }

        try {
            Artisan::call('config:cache');
            while (true) {
                if (config("theme.{$this->theme}")) {
                    break;
                }
            }
        } catch (\Exception $e) {
            abort(500, "Failed to initialize theme [{$this->theme}]");
        }
    }
}
