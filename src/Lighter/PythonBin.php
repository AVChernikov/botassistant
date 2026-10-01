<?php

declare(strict_types=1);

namespace Lighter;

/**
 * Resolve mcp-lighter venv Python on Linux (bin/python) or Windows (Scripts/python.exe).
 */
final class PythonBin
{
    public static function path(?string $root = null): string
    {
        $root ??= dirname(__DIR__, 2);
        $venv = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '.venv';
        $unix = $venv . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
        $win = $venv . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        if (is_file($unix)) {
            return $unix;
        }
        if (is_file($win)) {
            return $win;
        }

        return PHP_OS_FAMILY === 'Windows' ? $win : $unix;
    }
}
