<?php

declare(strict_types=1);

namespace FancyCompliance\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/** Hands a caught exception to the host's handler, when it has one, so a failure is logged and not only recorded. */
final class Reporter
{
    public static function report(Container $app, Throwable $e): void
    {
        if ($app->bound(ExceptionHandler::class)) {
            $app->make(ExceptionHandler::class)->report($e);
        }
    }
}
