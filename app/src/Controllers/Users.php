<?php
declare(strict_types=1);

namespace GuzabaPlatform\Users\Controllers;

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
        ],
    ];

    protected const CONFIG_RUNTIME = [];

    public function main(int $page, int $limit, string $search_values, string $sort_by, string $sort): ResponseInterface
    {
        $struct = [];

        if ($sort_by === 'none') {
            $sort_by = 'user_name';
        }

        $offset = ($page - 1) * $limit;
        $search = json_decode(base64_decode(urldecode($search_values)));

        //$columns_data = User::get_columns_data();
        $active_record_keys = [
            'user_id',
            'user_name',
            'user_email',
            'user_role',
            'meta_object_uuid',
            'inherited_roles_names',
        ];
        //print_r($active_record_keys);
        $struct['properties'] = $active_record_keys;

        //$struct['data'] = Users::get_data_by((array) $search, $offset, $limit, $use_like = TRUE, $sort_by, (bool) $sort_desc, $total_found_rows);
        $struct['data'] = \GuzabaPlatform\Users\Models\Users::get_users((array) $search, $offset, $limit = 0, $sort_by, $sort, $total_found_rows);

        $struct['totalItems'] = $total_found_rows;
        if ($limit) {
            $struct['numPages'] = ceil($struct['totalItems'] / $limit);
        } else {
            $struct['numPages'] = 1;
        }


        $Response = parent::get_structured_ok_response($struct);
        return $Response;
    }

}