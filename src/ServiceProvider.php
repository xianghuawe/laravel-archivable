<?php

declare(strict_types=1);

namespace Xianghuawe\Archivable;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Xianghuawe\Archivable\Console\ArchiveCommand;
use Xianghuawe\Archivable\Console\TableStructureSyncCommand;

class ServiceProvider extends BaseServiceProvider implements DeferrableProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // 加载配置文件
        $this->mergeConfigFrom(
            __DIR__ . '/config/archive.php',
            'archive'
        );
        $this->registerCommands();
    }

    /**
     * Register the console commands for the package.
     *
     * @return void
     */
    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ArchiveCommand::class,
                TableStructureSyncCommand::class,
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            ArchiveCommand::class,
        ];
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        // 发布配置文件
        $this->publishes([
            __DIR__ . '/config/archive.php' => config_path('archive.php'),
        ], 'config');
    }
}
