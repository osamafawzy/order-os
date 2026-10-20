<?php

namespace Modules\Payments\Models;

use Modules\Core\Models\BaseModel;

/**
 * A database override for one gateway's config.
 *
 * Credentials are encrypted at rest with the application key: a database dump
 * must not be a list of live payment secrets.
 *
 * @property string $key
 * @property bool|null $enabled
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 */
class PaymentGatewaySetting extends BaseModel
{
    protected $casts = [
        'enabled' => 'boolean',
        'credentials' => 'encrypted:array',
        'settings' => 'array',
    ];

    protected $hidden = [
        'credentials',
    ];
}
