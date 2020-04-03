<?php
declare(strict_types=1);

namespace GuzabaPlatform\Users\Controllers;

use Guzaba2\Http\Method;
use GuzabaPlatform\Platform\Application\BaseController;
use Psr\Http\Message\ResponseInterface;

/**
 * Class Users
 * @package GuzabaPlatform\Users\Controllers
 *
 * Provides users listing.
 * The user management (crete, update, delete_ is provided by the ActiveRecordDefaultController)
 */
class Users extends BaseController
{
    protected const CONFIG_DEFAULTS = [
        'routes' => [
            '/admin/users' => [
                Method::HTTP_GET => [self::class, 'main'],
            ],
        ],
    ];

    protected const CONFIG_RUNTIME = [];

    public function main(): ResponseInterface
    {

    }

}