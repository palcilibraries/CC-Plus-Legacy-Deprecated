<?php

namespace App\Providers;
use \App\GlobalSetting;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\ServiceProvider;

class GlobalSettingsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap the application services.
     *
     * @param \Illuminate\Contracts\Cache\Factory $cache
     * @param \App\GlobalSetting $settings
     *
     * @return void
     */
    public function boot(Factory $cache, GlobalSetting $settings)
    {
        // ccplus config settings from global_settings table
        $config_settings = $cache->remember('ccplus', 60, function() use ($settings)
        {
            $values = array();
            try {
              $values = $settings->where('type','config')->pluck('value', 'name')->all();
            } catch (\Exception $e) { }
            return $values;
        });
        config()->set('ccplus', $config_settings);

        // mail settings from global_settings table
        $mail_settings = $cache->remember('mail', 60, function() use ($settings)
        {
            $values = array();
            try {
              $data = $settings->where('type','mail')->pluck('value', 'name')->all();
              // clear "mail_" prefix off the array keys if they exist
              foreach ($data as $k => $v) {
                  $newKey = preg_replace('/^(mail_)/', '', $k);
                  if ($newKey !== $k) {
                      $data[$newKey] = $v;
                      unset($data[$k]);
                  }
              }
              $data['from'] = array('address' => $data['from_address'], 'name' => $data['from_name']);
              $values = array_except($data,['from_address','from_name']);
            } catch (\Exception $e) { }
            return $values;
        });
        config()->set('mail', $mail_settings);
    }
}
