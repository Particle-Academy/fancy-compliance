<?php

declare(strict_types=1);

namespace FancyCompliance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One export request. The subject is a keyed hash (`subject_hash`); the
 * subject itself is held encrypted only until the export is built.
 *
 * @property string $id
 * @property string $subject_hash
 * @property string|null $subject encrypted JSON {type, id}, null once built
 * @property string $format json | zip
 * @property string $status pending | ready | failed | downloaded | expired
 * @property string|null $path
 * @property int|null $bytes
 * @property string|null $error
 * @property string|null $failed_registrant
 * @property string|null $download_token_hash
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $ready_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
final class DataExport extends Model
{
    public const TABLE = 'fancy_compliance_exports';

    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const DOWNLOADED = 'downloaded';

    public const EXPIRED = 'expired';

    public $incrementing = false;

    public $timestamps = false;

    protected $table = self::TABLE;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'bytes' => 'integer',
        'created_at' => 'datetime',
        'ready_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
