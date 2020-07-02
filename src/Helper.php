<?php

declare(strict_types=1);

namespace Ebcms;

use Psr\Http\Message\ServerRequestInterface;

function get_container(): Container
{
    return App::getInstance()->execute(function (Container $container): Container {
        return $container;
    });
}

function get_server_request(): ServerRequestInterface
{
    return get_container()->get(ServerRequestInterface::class);
}

function get_request_handler(): RequestHandler
{
    return get_container()->get(RequestHandler::class);
}

function get_router(): Router
{
    return get_container()->get(Router::class);
}

function get_config(): Config
{
    return get_container()->get(Config::class);
}
