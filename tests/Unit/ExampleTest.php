<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    #[TestDox('true 為真（Laravel 樣板測試）')]
    public function test_that_true_is_true(): void
    {
        $this->assertTrue(true);
    }
}
