<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights\Registrants;

use Composer\InstalledVersions;
use Throwable;

/** This package's installed version, for the erasure audit trail. `unknown` when Composer cannot say. */
final class PackageVersion
{
    public static function get(): string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('particle-academy/fancy-compliance')) {
                return substr((string) InstalledVersions::getPrettyVersion('particle-academy/fancy-compliance'), 0, 64);
            }
        } catch (Throwable) {
        }

        return 'unknown';
    }
}
