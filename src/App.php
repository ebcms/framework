<?php

declare(strict_types=1);

namespace Ebcms;

use Closure;
use Composer\Autoload\ClassLoader;
use Ebcms\Container;
use Ebcms\EventDispatcher;
use Ebcms\ListenerProvider;
use Ebcms\ResponseFactory;
use Ebcms\ServerRequestFactory;
use Ebcms\SimpleCacheNullAdapter;
use FastRoute\Dispatcher;
use InvalidArgumentException;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use SplPriorityQueue;

use function Composer\Autoload\includeFile;

/**
 * @property Container $container
 */
class App
{
    private $container;

    private $app_path;
    private $web_root;
    private $packages = [];
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

        $this->web_root = (function (): string {
            $script_name = '/' . implode('/', array_filter(explode('/', $_SERVER['SCRIPT_NAME'])));
            $request_uri = parse_url('/' . implode('/', array_filter(explode('/', $_SERVER['REQUEST_URI']))), PHP_URL_PATH);
            if (strpos($request_uri, $script_name) === 0) {
                return $script_name;
            } else {
                return strlen(dirname($script_name)) > 1 ? dirname($script_name) : '';
            }
        })();

        if (file_exists($this->app_path . '/loader.php')) {
            includeFile($this->app_path . '/loader.php');
        } else {
            $this->load($this->container);
        }

        $this->packages = $this->loadPackages($this->container, $this->app_path);
        $this->registerPackages($this->packages);

        $this->loadHook('app.init', $this->packages);

        if ($callable = $this->reflectRequestTarget()) {
            $this->loadHook('app.start', $this->packages);
            if ($this->request_package) {
                $this->loadHook('app.start@' . str_replace('/', '.', $this->request_package), $this->packages);
            }
            $this->app_response = $this->getRequestHandler()->execute(function () use ($callable): ResponseInterface {
                return $this->toResponse($this->execute($callable));
            }, $this->getServerRequest());
        } else {
            $this->app_response = $this->getRequestHandler()->execute(function (): ResponseInterface {
                return $this->getResponseFactory()->createResponse(404);
            }, $this->getServerRequest());
        }

        $this->app_response = $this->app_response->withHeader('X-Powered-By', 'EBCMS');
        (new ResponseEmitter)->emit($this->app_response);

        if ($this->request_package) {
            $this->loadHook('app.end@' . str_replace('/', '.', $this->request_package), $this->packages);
        }
        $this->loadHook('app.end', $this->packages);
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

        $routeInfo = $this->getRouter()->dispatch(
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
        if (!array_key_exists($package, $this->packages)) {
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
        $relative_uri_path = substr(
            parse_url(
                '/' . implode('/', array_filter(explode('/', $_SERVER['REQUEST_URI']))),
                PHP_URL_PATH
            ),
            strlen($this->web_root)
        );
        $dirname = pathinfo($relative_uri_path, PATHINFO_DIRNAME);
        return (strlen($dirname) > 1 ? $dirname : '') . '/' . pathinfo($relative_uri_path, PATHINFO_FILENAME);
    }

    private function loadHook(string $tag, array $packages)
    {
        static $hooks = [];
        if (!$hooks) {
            foreach ($packages as $value) {
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

    private function loadPackages(ContainerInterface $container, string $app_path): array
    {
        $cache = (function () use ($container): ?CacheInterface {
            if ($container->has(CacheInterface::class)) {
                return $container->get(CacheInterface::class);
            }
            return null;
        })();

        if (!$cache || !$packages = $cache->get('_packages')) {
            $vendor_dir = dirname(dirname((new ReflectionClass(ClassLoader::class))->getFileName()));
            $packages = [];
            $installed = json_decode(file_get_contents($vendor_dir . '/composer/installed.json'), true);
            foreach ($installed as $package) {
                if ($package['type'] == 'ebcms-app') {
                    $packages[$package['name']] = [
                        'dir' => $vendor_dir . '/' . $package['name'],
                        'vendor' => true,
                    ];
                }
            }

            foreach (glob($app_path . '/app/*/*/composer.json') as $file) {
                $tmp = explode('/', $file);
                array_pop($tmp);
                $name = array_pop($tmp);
                $vendor = array_pop($tmp);
                $packages[$vendor . '/' . $name] = [
                    'dir' => $app_path . '/app/' . $vendor . '/' . $name,
                    'vendor' => false,
                ];
            }

            if ($cache) {
                $cache->set('_packages', $packages);
            }
        }
        return $packages;
    }
    private function registerPackages(array $packages)
    {
        $loader = new ClassLoader;
        foreach ($packages as $name => $value) {
            if (!$value['vendor']) {
                $loader->addPsr4(
                    str_replace(['-'], '', ucwords('App\\' . str_replace(['/'], ['\\'], $name) . '\\', '\\-')),
                    $value['dir'] . '/src/library'
                );
            }
        }
        $loader->register();
    }
    private function toResponse($result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        $response = $this->getResponseFactory()->createResponse(200);
        if (is_scalar($result)) {
            $response->getBody()->write($result);
        } else {
            $response->getBody()->write(json_encode($result));
            $response = $response->withHeader('Content-Type', 'application/json');
        }
        return $response;
    }

    private function load(Container $container)
    {
        $container->set(ServerRequestInterface::class, function () use ($container): ServerRequestInterface {
            return $container->get(ServerRequestFactory::class)->createServerRequestFromGlobals();
        });
        $container->set(ResponseFactoryInterface::class, function () use ($container): ResponseFactoryInterface {
            return $container->get(ResponseFactory::class);
        });
        $container->set(LoggerInterface::class, function () use ($container): LoggerInterface {
            return $container->get(NullLogger::class);
        });
        $container->set(CacheInterface::class, function () use ($container): CacheInterface {
            return $container->get(SimpleCacheNullAdapter::class);
        });
        $container->set(ListenerProviderInterface::class, function () use ($container): ListenerProviderInterface {
            return $container->get(ListenerProvider::class);
        });
        $container->set(EventDispatcherInterface::class, function () use ($container): EventDispatcherInterface {
            return $container->get(EventDispatcher::class);
        });
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
    public function getContainer(): Container
    {
        return $this->container;
    }
    public function getRouter(): Router
    {
        return $this->container->get(Router::class);
    }
    public function getConfig(): Config
    {
        return $this->container->get(Config::class);
    }
    public function getLogger(): LoggerInterface
    {
        return $this->container->get(LoggerInterface::class);
    }
    public function getCache(): CacheInterface
    {
        return $this->container->get(CacheInterface::class);
    }
    public function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->container->get(ResponseFactoryInterface::class);
    }
    public function getServerRequest(): ServerRequestInterface
    {
        return $this->container->get(ServerRequestInterface::class);
    }
    public function getRequestHandler(): RequestHandler
    {
        return $this->container->get(RequestHandler::class);
    }
    public function getAppPath(): string
    {
        return $this->app_path;
    }
    public function getWebRoot(): string
    {
        return $this->web_root;
    }
    public function getPackages(): array
    {
        return $this->packages;
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
    public function buildUrl($name, array $param = [], string $method = 'GET'): string
    {
        if (!$url = $this->getRouter()->buildUrl($name, $param, $method)) {
            $url = $this->web_root . $name . ($param ? '?' . http_build_query($param) : '');
        }
        return $url;
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
