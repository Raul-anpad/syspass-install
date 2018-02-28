<?php
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2018, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Services\Auth;

defined('APP_ROOT') || die();

use Defuse\Crypto\Exception\CryptoException;
use SP\Config\ConfigData;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Core\Exceptions\SPException;
use SP\Core\Language;
use SP\Core\UI\Theme;
use SP\DataModel\UserLoginData;
use SP\DataModel\UserPreferencesData;
use SP\Http\Request;
use SP\Providers\Auth\Auth;
use SP\Providers\Auth\AuthResult;
use SP\Providers\Auth\AuthUtil;
use SP\Providers\Auth\Browser\BrowserAuthData;
use SP\Providers\Auth\Database\DatabaseAuthData;
use SP\Providers\Auth\Ldap\LdapAuthData;
use SP\Repositories\Track\TrackRequest;
use SP\Services\Crypt\TemporaryMasterPassService;
use SP\Services\Service;
use SP\Services\Track\TrackService;
use SP\Services\User\UserLoginRequest;
use SP\Services\User\UserPassService;
use SP\Services\User\UserService;
use SP\Services\UserPassRecover\UserPassRecoverService;
use SP\Services\UserProfile\UserProfileService;
use SP\Util\Util;

/**
 * Class LoginService
 *
 * @package SP\Services
 */
class LoginService extends Service
{
    /**
     * Estados
     */
    const STATUS_INVALID_LOGIN = 1;
    const STATUS_INVALID_MASTER_PASS = 2;
    const STATUS_USER_DISABLED = 3;
    const STATUS_NEED_OLD_PASS = 5;
    const STATUS_MAX_ATTEMPTS_EXCEEDED = 6;
    const STATUS_PASS_RESET = 7;
    const STATUS_PASS = 0;
    const STATUS_NONE = 100;

    /**
     * @var UserLoginData
     */
    protected $userLoginData;
    /**
     * @var ConfigData
     */
    protected $configData;
    /**
     * @var Theme
     */
    protected $theme;
    /**
     * @var UserService
     */
    protected $userService;
    /**
     * @var Language
     */
    protected $language;
    /**
     * @var TrackService
     */
    protected $trackService;
    /**
     * @var TrackRequest
     */
    protected $trackRequest;

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SP\Core\Exceptions\InvalidArgumentException
     */
    public function initialize()
    {
        $this->configData = $this->config->getConfigData();
        $this->theme = $this->dic->get(Theme::class);
        $this->userService = $this->dic->get(UserService::class);
        $this->language = $this->dic->get(Language::class);
        $this->trackService = $this->dic->get(TrackService::class);

        $this->userLoginData = new UserLoginData();
        $this->trackRequest = TrackService::getTrackRequest('login');
    }

    /**
     * Ejecutar las acciones de login
     *
     * @return LoginResponse
     * @throws AuthException
     * @throws SPException
     * @throws \Defuse\Crypto\Exception\EnvironmentIsBrokenException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SP\Core\Exceptions\ConstraintException
     * @throws \SP\Core\Exceptions\QueryException
     * @throws \Exception
     */
    public function doLogin()
    {
        $this->userLoginData->setLoginUser(Request::analyze('user'));
        $this->userLoginData->setLoginPass(Request::analyzeEncrypted('pass'));

        if ($this->trackService->checkTracking($this->trackRequest)) {
            $this->addTracking();

            throw new AuthException(
                __u('Intentos excedidos'),
                AuthException::INFO,
                null,
                self::STATUS_MAX_ATTEMPTS_EXCEEDED
            );
        }

        $auth = new Auth($this->userLoginData, $this->configData);

        if (($result = $auth->doAuth()) !== false) {
            // Ejecutar la acción asociada al tipo de autentificación
            foreach ($result as $authResult) {
                /** @var AuthResult $authResult */
                if ($authResult->isAuthGranted() === true
                    && $this->{$authResult->getAuth()}($authResult->getData()) === true) {
                    break;
                }
            }
        } else {
            $this->addTracking();

            throw new AuthException(
                __u('Login incorrecto'),
                AuthException::INFO,
                __FUNCTION__,
                self::STATUS_INVALID_LOGIN
            );
        }

        if (($loginResponse = $this->checkUser())->getStatus() !== self::STATUS_NONE) {
            return $loginResponse;
        }

        $this->loadMasterPass();
        $this->setUserSession();
        $this->loadUserPreferences();
        $this->cleanUserData();

        return new LoginResponse(self::STATUS_PASS, 'index.php?r=index');
    }

    /**
     * Añadir un seguimiento
     *
     * @throws AuthException
     */
    private function addTracking()
    {
        try {
            $this->trackService->add($this->trackRequest);
        } catch (\Exception $e) {
            throw new AuthException(
                __u('Error interno'),
                AuthException::ERROR,
                null,
                Service::STATUS_INTERNAL_ERROR
            );
        }
    }

    /**
     * Comprobar estado del usuario
     *
     * @throws AuthException
     * @throws \Defuse\Crypto\Exception\EnvironmentIsBrokenException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SP\Core\Exceptions\ConstraintException
     * @throws \SP\Core\Exceptions\QueryException
     * @return LoginResponse
     */
    protected function checkUser()
    {
        $userLoginResponse = $this->userLoginData->getUserLoginResponse();

        // Comprobar si el usuario está deshabilitado
        if ($userLoginResponse->getIsDisabled()) {
            $this->eventDispatcher->notifyEvent('login.checkUser.disabled',
                new Event($this,
                    EventMessage::factory()
                        ->addDescription(__u('Usuario deshabilitado'))
                        ->addDetail(__u('Usuario'), $userLoginResponse->getLogin()))
            );

            $this->addTracking();

            throw new AuthException(
                __u('Usuario deshabilitado'),
                AuthException::INFO,
                null,
                self::STATUS_USER_DISABLED
            );
        }

        // Comprobar si se ha forzado un cambio de clave
        if ($userLoginResponse->getIsChangePass()) {
            $this->eventDispatcher->notifyEvent('login.checkUser.changePass',
                new Event($this,
                    EventMessage::factory()
                        ->addDetail(__u('Usuario'), $userLoginResponse->getLogin()))
            );

            $hash = Util::generateRandomBytes(16);

            $this->dic->get(UserPassRecoverService::class)->add($userLoginResponse->getId(), $hash);

            return new LoginResponse(self::STATUS_PASS_RESET, 'index.php?r=userPassReset/change/' . $hash);
        }

        return new LoginResponse(self::STATUS_NONE);
    }

    /**
     * Cargar la clave maestra o solicitarla
     *
     * @throws AuthException
     * @throws SPException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function loadMasterPass()
    {
        $temporaryMasterPass = $this->dic->get(TemporaryMasterPassService::class);
        $userPassService = $this->dic->get(UserPassService::class);

        $masterPass = Request::analyzeEncrypted('mpass');
        $oldPass = Request::analyzeEncrypted('oldpass');

        try {
            if ($masterPass) {
                if ($temporaryMasterPass->checkTempMasterPass($masterPass)) {
                    $this->eventDispatcher->notifyEvent('login.masterPass.temporary',
                        new Event($this, EventMessage::factory()->addDescription(__u('Usando clave temporal')))
                    );

                    $masterPass = $temporaryMasterPass->getUsingKey($masterPass);
                }

                if ($userPassService->updateMasterPassOnLogin($masterPass, $this->userLoginData)->getStatus() !== UserPassService::MPASS_OK) {
                    $this->eventDispatcher->notifyEvent('login.masterPass',
                        new Event($this, EventMessage::factory()->addDescription(__u('Clave maestra incorrecta')))
                    );

                    $this->addTracking();

                    throw new AuthException(
                        __u('Clave maestra incorrecta'),
                        AuthException::INFO,
                        null,
                        self::STATUS_INVALID_MASTER_PASS
                    );
                }

                $this->eventDispatcher->notifyEvent('login.masterPass',
                    new Event($this, EventMessage::factory()->addDescription(__u('Clave maestra actualizada')))
                );
            } else if ($oldPass) {
                if (!$userPassService->updateMasterPassFromOldPass($oldPass, $this->userLoginData)->getStatus() !== UserPassService::MPASS_OK) {
                    $this->eventDispatcher->notifyEvent('login.masterPass',
                        new Event($this, EventMessage::factory()->addDescription(__u('Clave maestra incorrecta')))
                    );

                    $this->addTracking();

                    throw new AuthException(
                        __u('Clave maestra incorrecta'),
                        AuthException::INFO,
                        null,
                        self::STATUS_INVALID_MASTER_PASS
                    );
                }

                $this->eventDispatcher->notifyEvent('login.masterPass',
                    new Event($this, EventMessage::factory()->addDescription(__u('Clave maestra actualizada')))
                );
            } else {
                switch ($userPassService->loadUserMPass($this->userLoginData)->getStatus()) {
                    case UserPassService::MPASS_CHECKOLD:
                        throw new AuthException(
                            __u('Es necesaria su clave anterior'),
                            AuthException::INFO,
                            null,
                            self::STATUS_NEED_OLD_PASS
                        );
                        break;
                    case UserPassService::MPASS_NOTSET:
                    case UserPassService::MPASS_CHANGED:
                    case UserPassService::MPASS_WRONG:
                        $this->addTracking();

                        throw new AuthException(
                            __u('La clave maestra no ha sido guardada o es incorrecta'),
                            AuthException::INFO,
                            null,
                            self::STATUS_INVALID_MASTER_PASS
                        );
                        break;
                }
            }
        } catch (CryptoException $e) {
            $this->eventDispatcher->notifyEvent('exception', new Event($e));

            throw new AuthException(
                __u('Error interno'),
                AuthException::ERROR,
                $e->getMessage(),
                Service::STATUS_INTERNAL_ERROR,
                $e
            );
        }
    }

    /**
     * Cargar la sesión del usuario
     *
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \SP\Core\Exceptions\ConstraintException
     * @throws \SP\Core\Exceptions\QueryException
     */
    protected function setUserSession()
    {
        $userLoginResponse = $this->userLoginData->getUserLoginResponse();

        // Actualizar el último login del usuario
        $this->userService->updateLastLoginById($userLoginResponse->getId());

        // Cargar las variables de ussuario en la sesión
        $this->session->setUserData($userLoginResponse);
        $this->session->setUserProfile($this->dic->get(UserProfileService::class)->getById($userLoginResponse->getUserProfileId())->getProfile());

        if ($this->configData->isDemoEnabled()) {
            $userLoginResponse->setPreferences(new UserPreferencesData());
        }

        $this->eventDispatcher->notifyEvent('login.session.load',
            new Event($this, EventMessage::factory()->addDetail(__u('Usuario'), $userLoginResponse->getLogin()))
        );
    }

    /**
     * Cargar las preferencias del usuario y comprobar si usa 2FA
     */
    protected function loadUserPreferences()
    {
        $this->language->setLanguage(true);

        $this->theme->initTheme(true);

        $this->session->setAuthCompleted(true);

        $this->eventDispatcher->notifyEvent('login.preferences.load', new Event($this));
    }

    /**
     * Limpiar datos de usuario
     */
    private function cleanUserData()
    {
        $this->userLoginData->setUserLoginResponse();
    }

    /**
     * Autentificación LDAP
     *
     * @param LdapAuthData $authData
     * @return bool
     * @throws \SP\Core\Exceptions\SPException
     * @throws AuthException
     */
    protected function authLdap(LdapAuthData $authData)
    {
        if ($authData->getStatusCode() > 0) {
            $eventMessage = EventMessage::factory()
                ->addDetail(__u('Tipo'), __FUNCTION__)
                ->addDetail(__u('Servidor LDAP'), $authData->getServer())
                ->addDetail(__u('Usuario'), $this->userLoginData->getLoginUser());

            if ($authData->getStatusCode() === 49) {
                $eventMessage->addDescription(__u('Login incorrecto'));

                $this->addTracking();

                $this->eventDispatcher->notifyEvent('login.auth.ldap', new Event($this, $eventMessage));

                throw new AuthException(
                    __u('Login incorrecto'),
                    AuthException::INFO,
                    __FUNCTION__,
                    self::STATUS_INVALID_LOGIN
                );
            }

            if ($authData->getStatusCode() === 701) {
                $eventMessage->addDescription(__u('Cuenta expirada'));

                $this->eventDispatcher->notifyEvent('login.auth.ldap', new Event($this, $eventMessage));

                throw new AuthException(
                    __u('Cuenta expirada'),
                    AuthException::INFO,
                    __FUNCTION__,
                    self::STATUS_USER_DISABLED
                );
            }

            if ($authData->getStatusCode() === 702) {
                $eventMessage->addDescription(__u('El usuario no tiene grupos asociados'));

                $this->eventDispatcher->notifyEvent('login.auth.ldap', new Event($this, $eventMessage));

                throw new AuthException(
                    __u('El usuario no tiene grupos asociados'),
                    AuthException::INFO,
                    __FUNCTION__,
                    self::STATUS_USER_DISABLED
                );
            }

            if ($authData->isAuthGranted() === false) {
                return false;
            }

            $eventMessage->addDescription(__u('Error interno'));

            $this->eventDispatcher->notifyEvent('login.auth.ldap', new Event($this, $eventMessage));

            throw new AuthException(
                __u('Error interno'),
                AuthException::INFO,
                __FUNCTION__,
                Service::STATUS_INTERNAL_ERROR
            );
        }

        $this->eventDispatcher->notifyEvent('login.auth.ldap',
            new Event($this, EventMessage::factory()
                ->addDetail(__u('Tipo'), __FUNCTION__)
                ->addDetail(__u('Servidor LDAP'), $authData->getServer())
            )
        );

        try {
            $userLoginRequest = new UserLoginRequest();
            $userLoginRequest->setLogin($this->userLoginData->getLoginUser());
            $userLoginRequest->setPassword($this->userLoginData->getLoginPass());
            $userLoginRequest->setEmail($authData->getEmail());
            $userLoginRequest->setName($authData->getName());
            $userLoginRequest->setIsLdap(1);


            // Verificamos si el usuario existe en la BBDD
            if ($this->userService->checkExistsByLogin($this->userLoginData->getLoginUser())) {
                // Actualizamos el usuario de LDAP en MySQL
                $this->userService->updateOnLogin($userLoginRequest);
            } else {
                // Creamos el usuario de LDAP en MySQL
                $this->userService->createOnLogin($userLoginRequest);
            }
        } catch (\Exception $e) {
            throw new AuthException(
                __u('Error interno'),
                AuthException::ERROR,
                __FUNCTION__,
                Service::STATUS_INTERNAL_ERROR,
                $e
            );
        }

        return true;
    }

    /**
     * Autentificación en BD
     *
     * @param DatabaseAuthData $authData
     * @return bool
     * @throws \SP\Core\Exceptions\SPException
     * @throws AuthException
     */
    protected function authDatabase(DatabaseAuthData $authData)
    {
        $eventMessage = EventMessage::factory()
            ->addDetail(__u('Tipo'), __FUNCTION__)
            ->addDetail(__u('Usuario'), $this->userLoginData->getLoginUser());

        // Autentificamos con la BBDD
        if ($authData->getAuthenticated() === 0) {
            if ($authData->isAuthGranted() === false) {
                return false;
            }

            $this->addTracking();

            $eventMessage->addDescription(__u('Login incorrecto'));

            $this->eventDispatcher->notifyEvent('login.auth.database', new Event($this, $eventMessage));

            throw new AuthException(
                __u('Login incorrecto'),
                AuthException::INFO,
                __FUNCTION__,
                self::STATUS_INVALID_LOGIN
            );
        }

        if ($authData->getAuthenticated() === 1) {
            $this->eventDispatcher->notifyEvent('login.auth.database', new Event($this, $eventMessage));
        }

        return true;
    }

    /**
     * Comprobar si el cliente ha enviado las variables de autentificación
     *
     * @param BrowserAuthData $authData
     * @return mixed
     * @throws AuthException
     */
    protected function authBrowser(BrowserAuthData $authData)
    {
        $eventMessage = EventMessage::factory()
            ->addDetail(__u('Tipo'), __FUNCTION__)
            ->addDetail(__u('Usuario'), $this->userLoginData->getLoginUser())
            ->addDetail(__u('Autentificación'), sprintf('%s (%s)', AuthUtil::getServerAuthType(), $authData->getName()));

        // Comprobar si concide el login con la autentificación del servidor web
        if ($authData->getAuthenticated() === 0) {
            if ($authData->isAuthGranted() === false) {
                return false;
            }

            $this->addTracking();

            $eventMessage->addDescription(__u('Login incorrecto'));

            $this->eventDispatcher->notifyEvent('login.auth.browser', new Event($this, $eventMessage));

            throw new AuthException(
                __u('Login incorrecto'),
                AuthException::INFO,
                __FUNCTION__,
                self::STATUS_INVALID_LOGIN
            );
        }

        if ($authData->getAuthenticated() === 1 && $this->configData->isAuthBasicAutoLoginEnabled()) {
            try {
                $userLoginRequest = new UserLoginRequest();
                $userLoginRequest->setLogin($this->userLoginData->getLoginUser());
                $userLoginRequest->setPassword($this->userLoginData->getLoginPass());

                // Verificamos si el usuario existe en la BBDD
                if (!$this->userService->checkExistsByLogin($this->userLoginData->getLoginUser())) {
                    // Creamos el usuario de SSO en la BBDD
                    $this->userService->createOnLogin($userLoginRequest);
                }

                $this->eventDispatcher->notifyEvent('login.auth.browser', new Event($this, $eventMessage));

                return true;
            } catch (\Exception $e) {
                throw new AuthException(
                    __u('Error interno'),
                    AuthException::ERROR,
                    __FUNCTION__,
                    Service::STATUS_INTERNAL_ERROR,
                    $e
                );
            }
        }

        return null;
    }
}