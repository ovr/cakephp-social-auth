<?php
/**
 * ADmad\SocialAuth plugin.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 */

namespace ADmad\SocialAuth\Middleware;

use ADmad\SocialAuth\Http\Client;
use Cake\Core\Configure;
use Cake\Core\InstanceConfigTrait;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ModelAwareTrait;
use Cake\Event\EventManagerTrait;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Log\Log;
use Cake\Network\Exception\BadRequestException;
use Cake\Routing\Router;
use RuntimeException;
use SocialConnect\Auth\Service;
use SocialConnect\Common\Entity\User as SocialConnectUser;
use SocialConnect\Common\Exception as SocialConnectException;
use SocialConnect\Provider\AccessTokenInterface;
use SocialConnect\Provider\Exception\InvalidResponse;
use SocialConnect\Provider\Session\Session;

class SocialAuthMiddleware
{
    use EventManagerTrait;
    use InstanceConfigTrait;
    use ModelAwareTrait;

    /**
     * The query string key used for remembering the referrered page when
     * getting redirected to login.
     */
    const QUERY_STRING_REDIRECT = 'redirect';

    /**
     * Default config.
     *
     * ### Options
     *
     * - `requestMethod`: Request method type. Default "POST".
     * - `loginUrl`: Login page URL. In case of auth failure user is redirected
     *   to this login page with "error" query string var.
     * - `userEntity`: Whether to return entity or array for user. Default `false`.
     * - `userModel`: User model name. Default "User".
     * - `socialProfileModel`: Social profile model. Default "ADmad/SocialAuth.SocialProfiles".
     * - `finder`: Table finder. Default "all".
     * - `fields`: Specify password field for removal in returned user identity.
     *   Default `['password' => 'password']`.
     * - `sessionKey`: Session key to write user record to. Default "Auth.User".
     * - `getUserCallback`: The callback method which will be called on user
     *   model for getting user record matching social profile. Defaults "getUser".
     * - `serviceConfig`: SocialConnect/Auth service providers config.
     * - `logErrors`: Whether social connect errors should be logged. Default `true`.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'requestMethod' => 'POST',
        'loginUrl' => '/users/login',
        'loginRedirect' => '/',
        'userEntity' => false,
        'userModel' => 'Users',
        'finder' => 'all',
        'fields' => [
            'password' => 'password',
        ],
        'sessionKey' => 'Auth.User',
        'getUserCallback' => 'getUser',
        'serviceConfig' => [],
        'logErrors' => true,
    ];

    /**
     * SocialConnect service.
     *
     * @var \SocialConnect\Auth\Service|null
     */
    protected $_service;

    /**
     * User model instance.
     *
     * @var \Cake\Datasource\RepositoryInterface|null
     */
    protected $_userModel;

    /**
     * Profile model instance.
     *
     * @var \Cake\Datasource\RepositoryInterface|null
     */
    protected $_profileModel;

    /**
     * Error.
     *
     * @var string
     */
    protected $_error;

    /**
     * Constructor.
     *
     * @param array $config Configuration.
     */
    public function __construct(array $config = [])
    {
        $this->setConfig($config);
    }

    /**
     * Handle authentication.
     *
     * @param \Cake\Http\ServerRequest $request The request.
     * @param \Cake\Http\Response $response The response.
     * @param callable $next Callback to invoke the next middleware.
     *
     * @return \Cake\Http\Response A response.
     */
    public function __invoke(ServerRequest $request, Response $response, $next)
    {
        $action = $request->getParam('action');

        if ($request->getParam('plugin') !== 'ADmad/SocialAuth'
            || $request->getParam('controller') !== 'Auth'
            || !in_array($action, ['login', 'callback'], true)
        ) {
            return $next($request, $response);
        }

        $method = '_handle' . ucfirst($action) . 'Action';

        return $this->{$method}($request, $response);
    }

    /**
     * Handle login action, initiate authentication process.
     *
     * @param \Cake\Http\ServerRequest $request The request.
     * @param \Cake\Http\Response $response The response.
     *
     * @throws \Cake\Network\Exception\BadRequestException If login action is
     *  called with incorrect request method.
     *
     * @return \Cake\Http\Response A response.
     */
    protected function _handleLoginAction(ServerRequest $request, Response $response)
    {
        $providerName = $request->getParam('provider');

        if ($request->getMethod() !== $this->getConfig('requestMethod')) {
            throw new BadRequestException();
        }

        $provider = $this->_getService($request)->getProvider($providerName);
        $authUrl = $provider->makeAuthUrl();

        $this->_setRedirectUrl($request);

        return $response->withLocation($authUrl);
    }

    /**
     * Handle callback action.
     *
     * @param \Cake\Http\ServerRequest $request The request.
     * @param \Cake\Http\Response $response The response.
     *
     * @return \Cake\Http\Response A response.
     */
    protected function _handleCallbackAction(ServerRequest $request, Response $response)
    {
        $config = $this->getConfig();
        $providerName = $request->getParam('provider');

        $profile = $this->_getProfile($providerName, $request);
        if (!$profile) {
            return $response->withLocation(
                Router::url($config['loginUrl'], true) . '?error=' . $this->_error
            );
        }

        $user = $this->_getUser($profile);
        if (!$user) {
            return $response->withLocation(
                Router::url($config['loginUrl'], true) . '?error=' . $this->_error
            );
        }

        $user->unsetProperty($config['fields']['password']);

        if (!$config['userEntity']) {
            $user = $user->toArray();
        }

        $request->getSession()->write($config['sessionKey'], $user);

        return $response->withLocation(
            Router::url($this->_getRedirectUrl($request), true)
        );
    }

    /**
     * Get social profile record.
     *
     * @param string $providerName Provider name.
     * @param \Cake\Http\ServerRequest $request Request instance.
     *
     * @return \Cake\Datasource\EntityInterface|null
     */
    protected function _getProfile($providerName, ServerRequest $request)
    {
        $this->_profileModel = $this->loadModel($this->config('socialProfileModel'));
        $this->_profileModel->belongsTo($this->config('userModel'));

        try {
            $provider = $this->_getService($request)->getProvider($providerName);
            $accessToken = $provider->getAccessTokenByRequestParameters($request->getQueryParams());
            $identity = $provider->getIdentity($accessToken);
        } catch (SocialConnectException $e) {
            $this->_error = 'provider_failure';

            if ($this->getConfig('logErrors')) {
                Log::error($this->_getLogMessage($request, $e));
            }

            return null;
        }

        $profile = $this->_profileModel->find()
            ->where([
                $this->_profileModel->aliasField('provider') => $providerName,
                $this->_profileModel->aliasField('identifier') => $identity->id,
            ])
            ->first();

        $profile = $this->_patchProfile(
            $providerName,
            $identity,
            $accessToken,
            $profile ?: null
        );
        if ($profile->isDirty()) {
            $this->_saveProfile($profile);
        }

        return $profile;
    }

    /**
     * Get user record.
     *
     * @param \Cake\Datasource\EntityInterface $profile Social profile entity
     *
     * @return array|\Cake\Datasource\EntityInterface|null User array or entity
     *   on success, null on failure.
     */
    protected function _getUser($profile)
    {
        $this->_userModel = $this->loadModel($this->config('userModel'));

        $user = null;

        if ($profile->get('user_id')) {
            $userPkField = $this->_userModel->aliasField($this->_userModel->getPrimaryKey());

            $user = $this->_userModel->find()
                ->where([
                    $userPkField => $profile->get('user_id'),
                ])
                ->find($this->getConfig('finder'))
                ->first();
        }

        if (!$user) {
            if ($profile->get('user_id')) {
                $this->_error = 'finder_failure';

                return null;
            }

            $user = $this->_getUserEntity($profile);
            $profile->set('user_id', $user->id);
        }

        if ($profile->isDirty()) {
            $this->_saveProfile($profile);
        }

        $user->set('social_profile', $profile);
        $user->unsetProperty($this->getConfig('fields.password'));

        return $user;
    }

    /**
     * Get social profile entity.
     *
     * @param string $providerName Provider name.
     * @param \SocialConnect\Common\Entity\User $identity Social connect entity.
     * @param \SocialConnect\Provider\AccessTokenInterface $accessToken Access token
     * @param \Cake\Datasource\EntityInterface $profile Social profile entity
     *
     * @return \Cake\Datasource\EntityInterface
     */
    protected function _patchProfile(
        $providerName,
        SocialConnectUser $identity,
        AccessTokenInterface $accessToken,
        EntityInterface $profile = null
    ) {
        if ($profile === null) {
            $profile = $this->_profileModel->newEntity([
                'provider' => $providerName,
            ]);
        }

        $data = [
            'access_token' => $accessToken,
        ];

        foreach (get_object_vars($identity) as $key => $value) {
            switch ($key) {
                case 'id':
                    $data['identifier'] = $value;
                    break;
                case 'lastname':
                    $data['last_name'] = $value;
                    break;
                case 'firstname':
                    $data['first_name'] = $value;
                    break;
                case 'birthday':
                    $data['birth_date'] = $value;
                    break;
                case 'emailVerified':
                    $data['email_verified'] = $value;
                    break;
                case 'fullname':
                    $data['full_name'] = $value;
                    break;
                case 'sex':
                    $data['gender'] = $value;
                    break;
                default:
                    $data[$key] = $value;
                    break;
            }
        }

        return $this->_profileModel->patchEntity($profile, $data);
    }

    /**
     * Get new user entity.
     *
     * It dispatches a `SocialConnect.getUser` event. A listener must return
     * an entity for new user record.
     *
     * @param \Cake\Datasource\EntityInterface $profile Social profile entity.
     *
     * @return \Cake\Datasource\EntityInterface User entity.
     */
    protected function _getUserEntity(EntityInterface $profile)
    {
        $callbackMethod = $this->getConfig('getUserCallback');

        $user = call_user_func([$this->_userModel, $callbackMethod], $profile);

        return $user;
    }

    /**
     * Save social profile entity.
     *
     * @param \Cake\Datasource\EntityInterface $profile Social profile entity.
     *
     * @throws \RuntimeException Thrown when unable to save social profile.
     *
     * @return void
     */
    protected function _saveProfile(EntityInterface $profile)
    {
        if (!$this->_profileModel->save($profile)) {
            throw new RuntimeException('Unable to save social profile.');
        }
    }

    /**
     * Get social connect service instance.
     *
     * @param \Cake\Http\ServerRequest $request Request instance.
     *
     * @return \SocialConnect\Auth\Service
     */
    protected function _getService(ServerRequest $request)
    {
        if ($this->_service !== null) {
            return $this->_service;
        }

        $serviceConfig = $this->getConfig('serviceConfig');
        if (empty($serviceConfig)) {
            Configure::load('social_auth');
            $serviceConfig = Configure::consume('SocialAuth');
        }

        $serviceConfig['redirectUri'] = Router::url([
            'plugin' => 'ADmad/SocialAuth',
            'controller' => 'Auth',
            'action' => 'callback',
        ], true);

        $request->getSession()->start();

        $this->_service = new Service(
            new Client(),
            new Session(),
            $serviceConfig
        );

        return $this->_service;
    }

    /**
     * Save URL to redirect to after authentication to session.
     *
     * @param \Cake\Http\ServerRequest $request Request instance.
     *
     * @return void
     */
    protected function _setRedirectUrl(ServerRequest $request)
    {
        $request->getSession()->delete('SocialAuth.redirectUrl');

        $queryParams = $request->getQueryParams();
        if (empty($queryParams[static::QUERY_STRING_REDIRECT])) {
            return;
        }

        $redirectUrl = $queryParams[static::QUERY_STRING_REDIRECT];
        if (substr($redirectUrl, 0, 1) !== '/'
            || substr($redirectUrl, 0, 2) === '//'
        ) {
            return;
        }

        $request->getSession()->write('SocialAuth.redirectUrl', $redirectUrl);
    }

    /**
     * Get URL to redirect to after authentication.
     *
     * @param \Cake\Http\ServerRequest $request Request instance.
     *
     * @return string
     */
    protected function _getRedirectUrl(ServerRequest $request)
    {
        $redirectUrl = $request->getSession()->read('SocialAuth.redirectUrl');
        if ($redirectUrl) {
            $request->getSession()->delete('SocialAuth.redirectUrl');

            return $redirectUrl;
        }

        return $this->getConfig('loginRedirect');
    }

    /**
     * Generate the error log message.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The current request.
     * @param \Exception $exception The exception to log a message for.
     *
     * @return string Error message
     */
    protected function _getLogMessage($request, $exception)
    {
        $message = sprintf(
            '[%s] %s',
            get_class($exception),
            $exception->getMessage()
        );

        $message .= "\nRequest URL: " . $request->getRequestTarget();

        $referer = $request->getHeaderLine('Referer');
        if ($referer) {
            $message .= "\nReferer URL: " . $referer;
        }

        if ($exception instanceof InvalidResponse && $exception->getResponse()) {
            $message .= "\nProvider Response: " . $exception->getResponse()->getBody();
        }

        $message .= "\nStack Trace:\n" . $exception->getTraceAsString() . "\n\n";

        return $message;
    }
}
