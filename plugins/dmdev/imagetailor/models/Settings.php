<?php namespace DMdev\Imagetailor\Models;

use System\Models\SettingModel;

/**
 * Settings model for ImageTailor plugin
 */
class Settings extends SettingModel
{
    public $settingsCode = 'dmdev_imagetailor_settings';

    public $settingsFields = 'fields.yaml';

    public function initSettingsData()
    {
        $this->enabled = true;
        $this->default_max_width = 1200;
        $this->default_max_height = 1200;
    }
}
