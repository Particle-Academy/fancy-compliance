<?php

declare(strict_types=1);

namespace FancyCompliance\Models;

use FancyCompliance\LedgerIsAppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the consent ledger. Insert-only.
 *
 * There is no IP address and no user agent column, and there must never be:
 * the ledger proves what a person chose, and neither value is needed for that.
 *
 * @property int $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $action decision | withdrawal | link
 * @property list<string> $granted
 * @property list<string> $refused
 * @property string $policy_version
 * @property string $source banner | preferences | api | login
 * @property string|null $linked_from
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ConsentRecord extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'granted' => 'array',
        'refused' => 'array',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('fancy-compliance.table', 'fancy_compliance_consents');
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LedgerIsAppendOnly('Consent records are append-only. Record a new decision instead.'));
        static::deleting(static fn () => throw new LedgerIsAppendOnly('Consent records are append-only. Retention is fancy-compliance:prune, off by default.'));
    }
}
