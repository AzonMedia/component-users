<?php
declare(strict_types=1);

namespace GuzabaPlatform\Users\Controllers;


use Guzaba2\Authorization\Role;
use Guzaba2\Base\Exceptions\InvalidArgumentException;
use Guzaba2\Base\Exceptions\LogicException;
use Guzaba2\Base\Exceptions\NotImplementedException;
use Guzaba2\Base\Exceptions\RunTimeException;
use Guzaba2\Http\Method;
use Guzaba2\Kernel\Exceptions\ConfigurationException;
use Guzaba2\Orm\Exceptions\MultipleValidationFailedException;
use GuzabaPlatform\Platform\Application\BaseController;
use Guzaba2\Translator\Translator as t;
use Psr\Http\Message\ResponseInterface;
use ReflectionException;

/**
 * Class User
 * @package GuzabaPlatform\Users\Controllers
 * Provides user management
 */
class User extends BaseController
{
    protected const CONFIG_DEFAULTS = [
        'routes' => [
            '/admin/users/user'             => [
                Method::HTTP_POST => [self::class, 'create']
            ],
            '/admin/users/user/{uuid}'      => [
                Method::HTTP_GET => [self::class, 'view'],
                Method::HTTP_PUT => [self::class, 'update'],
                //deletion is now allowed - instead the users can be disabled
                //Method::HTTP_DELETE => [self::class, 'remove'],//the ActiveRecordDefaultController could be used as well but for completeness of the API this is also provided
            ],
            '/admin/users/user/{uuid}/disable'      => [
                Method::HTTP_PUT => [self::class, 'disable'],
            ],
            '/admin/users/user/{uuid}/enable'      => [
                Method::HTTP_PUT => [self::class, 'disable'],
            ],

            '/admin/users/user/{uuid}/role/{role_uuid}'      => [ //not used by the UI but still useful API methods
                Method::HTTP_POST => [self::class, 'grant_role'],
                Method::HTTP_DELETE => [self::class, 'revoke_role'],
            ],
        ],
    ];

    protected const CONFIG_RUNTIME = [];

    /**
     * These are the properties to be presented in the UI (as more are actually returned)
     */
    public const RECORD_PROPERTIES = [
        'user_id',
        'user_name',
        'user_email',
        'user_password',
        'user_password_confirmation',
        'role_id',
        'meta_object_uuid',
        //'granted_roles_names',// no longer needed
        'granted_roles_uuids',
    ];

    /**
     * The editable properties form the UI. Must be a subset of @see self::RECORD_PROPERTIES
     */
    public const EDITABLE_RECORD_PROPERTIES = [
        //'user_id',
        'user_name',
        'user_email',
        'user_password',
        'user_password_confirmation',
        //'role_id',
        //'meta_object_uuid',
        //'inherits_role_name',
        //'granted_roles_names',
        'granted_roles_uuids',
    ];

    /**
     * @param string $uuid
     * @return ResponseInterface
     * @throws \Azonmedia\Exceptions\InvalidArgumentException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RunTimeException
     * @throws ConfigurationException
     * @throws ReflectionException
     */
    public function view(string $uuid): ResponseInterface
    {
        $User = new \GuzabaPlatform\Platform\Authentication\Models\User($uuid);
        $struct = [];
        $struct['record_properties'] = self::RECORD_PROPERTIES;
        $struct['editable_record_properties'] = self::EDITABLE_RECORD_PROPERTIES;
        $struct['inherited_roles'] = $User->get_role()->get_inherited_roles_names_and_uuids();//only directly inherited roles
        $struct = [$struct, ...$User->get_record_data()];
        return self::get_structured_ok_response($struct);
    }

    /**
     * @param string $user_name
     * @param string $user_email
     * @param string $user_password
     * @param string $user_password_confirmation
     * @param bool $user_is_disabled
     * @param array $granted_roles_uuids
     * @return ResponseInterface
     * @throws ConfigurationException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws MultipleValidationFailedException
     * @throws ReflectionException
     * @throws RunTimeException
     * @throws \Azonmedia\Exceptions\InvalidArgumentException
     */
    public function create(string $user_name, string $user_email, string $user_password, string $user_password_confirmation, bool $user_is_disabled, array $granted_roles_uuids): ResponseInterface
    {
        $user_properties = func_get_args();
        unset($user_properties['$ranted_roles_uuids']);
        $User = \GuzabaPlatform\Users\Models\Users::create($user_properties, $granted_roles_uuids);
        return self::get_structured_ok_response( ['message' => sprintf(t::_('The user %1s was created with UUID %2s.'), $User->user_name, $User->get_uuid() )] );
    }

    /**
     * @param string $uuid
     * @param string $user_name
     * @param string $user_email
     * @param string $user_password
     * @param string $user_password_confirmation
     * @param bool $user_is_disabled
     * @param array $granted_roles_uuids
     * @return ResponseInterface
     * @throws ConfigurationException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws MultipleValidationFailedException
     * @throws ReflectionException
     * @throws RunTimeException
     * @throws \Azonmedia\Exceptions\InvalidArgumentException
     */
    public function update(string $uuid, string $user_name, string $user_email, string $user_password, string $user_password_confirmation, bool $user_is_disabled, array $granted_roles_uuids): ResponseInterface
    {
        $user_properties = func_get_args();
        unset($user_properties['granted_roles_uuids']);
        unset($user_properties['uuid']);
        print_r($user_properties);
        $User = new \GuzabaPlatform\Platform\Authentication\Models\User($uuid);
        \GuzabaPlatform\Users\Models\Users::update($User, $user_properties, $granted_roles_uuids);
        return self::get_structured_ok_response( ['message' => sprintf(t::_('The user %1s with UUID %2s was updated.'), $User->user_name, $User->get_uuid() )] );
    }

    /**
     * @return ResponseInterface
     * @throws NotImplementedException
     * @throws ReflectionException
     * @throws \Azonmedia\Exceptions\InvalidArgumentException
     */
    public function remove(): ResponseInterface
    {
        throw new NotImplementedException(sprintf(t::_('Deleting users is not allowed. Please use %1s() (route: %2s).'), __CLASS__.'::disable', '/admin/users/user/{uuid}/disable' ));
    }

    /**
     * @param string $uuid
     * @return ResponseInterface
     * @throws \Azonmedia\Exceptions\InvalidArgumentException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RunTimeException
     * @throws ConfigurationException
     * @throws MultipleValidationFailedException
     * @throws ReflectionException
     */
    public function disable(string $uuid): ResponseInterface
    {
        $User = new \GuzabaPlatform\Platform\Authentication\Models\User($uuid);
        $User->disable();
        return self::get_structured_ok_response( ['message' => sprintf(t::_('The user %1s was disabled.'), $User->user_name)] );
    }

    /**
     * @param string $uuid
     * @return ResponseInterface
     * @throws \Azonmedia\Exceptions\InvalidArgumentException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RunTimeException
     * @throws ConfigurationException
     * @throws MultipleValidationFailedException
     * @throws ReflectionException
     */
    public function enable(string $uuid): ResponseInterface
    {
        $User = new \GuzabaPlatform\Platform\Authentication\Models\User($uuid);
        $User->enable();
        return self::get_structured_ok_response( ['message' => sprintf(t::_('The user %1s was enabled.'), $User->user_name)] );
    }

    /**
     * @param string $uuid
     * @param string $role_uuid
     * @return ResponseInterface
     * @throws ConfigurationException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws ReflectionException
     * @throws RunTimeException
     * @throws \Azonmedia\Exceptions\InvalidArgumentException
     */
    public function grant_role(string $uuid, string $role_uuid): ResponseInterface
    {
        $User = new \GuzabaPlatform\Platform\Authentication\Models\User($uuid);
        $Role = new Role($role_uuid);
        $User->grant_role($Role);
        return self::get_structured_ok_response( ['message' => sprintf(t::_('The user %1s was granted role %2s.'), $User->user_name, $Role->role_name )] );
    }

    /**
     * @param string $uuid
     * @param string $role_uuid
     * @return ResponseInterface
     * @throws ConfigurationException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws ReflectionException
     * @throws RunTimeException
     * @throws \Azonmedia\Exceptions\InvalidArgumentException
     */
    public function revoke_role(string $uuid, string $role_uuid): ResponseInterface
    {
        $User = new \GuzabaPlatform\Platform\Authentication\Models\User($uuid);
        $Role = new Role($role_uuid);
        $User->revoke_role($Role);
        return self::get_structured_ok_response( ['message' => sprintf(t::_('The user %1s was revoked role %2s.'), $User->user_name, $Role->role_name )] );
    }

    /**
     * Logs the current user as the selected user
     * @param string $uuid
     * @return ResponseInterface
     */
    public function login(string $uuid): ResponseInterface
    {
        //TODO implement
    }


}