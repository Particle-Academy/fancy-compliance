<?php

declare(strict_types=1);

namespace FancyCompliance\Models;

use FancyCompliance\LedgerIsAppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the erasure audit trail, `fancy_compliance_erasures`. Insert-only:
 * every state change of a request, and every registrant's outcome (its version
 * and its deleted, anonymised and retained counts, or its failure).
 *
 * The subject appears only as its keyed-hash pseudonym, so the trail shows
 * that an erasure happened without naming who it was for.
 *
 * @property int $id
 * @property string $request_id
 * @property string $pseudonym
 * @property string $event requested | cancelled | executing | registrant_executed | registrant_failed | executed | partially_executed
 * @property string|null $registrant
 * @property string|null $registrant_version
 * @property array<string, mixed>|null $counts
 * @property string|null $error
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ErasureRecord extends Model
{
    public const TABLE = 'fancy_compliance_erasures';

    public const UPDATED_AT = null;

    protected $table = self::TABLE;

    protected $guarded = ['id'];

    protected $casts = [
        'counts' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LedgerIsAppendOnly('The erasure audit trail is append-only.'));
        static::deleting(static fn () => throw new LedgerIsAppendOnly('The erasure audit trail is append-only.'));
    }
}
