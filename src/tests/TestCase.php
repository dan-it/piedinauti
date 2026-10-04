<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Inertia checks that a rendered page exists as a file. Its default folder is
        // "Pages" (capital P), while this project keeps its pages in "pages".
        config(['inertia.testing.page_paths' => [resource_path('js/pages')]]);
    }
}
