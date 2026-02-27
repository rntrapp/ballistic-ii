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

        // Stub Vite so Inertia routes render without built assets.
        // app.blade.php calls @vite([...]) which throws ViteManifestNotFound
        // when public/build/manifest.json is absent. Tests assert HTTP status,
        // not compiled JS — the Laravel-provided no-op Vite handler suffices.
        $this->withoutVite();

        // Disable CSRF middleware for Laravel 12 testing
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }
}
