<?php

declare(strict_types=1);

namespace Ebcms;

use InvalidArgumentException;

class Config
{
    private $configs = [];
    private $packages;
    private $app_path;

    public function __construct(App $app)
    {
        $this->app_path = $app->getAppPath();
        $this->packages = $packages = $app->getPackages();
        foreach (array_keys($packages) as $package_name) {
            $this->configs[$package_name] = [];
        }
    }

    public function get(string $key = '', $default = null)
    {
        list($path, $package_name) = explode('@', $key);
        $package_name = str_replace('.', '/', $package_name);
        if (!$path) {
            throw new InvalidArgumentException('Invalid Argument Exception');
        }
        if (!array_key_exists($package_name, $this->configs)) {
            throw new InvalidArgumentException('App [' . $package_name . '] unavailable!');
        }

        $paths = array_filter(explode('.', $path));
        static $discoverd = [];
        if (!isset($discoverd[$paths[0] . '@' . $package_name])) {
            $discoverd[$paths[0] . '@' . $package_name] = true;
            $this->discover($package_name, $paths[0]);
        }

        return $this->getValue($this->configs[$package_name], $paths, $default);
    }

    public function set(string $key, $value = null): self
    {
        list($path, $package_name) = explode('@', $key);
        $package_name = str_replace('.', '/', $package_name);
        if (!$path) {
            throw new InvalidArgumentException('Invalid Argument Exception');
        }
        if (!array_key_exists($package_name, $this->configs)) {
            throw new InvalidArgumentException('App [' . $package_name . '] unavailable!');
        }

        $paths = array_filter(explode('.', $path));
        $this->setValue($this->configs[$package_name], $paths, $value);
        return $this;
    }

    private function discover(string $package_name, string $key)
    {
        if (!array_key_exists($package_name, $this->configs)) {
            throw new InvalidArgumentException('App [' . $package_name . '] unavailable!');
        }
        $args = [];
        $config_file = $this->packages[$package_name]['dir'] . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $key . '.php';
        if (file_exists($config_file)) {
            $args[] = $this->requireFile($config_file);
        }
        $config_file = $this->app_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $package_name) . DIRECTORY_SEPARATOR . $key . '.php';
        if (file_exists($config_file)) {
            $args[] = $this->requireFile($config_file);
        }
        if (isset($this->configs[$package_name][$key])) {
            $args[] = $this->configs[$package_name][$key];
        }
        $this->configs[$package_name][$key] = $args ? $this->array_merge_deep(...$args) : null;
    }

    private static function array_merge_deep(...$args)
    {
        $res = array_pop($args);
        if (!is_array($res)) {
            return $res;
        }
        while ($arg = array_pop($args)) {
            if (is_array($arg)) {
                $keys = array_unique(array_keys(array_merge($arg, $res)));
                foreach ($keys as $key) {
                    if (isset($arg[$key]) && isset($res[$key])) {
                        $res[$key] = self::array_merge_deep($arg[$key], $res[$key]);
                    } elseif (isset($arg[$key])) {
                        $res[$key] = $arg[$key];
                    }
                }
            }
        }
        return $res;
    }

    private function getValue($data, $path, $default)
    {
        $key = array_shift($path);
        if (!$path) {
            return isset($data[$key]) ? $data[$key] : $default;
        } else {
            if (isset($data[$key])) {
                return $this->getValue($data[$key], $path, $default);
            } else {
                return $default;
            }
        }
    }

    private function setValue(&$data, $path, $value)
    {
        $key = array_shift($path);
        if ($path) {
            if (!isset($data[$key])) {
                $data[$key] = null;
            }
            $this->setValue($data[$key], $path, $value);
        } else {
            $data[$key] = $value;
        }
    }

    private function requireFile(string $file)
    {
        static $loader;
        if (!$loader) {
            $loader = new class()
            {
                public function load(string $file)
                {
                    return require $file;
                }
            };
        }
        return $loader->load($file);
    }
}
