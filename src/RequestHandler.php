<?php declare (strict_types = 1);

namespace Ebcms;

use InvalidArgumentException;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RequestHandler implements RequestHandlerInterface
{
    /**
     * @var array
     */
    protected $middleware = [];

    /**
     * @var ContainerInterface
     */
    protected $container = null;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function middleware(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    public function middlewares(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->middleware($middleware);
        }

        return $this;
    }

    public function prependMiddleware(MiddlewareInterface $middleware): self
    {
        array_unshift($this->middleware, $middleware);

        return $this;
    }

    public function lazyMiddleware(string $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    public function lazyMiddlewares(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->lazyMiddleware($middleware);
        }

        return $this;
    }

    public function lazyPrependMiddleware(string $middleware): self
    {
        array_unshift($this->middleware, $middleware);

        return $this;
    }

    public function shiftMiddleware(): MiddlewareInterface
    {
        $middleware = array_shift($this->middleware);

        if ($middleware === null) {
            throw new OutOfBoundsException('Reached end of middleware stack. Does your return a response?');
        }

        $container = $this->container;

        if ($container === null && is_string($middleware) && class_exists($middleware)) {
            $middleware = new $middleware;
        }

        if ($container !== null && is_string($middleware) && $container->has($middleware)) {
            $middleware = $container->get($middleware);
        }

        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        throw new InvalidArgumentException(sprintf('Could not resolve middleware class: %s', $middleware));
    }

    public function getMiddlewareStack(): iterable
    {
        yield from $this->middleware;
    }

    public function execute(callable $callback, ServerRequestInterface $server_request): ResponseInterface
    {
        $this->middleware(new class($callback) implements MiddlewareInterface
        {
            /**
             * @var callable $callback
             */
            protected $callback;

            public function __construct(callable $callback)
            {
                $this->callback = $callback;
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $requestHandler
            ): ResponseInterface {
                return ($this->callback)($request);
            }
        });

        return $this->handle($server_request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = $this->shiftMiddleware();
        return $middleware->process($request, $this);
    }
}
