<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Inherits createApplication() from BaseTestCase, which both
    // requires bootstrap/app.php AND calls Kernel::bootstrap() to wire
    // facades + the IoC container. Our setup doesn't need anything
    // extra — environment is pinned via phpunit.xml.
}
