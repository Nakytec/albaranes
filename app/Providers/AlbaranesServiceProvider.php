<?php

namespace Modules\Albaranes\Providers;

use Illuminate\Support\ServiceProvider;

class AlbaranesServiceProvider extends ServiceProvider
{
    protected string $name = 'Albaranes';

    public function register(): void
    {
        $this->mergeConfigFrom(module_path($this->name, 'config/config.php'), 'albaranes');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(module_path($this->name, 'routes/api.php'));
        $this->loadRoutesFrom(module_path($this->name, 'routes/web.php'));
        $this->loadViewsFrom(module_path($this->name, 'resources/views'), 'albaranes');
    }
}
