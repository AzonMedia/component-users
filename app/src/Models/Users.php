<?php
declare(strict_types=1);

namespace GuzabaPlatform\Users\Models;

use Azonmedia\Exceptions\InvalidArgumentException;
use Guzaba2\Authorization\Interfaces\RoleInterface;
use Guzaba2\Authorization\Interfaces\UserInterface;
use Guzaba2\Authorization\Role;
use Guzaba2\Authorization\RolesHierarchy;
use Guzaba2\Base\Base;
use Guzaba2\Base\Exceptions\LogicException;
use Guzaba2\Base\Exceptions\RunTimeException;
use Guzaba2\Database\Interfaces\ConnectionInterface;
use Guzaba2\Database\Sql\Mysql\ConnectionCoroutine;
use Guzaba2\Kernel\Exceptions\ConfigurationException;
use Guzaba2\Orm\ActiveRecord;
use Guzaba2\Orm\Exceptions\MultipleValidationFailedException;
use Guzaba2\Orm\Exceptions\RecordNotFoundException;
use Guzaba2\Orm\Store\Sql\Mysql;
use GuzabaPlatform\Platform\Application\MysqlConnectionCoroutine;
use GuzabaPlatform\Platform\Authentication\Models\User;
use Guzaba2\Translator\Translator as t;

class Users extends Base
{

    protected const CONFIG_DEFAULTS = [
        'services'      => [
            'ConnectionFactory',
            'MysqlOrmStore',//needed because the get_class_id() method is used
        ],
        'class_dependencies'        => [ //dependencies on other classes
            //interface                 => implementation
            UserInterface::class    => User::class,
            RoleInterface::class    => Role::class
        ],
    ];

    protected const CONFIG_RUNTIME = [];

    public const SEARCH_CRITERIA = [
        'user_uuid',
        'meta_object_uuid',//the same as user_uuid
        'user_id',
        'user_name',
        'user_email',
        'user_is_disabled',
        'inherits_role_uuid',//check for the given role in the whole inheritance tree
        'inherits_role_name',
        'granted_role_uuid',//check for the given role only in the immediately granted roles
        'granted_role_name',
    ];

    /**
     * The create method is on Users as if the GP User class is inherited then the newly created meta records will enter with the new class name
     * This should not be done as this package (GuzabaPlatform/Users) is just a management package, does not (and should not)
     * @param array $user_properties
     * @param array $granted_roles_uuids
     * @return User
     * @throws ConfigurationException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws MultipleValidationFailedException
     * @throws RunTimeException
     * @throws \Guzaba2\Base\Exceptions\InvalidArgumentException
     * @throws \ReflectionException
     */
    public static function create(array $user_properties, array $granted_roles_uuids): User
    {
        $user_class = self::CONFIG_RUNTIME['class_dependencies'][UserInterface::class];
        $User = new $user_class();
        self::update($User, $user_properties, $granted_roles_uuids);
        return $User;
    }

    /**
     * @param User $User
     * @param array $user_properties
     * @param array $granted_roles_uuids
     * @throws ConfigurationException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws MultipleValidationFailedException
     * @throws RunTimeException
     * @throws \Guzaba2\Base\Exceptions\InvalidArgumentException
     * @throws \ReflectionException
     */
    public static function update(User $User, array $user_properties, array $granted_roles_uuids): void
    {
        //a transaction is started as the user creation/update and role granting needs to be one operation.
        $Transaction = ActiveRecord::new_transaction($TR);
        $Transaction->begin();

        foreach ($user_properties as $property_name=>$property_value) {
            if (in_array($property_name, ['user_password', 'user_password_confirmation'])) {
                continue;
            }
            $User->{$property_name} = $property_value;
        }
        if (!empty($user_properties['user_password']) && !empty($user_properties['user_password_confirmation'])) {
            $User->set_password($user_properties['user_password'], $user_properties['user_password_confirmation']);
        }

        $User->write();

        $role_class = self::CONFIG_RUNTIME['class_dependencies'][RoleInterface::class];

        $current_granted_roles_uuids = $User->get_role()->get_inherited_roles_uuids();
        foreach ($current_granted_roles_uuids as $current_inherited_role_uuid) {
            if (!in_array($current_inherited_role_uuid, $granted_roles_uuids)) {
                $Role = $role_class($current_inherited_role_uuid);
                $User->revoke_role($Role);
            }
        }

        foreach ($granted_roles_uuids as $inherited_role_uuid) {
            if (!in_array($inherited_role_uuid, $current_granted_roles_uuids)) {
                $Role = $role_class($inherited_role_uuid);
                $User->grant_role($Role);
            }
        }

        $Transaction->commit();

    }

    /**
     * Returns users based on the provided $search_criteria. Please @see self::SEARCH_CRITERIA for the valid keys.
     * @param array $search_criteria
     * @param string $order_by
     * @param string $order
     * @param int $offset
     * @param int $limit
     * @param int|null $total_found_rows
     * @return iterable
     * @throws InvalidArgumentException
     * @throws RunTimeException
     * @throws \Guzaba2\Base\Exceptions\InvalidArgumentException
     * @throws LogicException
     * @throws ConfigurationException
     * @throws \ReflectionException
     */
    public static function get_users(
        array $search_criteria,
        int $offset = 0,
        int $limit = 0,
        string $order_by = 'user_name',
        string $order = 'ASC',
        ?int &$total_found_rows = NULL
    ): iterable {

        foreach ($search_criteria as $key=>$value) {
            if (!in_array($key, self::SEARCH_CRITERIA)) {
                $message = sprintf(
                    t::_('The $search_criteria contains an unsupported key %1$s. The supported keys are %2$s.'),
                    $key,
                    implode(',', self::SEARCH_CRITERIA)
                );
                throw new \Guzaba2\Base\Exceptions\InvalidArgumentException();
            }
        }

        /** @var ConnectionInterface $Connection */
        $Connection = self::get_service('ConnectionFactory')->get_connection(MysqlConnectionCoroutine::class, $CR);
        $user_class = self::CONFIG_RUNTIME['class_dependencies'][UserInterface::class];
        $role_class = self::CONFIG_RUNTIME['class_dependencies'][RoleInterface::class];
        $users_table = $user_class::get_main_table();
        $roles_hierarchy_table = RolesHierarchy::get_main_table();
        $roles_table = $role_class::get_main_table();
        /** @var Mysql $MysqlOrmStore */
        $MysqlOrmStore = self::get_service('MysqlOrmStore');
        $meta_table = $MysqlOrmStore::get_meta_table();

        $w = $b = [];

        if (array_key_exists('user_id', $search_criteria) && $search_criteria['user_id'] !== NULL) {
            $w[] = "users.user_id = :user_id";
            $b['user_id'] = $search_criteria['user_id'];
        }
        if (array_key_exists('user_uuid', $search_criteria) && $search_criteria['user_uuid'] !== NULL) {
            $w[] = "meta.meta_object_uuid LIKE :user_uuid";
            $b['user_uuid'] = '%'.$search_criteria['user_uuid'].'%';
        }
        if (array_key_exists('meta_object_uuid', $search_criteria) && $search_criteria['meta_object_uuid'] !== NULL) {
            $w[] = "meta.meta_object_uuid LIKE :user_uuid";
            $b['user_uuid'] = '%'.$search_criteria['meta_object_uuid'].'%';
        }
        if (array_key_exists('user_name', $search_criteria) && $search_criteria['user_name'] !== NULL) {
            $w[] = "users.user_name LIKE :user_name";
            $b['user_name'] = '%'.$search_criteria['user_name'].'%';
        }
        if (array_key_exists('user_email', $search_criteria) && $search_criteria['user_email'] !== NULL) {
            $w[] = "users.user_email LIKE :user_email";
            $b['user_email'] = '%'.$search_criteria['user_email'].'%';
        }
        if (array_key_exists('user_is_disabled', $search_criteria) && $search_criteria['user_is_disabled'] !== NULL) {
            $w[] = "users.user_is_disabled = :user_is_disabled";
            if (strtolower($search_criteria['user_is_disabled']) === 'false' || !$search_criteria['user_is_disabled']) {
                $b['user_is_disabled'] = FALSE;
            } else {
                $b['user_is_disabled'] = TRUE;
            }

        }
        if (array_key_exists('inherits_role_uuid', $search_criteria) && $search_criteria['inherits_role_uuid'] !== NULL) {
            try {
                $Role = new Role($search_criteria['inherits_role_uuid']);
            } catch (RecordNotFoundException $Exception) {
                throw new \Guzaba2\Base\Exceptions\InvalidArgumentException(sprintf(t::_('There is no role with UUID %1$s as provided in "%2$s" key in $search_criteria.'), $search_criteria['inherits_role_uuid'], 'inherits_role_uuid' ));
            }
            $inheriting_roles_ids = $Role->get_all_inheriting_roles_ids();//already includes this role id
            $ids_placeholder = $Connection::array_placeholder($inheriting_roles_ids, 'role');
            $w[] = "roles_hierarchy.inherited_role_id IN ({$ids_placeholder})";
            $b['role'] = $inheriting_roles_ids;
        }
        if (array_key_exists('inherits_role_name', $search_criteria) && $search_criteria['inherits_role_name'] !== NULL) {
            try {
                $Role = new Role( ['role_name' => $search_criteria['inherits_role_name'] ]);//role_name is unique
            } catch (RecordNotFoundException $Exception) {
                throw new \Guzaba2\Base\Exceptions\InvalidArgumentException(sprintf(t::_('There is no role with role_name %1$s as provided in "%2$s" key in $search_criteria.'), $search_criteria['inherits_role_name'], 'inherits_role_name' ));
            }
            $inheriting_roles_ids = $Role->get_all_inheriting_roles_ids();//already includes this role id
            $ids_placeholder = $Connection::array_placeholder($inheriting_roles_ids, 'role');
            $w[] = "roles_hierarchy.inherited_role_id IN ({$ids_placeholder})";
            $b['role'] = $inheriting_roles_ids;
        }

        if ($w) {
            $w_str = "WHERE".PHP_EOL.implode(PHP_EOL."AND ", $w);
        } else {
            $w_str = "";
        }
        if ($offset || $limit) {
            $l_str = "LIMIT {$offset}, {$limit}";
        } else {
            $l_str = "";
        }

        $b['meta_class_id'] = $MysqlOrmStore->get_class_id($user_class);
        $b['roles_meta_class_id'] = $MysqlOrmStore->get_class_id($role_class);

        $q = "
SELECT
    users.user_id, users.user_name, users.user_email, users.role_id, users.user_is_disabled,
    meta.meta_object_uuid, meta.meta_class_id, meta.meta_object_create_microtime, meta.meta_object_last_update_microtime,
    meta.meta_object_create_role_id, meta.meta_object_last_update_role_id,
    GROUP_CONCAT(roles_hierarchy.inherited_role_id SEPARATOR ',') AS granted_roles_ids,
    GROUP_CONCAT(roles.role_name SEPARATOR ',') AS granted_roles_names,
    GROUP_CONCAT(roles_meta.meta_object_uuid SEPARATOR ',') AS granted_roles_uuids
FROM
    {$Connection::get_tprefix()}{$users_table} AS users
    INNER JOIN {$Connection::get_tprefix()}{$meta_table} AS meta ON meta.meta_object_id = users.user_id AND meta.meta_class_id = :meta_class_id
    INNER JOIN {$Connection::get_tprefix()}{$roles_hierarchy_table} AS roles_hierarchy ON roles_hierarchy.role_id = users.role_id
    LEFT JOIN {$Connection::get_tprefix()}{$roles_table} AS roles ON roles.role_id = roles_hierarchy.inherited_role_id
    LEFT JOIN {$Connection::get_tprefix()}{$meta_table} AS roles_meta ON roles_meta.meta_object_id = roles.role_id AND roles_meta.meta_class_id = :roles_meta_class_id
{$w_str}
GROUP BY
    users.user_id
{$l_str}
        ";
        //because the inherited role filter is applied after the query is executed there is no point having two parallel queries (one for data and one for total count if there is limit provided)

        $data = $Connection->prepare($q)->execute($b)->fetchAll();
        //$total_found_rows = count($data);


        //TODO - convert to parallel queries
        $q_count = "
SELECT
    COUNT(users.user_id) AS total_count
FROM
    {$Connection::get_tprefix()}{$users_table} AS users
    INNER JOIN {$Connection::get_tprefix()}{$meta_table} AS meta ON meta.meta_object_id = users.user_id AND meta.meta_class_id = :meta_class_id
    INNER JOIN {$Connection::get_tprefix()}{$roles_hierarchy_table} AS roles_hierarchy ON roles_hierarchy.role_id = users.role_id
{$w_str}
        ";
        $b_count = $b;
        unset($b_count['roles_meta_class_id']);
        $b_count['meta_class_id'] = $MysqlOrmStore->get_class_id($user_class);
        //because the inherited role filter is applied after the query is executed there is no point having two parallel queries (one for data and one for total count if there is limit provided)

        //unset($b['roles_meta_class_id'], $b['meta_class_id']);
        $total_found_rows = $Connection->prepare($q_count)->execute($b_count)->fetchAll()[0]['total_count'];


        $query_rows = count($data);
        for ($aa = 0; $aa < $query_rows; $aa++) {
            if (!$data[$aa]['granted_roles_ids']) {
                $data[$aa]['granted_roles_ids'] = [];
            } else {
                $data[$aa]['granted_roles_ids'] = explode(',', $data[$aa]['granted_roles_ids']);
            }
            if (!$data[$aa]['granted_roles_names']) {
                $data[$aa]['granted_roles_names'] = [];
            } else {
                $data[$aa]['granted_roles_names'] = explode(',', $data[$aa]['granted_roles_names']);
            }
            if (!$data[$aa]['granted_roles_uuids']) {
                $data[$aa]['granted_roles_uuids'] = [];
            } else {
                $data[$aa]['granted_roles_uuids'] = explode(',', $data[$aa]['granted_roles_uuids']);
            }

            $data[$aa]['user_is_disabled'] = (bool) $data[$aa]['user_is_disabled'];
        }

        //better have a method on Role that returns all inheriting roles and use this in IN () clause
//        $ret = [];
//        if (!empty($Role)) { //means it was requested only users having this role to be returned
//            foreach ($data as $record) {
//                $inherited_role_ids = explode(',', $record['inherited_role_ids']);
//                $include_record = FALSE;
//                foreach ($inherited_role_ids as $inherited_role_id) {
//                    $InheritedRole = new Role($inherited_role_id);
//                    if (in_array($Role->get_id(), $InheritedRole->get_all_inherited_roles_ids())) {
//                        $include_record = TRUE;//a role may be inherited through multiple others... and if $ret[] = $record is here this will produce multiple duplicate records
//                    }
//                }
//                if ($include_record) {
//                    $ret[] = $record;
//                }
//            }
//        }
        return $data;

    }
}