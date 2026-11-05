<?php

declare(strict_types=1);

namespace FancyCompliance;

use LogicException;

/**
 * Something tried to edit or delete a consent record through the model.
 *
 * A consent record is evidence of what a person agreed to and when. Changing
 * one rewrites that evidence; a change of mind is a new row. The only deletion
 * path is `fancy-compliance:prune`, which is off by default and never removes a
 * subject's latest row.
 */
final class LedgerIsAppendOnly extends LogicException {}
