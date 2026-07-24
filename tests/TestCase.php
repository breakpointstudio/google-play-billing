<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Tests;

use Breakpoint\GooglePlay\GooglePlayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [GooglePlayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('google-play-billing.package_name', 'com.consumedbycode.slopes');
        $app['config']->set('google-play-billing.rtdn.retry_delay', 0);
        $app['config']->set('cache.default', 'array');
    }
}
