<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Inertia page-render tests hit @vite() in app.blade.php, which
        // throws ViteManifestNotFoundException when public/build/manifest.json
        // is absent. Backend tests must not depend on frontend build artifacts;
        // this stubs the Vite directive so views render without a manifest.
        $this->withoutVite();

        // Disable CSRF middleware for Laravel 12 testing
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }
}
