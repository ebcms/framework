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

/**
 * @property Container $container
 * @property string $app_path
 * @property string $request_package
 * @property string $request_class
 * @property ?ResponseInterface $response
 */
class App
{
    private $container;
    private $app_path;
    private $request_package;
    private $request_class;
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

        $this->container->set(ServerRequestInterface::class, function (): ServerRequestInterface {
            return $this->container->get(ServerRequestFactory::class)->createServerRequestFromGlobals();
        });
        $this->container->set(ResponseFactoryInterface::class, function (): ResponseFactoryInterface {
            return $this->container->get(ResponseFactory::class);
        });
        $this->container->set(CacheInterface::class, function (): CacheInterface {
            return $this->container->get(SimpleCacheNullAdapter::class);
        });
        $this->container->set(LoggerInterface::class, function (): LoggerInterface {
            return $this->container->get(NullLogger::class);
        });

        $request_handler = (function (): RequestHandler {
            return $this->container->get(RequestHandler::class);
        })();

        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            throw new ErrorException($errstr . ' on line ' . $errline . ' in file ' . $errfile, $errno);
        });


        try {
            $this->emitHook('app.init');
            $class_name = $this->reflectRequestClass();
            $this->emitHook('app.start');
            if ($class_name) {
                $callable = [$this->container->get($class_name), 'handle'];
                if ($this->request_package) {
                    $this->emitHook('app.' . str_replace('/', '.', $this->request_package) . '.start');
                }
                $this->response = $request_handler->execute(function () use ($callable): ResponseInterface {
                    return $this->toResponse($this->execute($callable));
                }, $this->container->get(ServerRequestInterface::class));
                if ($this->request_package) {
                    $this->emitHook('app.' . str_replace('/', '.', $this->request_package) . '.end');
                }
            } else {
                $this->response = (new ResponseFactory())->createResponse(404);
            }
            $this->emitHook('app.end');
        } catch (\Throwable $th) {
            $this->response = (new ResponseFactory())->createResponse(500);
            $this->response->getBody()->write($th->getMessage() . ' in ' . $th->getFile() . ':' . $th->getLine());
            $this->emitHook('app.exception', $th);
            ob_clean();
        }

        $this->response = $this->response->withHeader('X-Powered-By', 'EBCMS');
        (new ResponseEmitter)->emit($this->response);
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

        $route_info = (function (): Router {
            return $this->container->get(Router::class);
        })()->getDispatcher()->dispatch(
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
                    $request_handler = (function (): RequestHandler {
                        return $this->container->get(RequestHandler::class);
                    })();
                    foreach ($route_info[3] as $middleware) {
                        if (is_string($middleware)) {
                            $request_handler->lazyMiddleware($middleware);
                        } elseif ($middleware instanceof MiddlewareInterface) {
                            $request_handler->middleware($middleware);
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
        $script_name = '/' . implode('/', array_filter(explode('/', $_SERVER['SCRIPT_NAME'])));
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
        $script_name = '/' . implode('/', array_filter(explode('/', $_SERVER['SCRIPT_NAME'])));
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
        ContainerInterface $container,
        array $args = []
    ): array {
        $res = [];
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $args)) {
                $res[] = $args[$name];
                continue;
            }

            $class = $param->getClass();
            if ($class !== null) {
                if ($container->has($class->getName())) {
                    $result = $container->get($class->getName());
                    $class_name = $class->getName();
                    if ($result instanceof $class_name) {
                        $res[] = $result;
                        continue;
                    }
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $res[] = $param->getDefaultValue();
                continue;
            }

            if ($param->isOptional()) {
                continue;
            }

            throw new OutOfBoundsException(sprintf(
                'Unable to resolve a value for parameter (%s $%s) at %s',
                $param->getType(),
                $param->getName(),
                $method->getFileName()
            ));
        }
        return $res;
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
            $args = $this->reflectArguments(new ReflectionMethod(...$callable), $this->container, $default_args);
        } elseif ($callable instanceof Closure) {
            $args = $this->reflectArguments(new ReflectionFunction($callable), $this->container, $default_args);
        } else {
            if (strpos($callable, '::')) {
                $args = $this->reflectArguments(new ReflectionMethod($callable), $this->container, $default_args);
            } else {
                $args = $this->reflectArguments(new ReflectionFunction($callable), $this->container, $default_args);
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
                $installed = json_decode(file_get_contents($vendor_dir . '/composer/installed.json'), true);
                foreach ($installed as $package) {
                    if (
                        $package['type'] == 'ebcms-app'
                    ) {
                        $packages[$package['name']] = [
                            'dir' => $vendor_dir . '/' . $package['name'],
                        ];
                    }
                }
                $loader = new ClassLoader();
                foreach (glob($this->app_path . '/plugin/*/plugin.json') as $value) {
                    $dir = dirname($value);
                    $name = pathinfo(dirname($value), PATHINFO_FILENAME);
                    if (
                        file_exists($this->app_path . '/config/plugin/' . $name . '/install.lock') &&
                        !file_exists($this->app_path . '/config/plugin/' . $name . '/disabled.lock')
                    ) {
                        $packages['plugin/' . $name] = [
                            'dir' => $dir,
                        ];
                        $loader->addPsr4(str_replace(
                            ['-'],
                            '',
                            ucwords('App\\Plugin\\' . $name . '\\', '\\-')
                        ), $dir . '/src/library/');
                    }
                }
                $loader->register();
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
