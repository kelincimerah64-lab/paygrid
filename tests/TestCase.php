<?php

namespace Tests;

use App\Services\FeeMenuCatalog;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FeeMenuCatalog::clearCache();
    }
}
