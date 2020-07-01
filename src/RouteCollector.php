<?php declare (strict_types = 1);

namespace Ebcms;

use FastRoute\RouteCollector as FastRouteRouteCollector;

class RouteCollector extends FastRouteRouteCollector
{

    private $builds = [];

    public function setPrefix(string $prefix = ''): self
    {
        $this->currentGroupPrefix = $prefix;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function addRoute($httpMethod, $route, $handler, ?string $name = null)
    {
        $route = $this->currentGroupPrefix . $route;
        $routeDatas = $this->routeParser->parse($route);
        foreach ((array) $httpMethod as $method) {
            foreach ($routeDatas as $routeData) {
                $this->dataGenerator->addRoute($method, $routeData, $handler);
            }
        }
        if ($name) {
            foreach ((array) $httpMethod as $method) {
                while ($routeData = array_pop($routeDatas)) {
                    $this->builds[$name][$method][] = $routeData;
                }
            }
        }
    }

    public function getBuildData($name, $method): iterable
    {
        if (isset($this->builds[$name][$method])) {
            foreach ($this->builds[$name][$method] as $key => $value) {
                yield $key => $value;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function get($route, $handler, ?string $name = null)
    {
        $this->addRoute('GET', $route, $handler, $name);
    }

    /**
     * @inheritDoc
     */
    public function post($route, $handler, ?string $name = null)
    {
        $this->addRoute('POST', $route, $handler, $name);
    }

    /**
     * @inheritDoc
     */
    public function put($route, $handler, ?string $name = null)
    {
        $this->addRoute('PUT', $route, $handler, $name);
    }

    /**
     * @inheritDoc
     */
    public function delete($route, $handler, ?string $name = null)
    {
        $this->addRoute('DELETE', $route, $handler, $name);
    }

    /**
     * @inheritDoc
     */
    public function patch($route, $handler, ?string $name = null)
    {
        $this->addRoute('PATCH', $route, $handler, $name);
    }

    /**
     * @inheritDoc
     */
    public function head($route, $handler, ?string $name = null)
    {
        $this->addRoute('HEAD', $route, $handler, $name);
    }
}
