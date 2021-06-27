<?php
declare(strict_types=1);

namespace GuzabaPlatform\Users\Controllers;

use Guzaba2\Authorization\Role;
use Guzaba2\Http\Method;
use GuzabaPlatform\Platform\Application\BaseController;
use GuzabaPlatform\Platform\Authentication\Models\User;
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
            '/admin/users/{page}/{limit}/{search_values}/{sort_by}/{sort}' => [
                Method::HTTP_GET => [self::class, 'main']
            ],
            '/admin/users/roles'                                            => [
                Method::HTTP_GET => [self::class, 'roles']
            ],
        ],
    ];

    protected const CONFIG_RUNTIME = [];

    public const LISTING_COLUMNS = [
        'user_id',
        'user_name',
        'user_email',
        'role_id',
        'user_is_disabled',
        'meta_object_uuid',
        //'inherits_role_name',
        'granted_roles_names',
    ];

    /**
     * @param int $page
     * @param int $limit
     * @param string $search_values
     * @param string $sort_by
     * @param string $sort
     * @return ResponseInterface
     * @throws \Azonmedia\Exceptions\InvalidArgumentException
     * @throws \Guzaba2\Base\Exceptions\InvalidArgumentException
     * @throws \Guzaba2\Base\Exceptions\LogicException
     * @throws \Guzaba2\Base\Exceptions\RunTimeException
     * @throws \Guzaba2\Kernel\Exceptions\ConfigurationException
     * @throws \ReflectionException
     */
    public function main(int $page, int $limit, string $search_values, string $sort_by, string $sort): ResponseInterface
    {

        $struct = [];

        if ($sort_by === 'none') {
            $sort_by = 'user_name';
        }

        $offset = ($page - 1) * $limit;
        $search = json_decode(base64_decode(urldecode($search_values)));

        $struct['listing_columns'] = self::LISTING_COLUMNS;
        $struct['record_properties'] = \GuzabaPlatform\Users\Controllers\User::RECORD_PROPERTIES;
        $struct['editable_record_properties'] = \GuzabaPlatform\Users\Controllers\User::EDITABLE_RECORD_PROPERTIES;

        //$struct['data'] = Users::get_data_by((array) $search, $offset, $limit, $use_like = TRUE, $sort_by, (bool) $sort_desc, $total_found_rows);
        $struct['data'] = \GuzabaPlatform\Users\Models\Users::get_users((array) $search, $offset, $limit, $sort_by, $sort, $total_found_rows);

        $struct['totalItems'] = $total_found_rows;
        if ($limit) {
            $struct['numPages'] = ceil($struct['totalItems'] / $limit);
        } else {
            $struct['numPages'] = 1;
        }

        return self::get_structured_ok_response($struct);
    }

    /**
     * Returns all non-user roles.
     * @return ResponseInterface
     * @throws \Guzaba2\Base\Exceptions\RunTimeException
     */
    public function roles(): ResponseInterface
    {
        /** @var Role[] $roles */
        $roles = Role::get_system_roles_data();

        //$roles = array_map(fn(array $record) : string => $record['role_name'], $roles);
        $roles = array_map(static function(array $record) : object {
            $Object = new \stdClass();
            $Object->role_name = $record['role_name'];
            $Object->meta_object_uuid = $record['meta_object_uuid'];
            return $Object;
        }, $roles);
        $struct = [];
        $struct['roles'] = $roles;
        return self::get_structured_ok_response($struct);
    }

}