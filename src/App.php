<?php

declare(strict_types=1);

namespace Ebcms;

use Closure;
use Composer\Autoload\ClassLoader;
use Ebcms\Container;
use Ebcms\ResponseFactory;
use Ebcms\ServerRequestFactory;
use ErrorException;
use InvalidArgumentException;
use Mobile_Detect;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

class App
{
    /**
     * @var Container
     */
    private $container;

    /**
     * @var string
     */
    private $app_path;

    /**
     * @var string
     */
    private $request_package;

    /**
     * @var string
     */
    private $request_class;

    /**
     * @var ?ResponseInterface
     */
    private $response;

    private function __construct(Container $container)
    {
        $this->container = $container;
    }

    private function __clone()
    {
    }

    public function run($app_path = '..')
    {
        if ($this->app_path) {
            return;
        }
        $this->app_path = $app_path;

        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            throw new ErrorException($errstr . ' on line ' . $errline . ' in file ' . $errfile, $errno);
        });

        try {

            $this->reg();

            $this->emitHook('app.init');
            $class_name = $this->reflectRequestClass();
            $this->emitHook('app.start');

            if (
                $class_name &&
                $this->container->has($class_name)
            ) {
                $callable = [$this->container->get($class_name), 'handle'];
            } else {
                $callable = function (): ResponseInterface {
                    return (new ResponseFactory())->createResponse(404);
                };
            }

            if ($this->request_package) {
                $this->emitHook('app.' . str_replace('/', '.', $this->request_package) . '.start');
            }

            $this->response = $this->getRequestHandler()->execute(function () use ($callable): ResponseInterface {
                return $this->toResponse($this->execute($callable));
            }, $this->container->get(ServerRequestInterface::class));

            if ($this->request_package) {
                $this->emitHook('app.' . str_replace('/', '.', $this->request_package) . '.end');
            }

            $this->emitHook('app.end');
        } catch (Throwable $th) {
            $this->response = (new ResponseFactory())->createResponse(500);
            $this->emitHook('app.exception', $th);
            ob_clean();
        }

        $this->response = $this->response->withHeader('X-Powered-By', 'EBCMS');
        (new ResponseEmitter)->emit($this->response);
    }

    private function reg()
    {
        $alias = [];
        $alias_file = $this->getAppPath() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'alias.php';
        if (file_exists($alias_file)) {
            $alias = (array) include $alias_file;
        }

        $alias = array_merge([
            CacheInterface::class => SimpleCacheNullAdapter::class,
            LoggerInterface::class => NullLogger::class,
            Template::class => function (): Template {
                return App::getInstance()->execute(function (
                    CacheInterface $cache,
                    Container $container,
                    Hook $hook
                ) {
                    $template = new Template($cache);
                    $template->assign([
                        'app' => App::getInstance(),
                        'container' => $container,
                        'cache' => $container->get(CacheInterface::class),
                        'logger' => $container->get(LoggerInterface::class),
                        'request' => $container->get(Request::class),
                        'session' => $container->get(Session::class),
                        'config' => $container->get(Config::class),
                        'router' => $container->get(Router::class),
                        'hook' => $container->get(Hook::class),
                    ]);
                    $template->extend('/\{cache\s*(.*)\s*\}([\s\S]*)\{\/cache\}/Ui', function ($matchs) {
                        $params = array_filter(explode(',', trim($matchs[1])));
                        if (!isset($params[0])) {
                            $params[0] = 3600;
                        }
                        if (!isset($params[1])) {
                            $params[1] = 'tpl_extend_cache_' . md5($matchs[2]);
                        }
                        return '<?php echo call_user_func(function($args){
                                extract($args);
                                if (!$cache->has(\'' . $params[1] . '\')) {
                                    $res = $container->get(\Ebcms\Template::class)->renderFromString(base64_decode(\'' . base64_encode($matchs[2]) . '\'), $args, \'__' . $params[1] . '\');
                                    $cache->set(\'' . $params[1] . '\', $res, ' . $params[0] . ');
                                }else{
                                    $res = $cache->get(\'' . $params[1] . '\');
                                }
                                return $res;
                            }, get_defined_vars());?>';
                    });

                    /**
                     * @var Mobile_Detect
                     */
                    $mobile_detect = $container->get(Mobile_Detect::class);

                    foreach (App::getInstance()->getPackages() as $key => $value) {
                        $template->addPath($key, $value['dir'] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'template/default');
                        $template->addPath($key, App::getInstance()->getAppPath() . DIRECTORY_SEPARATOR . 'template/default' . DIRECTORY_SEPARATOR . $key, 9);
                        if ($mobile_detect->isMobile()) {
                            $template->addPath($key, $value['dir'] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'template/mobile', 10);
                            $template->addPath($key, App::getInstance()->getAppPath() . DIRECTORY_SEPARATOR . 'template/mobile' . DIRECTORY_SEPARATOR . $key, 19);
                        }
                        if ($mobile_detect->isTablet()) {
                            $template->addPath($key, $value['dir'] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'template/tablet', 20);
                            $template->addPath($key, App::getInstance()->getAppPath() . DIRECTORY_SEPARATOR . 'template/tablet' . DIRECTORY_SEPARATOR . $key, 29);
                        }
                    }

                    $hook->emit('template.instance', $template);
                    return $template;
                });
            },
        ], $alias, [
            ServerRequestInterface::class => function (): ServerRequestInterface {
                return $this->container->get(ServerRequestFactory::class)->createServerRequestFromGlobals();
            },
            ResponseFactoryInterface::class => ResponseFactory::class,
        ]);

        foreach ($alias as $key => $value) {
            if (is_string($key)) {
                if (!is_array($value)) {
                    $value = [$value];
                }
                if (is_string($value[0])) {
                    $value[0] = function () use ($value) {
                        return $this->container->get($value[0]);
                    };
                }
                $this->container->set($key, ...$value);
            }
        }
    }

    private function getRequestHandler(): RequestHandler
    {
        return $this->container->get(RequestHandler::class);
    }

    private function reflectRequestClass(): ?string
    {
        if (
            (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https')
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443')
        ) {
            $schema = 'https';
        } else {
            $schema = 'http';
        }

        $router = (function (): Router {
            return $this->container->get(Router::class);
        })();

        $router->getBuilder()->addCreater(function (array &$router) {
            if (is_string($router['handler'])) {
                $tmp_arr = array_values(array_filter(explode('\\', strtolower(preg_replace_callback(
                    '/([\w])([A-Z]{1})/',
                    function ($match) {
                        return $match[1] . '-' . lcfirst($match[2]);
                    },
                    $router['handler']
                )))));
                if ($tmp_arr[0] !== 'app' || $tmp_arr[3] !== 'http' || !isset($tmp_arr[4])) {
                    return;
                }
                unset($tmp_arr[0]);
                unset($tmp_arr[3]);
                $router['name'] = '/' . implode('/', $tmp_arr);
            }
        });

        $route_info = $router->getDispatcher()->dispatch(
            $_SERVER['REQUEST_METHOD'],
            $schema . '://' . $_SERVER['HTTP_HOST'] . $this->filterUrlPath()
        );

        switch ($route_info[0]) {
            case 0:
                $request_class = $this->reflectRequestClassFromPath($this->resolveRelativeUrlPath());
                if (!$request_class) {
                    return null;
                }
                break;

            case 1:
                $request_class = $route_info[1];
                if (!is_string($request_class)) {
                    return null;
                }

                $server_request = (function (): ServerRequestInterface {
                    return $this->container->get(ServerRequestInterface::class);
                })();

                if ($route_info[4]) {
                    foreach ($route_info[4] as $key => $value) {
                        $server_request = $server_request->withAttribute($key, $value);
                    }
                }

                if ($route_info[2] || $route_info[4]) {
                    $server_request = $server_request->withQueryParams(array_merge($_GET, $route_info[2], $route_info[4]));
                }

                $this->container->set(ServerRequestInterface::class, function () use ($server_request) {
                    return $server_request;
                });

                if ($route_info[3]) {
                    foreach ($route_info[3] as $middleware) {
                        if (is_string($middleware)) {
                            $this->getRequestHandler()->lazyMiddleware($middleware);
                        } elseif ($middleware instanceof MiddlewareInterface) {
                            $this->getRequestHandler()->middleware($middleware);
                        }
                    }
                }
                break;

            case 2:
                return null;
                break;
        }

        if (!$request_package = $this->reflectPackageFromRequestClass($request_class)) {
            return null;
        }
        $this->request_package = $request_package;
        $this->request_class = $request_class;
        return $request_class;
    }

    private function reflectPackageFromRequestClass(string $request_class): ?string
    {
        $tmp_arr = array_values(array_filter(explode('\\', strtolower(preg_replace_callback(
            '/([\w])([A-Z]{1})/',
            function ($match) {
                return $match[1] . '-' . lcfirst($match[2]);
            },
            $request_class
        )))));
        if ($tmp_arr[0] !== 'app' || $tmp_arr[3] !== 'http' || !isset($tmp_arr[4])) {
            return null;
        }
        $package = $tmp_arr[1] . '/' . $tmp_arr[2];
        if (!array_key_exists($package, $this->getPackages())) {
            return null;
        }
        return $package;
    }

    private function reflectRequestClassFromPath(string $path): ?string
    {
        $path_arr = array_map(function (string $val) {
            return strtolower(preg_replace('/([^A-Z])([A-Z])/', '$1-$2', $val));
        }, array_filter(explode('/', $path)));

        if (count($path_arr) < 3) {
            return null;
        }

        $vendor_name = array_shift($path_arr) ?: '';
        $package_name = array_shift($path_arr) ?: '';

        return str_replace(
            ['-'],
            '',
            ucwords('\\App\\' . $vendor_name . '\\' . $package_name . '\\Http\\' . implode('\\', $path_arr), '\\-')
        );
    }

    private function resolveRelativeUrlPath(): string
    {
        $url_path = $this->filterUrlPath();
        if (substr($url_path, -1) == '/') {
            $url_path .= 'index';
        }
        $script_name = '/' . implode('/', array_filter(explode(DIRECTORY_SEPARATOR, $_SERVER['SCRIPT_NAME'])));
        if (strpos($url_path, $script_name) === 0) {
            $prefix = $script_name;
        } else {
            $prefix = strlen(dirname($script_name)) > 1 ? dirname($script_name) : '';
        }
        return substr($url_path, strlen($prefix));
    }

    private function filterUrlPath(): string
    {
        $tmp_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $url_path = implode('/', array_filter(explode('/', $tmp_path)));
        $url_path = $url_path ? '/' . $url_path : '';
        $script_name = '/' . implode('/', array_filter(explode(DIRECTORY_SEPARATOR, $_SERVER['SCRIPT_NAME'])));
        if ($url_path === $script_name) {
            return $url_path . '/';
        }
        if (substr($tmp_path, -1) === '/') {
            return $url_path . '/';
        }
        return $url_path;
    }

    private function toResponse($result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        $response = (new ResponseFactory())->createResponse(200);
        if (is_scalar($result) || (is_object($result) && method_exists($result, '__toString'))) {
            $response->getBody()->write($result);
        } else {
            $response->getBody()->write(json_encode($result));
            $response = $response->withHeader('Content-Type', 'application/json');
        }
        return $response;
    }

    private function emitHook(string $name, &$params = [])
    {
        (function (): Hook {
            return $this->container->get(Hook::class);
        })()->emit($name, $params);
    }

    private function reflectArguments(
        ReflectionFunctionAbstract $method,
        array $args = []
    ): array {
        return array_map(function (ReflectionParameter $param) use ($method, $args) {
            $name = $param->getName();
            if (array_key_exists($name, $args)) {
                return $args[$name];
            }

            $type = $param->getType();
            if ($type !== null && !$type->isBuiltin()) {
                if ($this->container->has($type->getName())) {
                    $result = $this->container->get($type->getName());
                    $type_name = $type->getName();
                    if ($result instanceof $type_name) {
                        return $result;
                    }
                }
            }

            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }

            if ($param->isOptional()) {
                return;
            }

            throw new OutOfBoundsException(sprintf(
                'Unable to resolve a value for parameter (%s $%s) in [%s] method:[%s]',
                $param->getType()->getName(),
                $param->getName(),
                $method->getFileName(),
                $method->getName(),
            ));
        }, $method->getParameters());
    }

    public function execute($callable, array $default_args = [])
    {
        if (!is_callable($callable)) {
            throw new InvalidArgumentException('The argument $callable only accepts callable.');
        }
        if (is_array($callable)) {
            if (is_string($callable[0])) {
                $callable[0] = $this->container->get($callable[0]);
            }
            $args = $this->reflectArguments(new ReflectionMethod(...$callable), $default_args);
        } elseif ($callable instanceof Closure) {
            $args = $this->reflectArguments(new ReflectionFunction($callable), $default_args);
        } else {
            if (strpos($callable, '::')) {
                $args = $this->reflectArguments(new ReflectionMethod($callable), $default_args);
            } else {
                $args = $this->reflectArguments(new ReflectionFunction($callable), $default_args);
            }
        }
        return call_user_func($callable, ...$args);
    }

    public function getPackages(): array
    {
        static $packages;
        if (!$packages) {
            $cache = (function (): CacheInterface {
                return $this->container->get(CacheInterface::class);
            })();

            if (!$packages = $cache->get('packages_cache')) {
                $vendor_dir = dirname(dirname((new ReflectionClass(ClassLoader::class))->getFileName()));
                $packages = [];
                $installed = json_decode(file_get_contents($vendor_dir . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json'), true);
                foreach ($installed['packages'] as $package) {
                    if (
                        $package['type'] == 'ebcms-app'
                    ) {
                        $packages[$package['name']] = [
                            'dir' => $vendor_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $package['name']),
                        ];
                    }
                }
                $this->emitHook('app.packages', $packages);
                $cache->set('packages_cache', $packages);
            }
        }
        return $packages;
    }

    public function getAppPath(): ?string
    {
        return $this->app_path;
    }

    public function getRequestPackage(): ?string
    {
        return $this->request_package;
    }

    public function getRequestClass(): ?string
    {
        return $this->request_class;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->container->get(LoggerInterface::class);
    }

    public function getCache(): CacheInterface
    {
        return $this->container->get(CacheInterface::class);
    }

    public function getConfig(): Config
    {
        return $this->container->get(Config::class);
    }

    public function getRouter(): Router
    {
        return $this->container->get(Router::class);
    }

    public static function getInstance(): App
    {
        static $instance;
        if (!$instance instanceof self) {
            $container = new Container;
            $container->set(Container::class, function () use ($container): Container {
                return $container;
            });
            $container->set(ContainerInterface::class, function () use ($container): ContainerInterface {
                return $container;
            });
            $instance = new self($container);
            $container->set(App::class, function () use ($instance): App {
                return $instance;
            });
        }
        return $instance;
    }
}
