<?php

declare(strict_types=1);

namespace Relay\Tests;

abstract class TestCase
{
    private int $assertions = 0;
    private array $failures = [];

    abstract public function run(): void;

    protected function assertTrue(bool $condition, string $message = 'Expected true'): void
    {
        $this->assertions++;
        if (!$condition) {
            $this->failures[] = $message;
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Expected false'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->failures[] = $message !== '' ? $message : 'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true);
        }
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected === $actual) {
            $this->failures[] = $message !== '' ? $message : 'Expected values to differ';
        }
    }

    protected function assertThrows(string $class, callable $callback, string $message = ''): void
    {
        $this->assertions++;
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            $this->failures[] = $message !== '' ? $message : 'Expected ' . $class . ', got ' . $e::class;
            return;
        }
        $this->failures[] = $message !== '' ? $message : 'Expected ' . $class . ' to be thrown';
    }

    protected function contains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(str_contains($haystack, $needle), $message !== '' ? $message : 'Expected to find "' . $needle . '"');
    }

    /** @return array{assertions:int,failures:list<string>} */
    public function result(): array
    {
        return ['assertions' => $this->assertions, 'failures' => $this->failures];
    }
}
