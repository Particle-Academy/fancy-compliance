<?php

declare(strict_types=1);

namespace FancyCompliance\Tests\Support;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;

final class User extends Model implements AuthenticatableContract
{
    use Authenticatable;

    public $timestamps = false;

    protected $guarded = [];
}
