<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway (like most PaaS platforms) terminates TLS at its edge
        // and forwards plain HTTP to the container -- without this,
        // Laravel sees every request as http:// and generates asset()/
        // @vite() URLs with the wrong scheme, which browsers then block
        // as mixed content on a page actually served over https://. '*'
        // trusts whatever's forwarding the request, which is fine here
        // since Railway's proxy is the only thing that can reach this
        // container -- it has no public IP of its own.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Sanctum's SPA mode: the React app and the API share a domain, so
        // a normal session cookie is the API auth instead of a bearer
        // token the browser would have to store. EnsureFrontendRequestsAreStateful
        // only *checks* the request is same-origin -- it still needs the
        // web session stack (cookies, session, CSRF) actually running on
        // the api group, which Laravel's default api group doesn't include.
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
