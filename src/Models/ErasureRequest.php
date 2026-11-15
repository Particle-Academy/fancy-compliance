<?php

declare(strict_types=1);

namespace FancyCompliance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One erasure request and its state. The subject is held encrypted only for
 * as long as the request may still run (pending, executing, partially
 * executed) and is cleared when it is executed or cancelled. Its history is
 * the append-only {@see ErasureRecord} trail.
 *
 * @property string $id
 * @property string $pseudonym
 * @property string|null $subject
 * @property string $status pending | cancelled | executing | executed | partially_executed
 * @property array<string, array<string, mixed>> $results per registrant
 * @property \Illuminate\Support\Carbon $requested_at
 * @property \Illuminate\Support\Carbon $due_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $executed_at
 */
final class ErasureRequest extends Model
{
    public const TABLE = 'fancy_compliance_erasure_requests';

    public const PENDING = 'pending';

    public const CANCELLED = 'cancelled';

    public const EXECUTING = 'executing';

    public const EXECUTED = 'executed';

    public const PARTIALLY_EXECUTED = 'partially_executed';

    public $incrementing = false;

    public $timestamps = false;

    protected $table = self::TABLE;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'results' => 'array',
        'requested_at' => 'datetime',
        'due_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'executed_at' => 'datetime',
    ];
}
