<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path redirects guests toward the dashboard (which then
     * requires authentication).
     */
    public function test_root_redirects_to_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
