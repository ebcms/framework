<?php

declare(strict_types=1);

namespace Ebcms;

use Closure;
use Composer\Autoload\ClassLoader;
use Ebcms\Container;
use Ebcms\ResponseFactory;
use Ebcms\ServerRequestFactory;
use FastRoute\Dispatcher;
use InvalidArgumentException;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use SplPriorityQueue;

use function Composer\Autoload\includeFile;

/**
 * @property Container $container
 * @property string $app_path
 * @property string $request_package
 * @property string $request_target_class
 * @property ?ResponseInterface $app_response
 */
class App
{
    private $container;
    private $app_path;
    private $request_package;
    private $request_target_class;
    private $app_response;

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

        if (file_exists($this->app_path . '/bootstrap.php')) {
            includeFile($this->app_path . '/bootstrap.php');
        }

        $this->loadHook('app.start');

        $request_handler = (function (): RequestHandler {
            return $this->container->get(RequestHandler::class);
        })();
        if ($callable = $this->reflectRequestTarget()) {
            $this->app_response = $request_handler->execute(function () use ($callable): ResponseInterface {
                return $this->toResponse($this->execute($callable));
            }, $this->container->get(ServerRequestInterface::class));
        } else {
            $this->app_response = $request_handler->execute(function (): ResponseInterface {
                return (new ResponseFactory())->createResponse(404);
            }, $this->container->get(ServerRequestInterface::class));
        }

        $this->app_response = $this->app_response->withHeader('X-Powered-By', 'EBCMS');
        (new ResponseEmitter)->emit($this->app_response);

        if ($this->request_package) {
            $this->loadHook('app.end@' . str_replace('/', '.', $this->request_package));
        }
        $this->loadHook('app.end');
    }

    private function reflectRequestTarget(): ?callable
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

        $url_path = parse_url('/' . implode('/', array_filter(explode('/', $_SERVER['REQUEST_URI']))), PHP_URL_PATH);

        $routeInfo = (function (): Router {
            return $this->container->get(Router::class);
        })()->dispatch(
            $_SERVER['REQUEST_METHOD'],
            $schema . '://' . $_SERVER['HTTP_HOST'] . $url_path
        );

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                $request_target_class = $this->reflectRequestTargetClassFromPath($this->resolveRelativeUriPath());
                if (!$request_target_class) {
                    return null;
                }
                break;
            case Dispatcher::METHOD_NOT_ALLOWED:
                return null;
                break;
            case Dispatcher::FOUND:
                $_GET = array_merge((array) $routeInfo[2], $_GET);
                $request_target_class = $routeInfo[1];
                if (is_callable($request_target_class)) {
                    return $request_target_class;
                }
                if (!$request_target_class || !is_string($request_target_class)) {
                    return null;
                }
                break;
        }

        if (!$request_package = $this->reflectPackageFromTargetClass($request_target_class)) {
            return null;
        }
        $this->request_package = $request_package;
        $this->request_target_class = $request_target_class;
        $this->loadHook('app.start@' . str_replace('/', '.', $this->request_package));

        try {
            $request_target = $this->container->get($request_target_class);
            return [$request_target, 'handle'];
        } catch (ContainerNotFoundException $th) {
            return null;
        }
    }

    private function reflectPackageFromTargetClass(string $request_target_class): ?string
    {
        $tmp_arr = array_values(array_filter(explode('\\', strtolower(preg_replace_callback(
            '/([\w])([A-Z]{1})/',
            function ($match) {
                return $match[1] . '-' . lcfirst($match[2]);
            },
            $request_target_class
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

    private function reflectRequestTargetClassFromPath(string $path): ?string
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
            ucwords('App\\' . $vendor_name . '\\' . $package_name . '\\Http\\' . implode('\\', $path_arr), '\\-')
        );
    }

    private function resolveRelativeUriPath(): string
    {
        $web_root = (function (): string {
            $script_name = '/' . implode('/', array_filter(explode('/', $_SERVER['SCRIPT_NAME'])));
            $request_uri = parse_url('/' . implode('/', array_filter(explode('/', $_SERVER['REQUEST_URI']))), PHP_URL_PATH);
            if (strpos($request_uri, $script_name) === 0) {
                return $script_name;
            } else {
                return strlen(dirname($script_name)) > 1 ? dirname($script_name) : '';
            }
        })();
        $relative_uri_path = substr(
            parse_url(
                '/' . implode('/', array_filter(explode('/', $_SERVER['REQUEST_URI']))),
                PHP_URL_PATH
            ),
            strlen($web_root)
        );
        $dirname = pathinfo($relative_uri_path, PATHINFO_DIRNAME);
        return (strlen($dirname) > 1 ? $dirname : '') . '/' . pathinfo($relative_uri_path, PATHINFO_FILENAME);
    }

    private function loadHook(string $tag)
    {
        static $hooks = [];
        if (!$hooks) {
            foreach ($this->getPackages() as $value) {
                foreach (glob($value['dir'] . '/src/hook/*/*.php') as $file) {
                    $hook_name = pathinfo(dirname($file), PATHINFO_BASENAME);
                    preg_match('/^(.*)(#([0-9]+))*$/Ui', pathinfo($file, PATHINFO_FILENAME), $matches);
                    if (!array_key_exists($hook_name, $hooks)) {
                        $hooks[$hook_name] = new SplPriorityQueue;
                    }
                    $hooks[$hook_name]->insert($file, isset($matches[3]) ? $matches[3] : 50);
                }
            }
            foreach (glob($this->app_path . '/hook/*/*.php') as $file) {
                $hook_name = pathinfo(dirname($file), PATHINFO_BASENAME);
                preg_match('/^(.*)(#([0-9]+))*$/Ui', pathinfo($file, PATHINFO_FILENAME), $matches);
                if (!array_key_exists($hook_name, $hooks)) {
                    $hooks[$hook_name] = new SplPriorityQueue;
                }
                $hooks[$hook_name]->insert($file, isset($matches[3]) ? $matches[3] : 50);
            }
        }
        if (array_key_exists($tag, $hooks)) {
            foreach ($hooks[$tag] as $file) {
                includeFile($file);
            }
        }
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
            $cache = (function (): ?CacheInterface {
                if ($this->container->has(CacheInterface::class)) {
                    return $this->container->get(CacheInterface::class);
                }
                return null;
            })();

            if (!$cache || !$packages = $cache->get('packages_cache')) {
                $vendor_dir = dirname(dirname((new ReflectionClass(ClassLoader::class))->getFileName()));
                $packages = [];
                $installed = json_decode(file_get_contents($vendor_dir . '/composer/installed.json'), true);
                foreach ($installed as $package) {
                    if ($package['type'] == 'ebcms-app') {
                        $packages[$package['name']] = [
                            'dir' => $vendor_dir . '/' . $package['name'],
                        ];
                    }
                }
                if ($cache) {
                    $cache->set('packages_cache', $packages);
                }
            }
        }
        return $packages;
    }

    public function getAppPath(): string
    {
        return $this->app_path;
    }

    public function getRequestPackage(): string
    {
        return $this->request_package;
    }

    public function getRequestTargetClass(): string
    {
        return $this->request_target_class;
    }

    public function getAppResponse(): ?ResponseInterface
    {
        return $this->app_response;
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
