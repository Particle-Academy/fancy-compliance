<?php

declare(strict_types=1);

namespace FancyCompliance;

use InvalidArgumentException;

/** The host's `fancy-compliance.categories` config cannot be used. A developer error, raised at first use. */
final class InvalidCategoryConfiguration extends InvalidArgumentException {}
