<?php

declare(strict_types=1);

namespace Ebcms;

use SplPriorityQueue;

class Hook
{
    private $app_path = '';
    private $apps = [];
    private $hooks = [];
    private $is_stop = false;

    public function __construct(App $app)
    {
        $this->app_path = $app->getAppPath();
        $this->apps = $app->getPackages();
    }

    public function emit(string $name, &$params = null)
    {
        foreach ($this->getHooks($name) as $value) {
            call_hook($value, $params);
            if ($this->is_stop) {
                $this->is_stop = false;
                break;
            }
        }
    }

    public function on(string $name, $value, int $priority = 50): self
    {
        $this->getHooks($name)->insert($value, $priority);
        return $this;
    }

    public function stop()
    {
        $this->is_stop = true;
    }

    private function getHooks(string $name): SplPriorityQueue
    {
        if (!isset($this->hooks[$name])) {
            $hooks = new SplPriorityQueue;
            foreach (glob($this->app_path . '/hook/' . $name . '/*.php') as $file) {
                preg_match('/^(.*)(#([0-9]+))*$/Ui', pathinfo($file, PATHINFO_FILENAME), $matches);
                $hooks->insert($file, isset($matches[3]) ? $matches[3] : 50);
            }
            foreach ($this->apps as $value) {
                foreach (glob($value['dir'] . '/src/hook/' . $name . '/*.php') as $file) {
                    preg_match('/^(.*)(#([0-9]+))*$/Ui', pathinfo($file, PATHINFO_FILENAME), $matches);
                    $hooks->insert($file, isset($matches[3]) ? $matches[3] : 50);
                }
            }
            $this->hooks[$name] = $hooks;
        }
        return $this->hooks[$name];
    }
}

function call_hook($value,  &$params = null)
{
    if (is_callable($value)) {
        call_user_func_array($value, [&$params]);
    } elseif (is_string($value)) {
        include $value;
    }
}
