<?php

declare(strict_types=1);

namespace FancyCompliance\DataRights;

use RuntimeException;

/** A data-rights setting that cannot be read safely. The request fails; it never falls back to "anything goes". */
final class InvalidDataRightsConfiguration extends RuntimeException {}
