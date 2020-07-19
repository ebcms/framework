<?php

declare(strict_types=1);

namespace Ebcms;

use FastRoute\DataGenerator\GroupCountBased as DataGeneratorGroupCountBased;
use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteParser\Std;

class Router
{

    /**
     * @var Std $routeParser
     * @var DataGeneratorGroupCountBased $dataGenerator
     * @var RouteCollector $routeCollector
     */
    private $routeParser;
    private $dataGenerator;
    private $routeCollector;

    public function __construct()
    {
        $this->routeParser = new Std;
        $this->dataGenerator = new DataGeneratorGroupCountBased;
        $this->routeCollector = new RouteCollector($this->routeParser, $this->dataGenerator);
    }

    public function dispatch($httpMethod, $uri)
    {
        return (new GroupCountBased($this->routeCollector->getData()))->dispatch($httpMethod, $uri);
    }

    public function buildUrl(string $name, array $param = [], string $method = 'GET'): ?string
    {
        $build = function (array $routeData, $param) {
            $uri = '';
            foreach ($routeData as $part) {
                if (is_array($part)) {
                    if (isset($param[$part[0]]) && preg_match('#^' . $part[1] . '$#', (string) $param[$part[0]])) {
                        $uri .= $param[$part[0]];
                        unset($param[$part[0]]);
                        continue;
                    } else {
                        return false;
                    }
                } else {
                    $uri .= $part;
                }
            }
            if ($param) {
                return $uri . '?' . http_build_query($param);
            }
            return $uri;
        };

        foreach ($this->getCollector()->getBuildData($name, $method) as $routeData) {
            if (false !== $uri = $build($routeData, $param)) {
                return $uri;
            }
        }

        static $web_root;
        if (is_null($web_root)) {
            $web_root = (function (): string {
                $script_name = '/' . implode('/', array_filter(explode('/', $_SERVER['SCRIPT_NAME'])));
                $request_uri = parse_url('/' . implode('/', array_filter(explode('/', $_SERVER['REQUEST_URI']))), PHP_URL_PATH);
                if (strpos($request_uri, $script_name) === 0) {
                    return $script_name;
                } else {
                    return strlen(dirname($script_name)) > 1 ? dirname($script_name) : '';
                }
            })();
        }
        return $web_root . $name . ($param ? '?' . http_build_query($param) : '');
    }

    public function getCollector(): RouteCollector
    {
        return $this->routeCollector;
    }
}
