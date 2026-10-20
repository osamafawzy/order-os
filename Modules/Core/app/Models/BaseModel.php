<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared base for every model in the system.
 *
 * Mass assignment is guarded by id only: every write reaches a model through a
 * repository fed by a DTO, so the allow-list lives in the DTO rather than in
 * a $fillable array that drifts out of date with the migration.
 */
abstract class BaseModel extends Model
{
    protected $guarded = ['id'];
}
