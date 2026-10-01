<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seperti di produksi (PHP-FPM), tiap request test mulai dengan state baru: instance scoped (cabang aktif, pengaturan, audit) dan
     * instance controller (yang di-cache router beserta service-nya). Tanpa ini cabang aktif dari request sebelumnya terbawa dan
     * menutupi bug urutan middleware (binding rute sebelum cabang ditentukan).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
