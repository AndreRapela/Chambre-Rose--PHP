<?php

declare(strict_types=1);

use ChambreRose\ApiException;
use ChambreRose\App;
use ChambreRose\Config;
use ChambreRose\Request;
use ChambreRose\Response;

require dirname(__DIR__) . '/bootstrap.php';

$request = Request::fromGlobals();

try {
    (new App())->handle($request)->send();
} catch (ApiException $exception) {
    Response::error($exception->status, $exception->getMessage(), $request->path, $exception->fields)
        ->withHeaders(App::commonHeaders($request))
        ->send();
} catch (Throwable $exception) {
    error_log('[Chambre Rose API][' . $request->requestId . '] ' . $exception);
    $message = Config::bool('APP_DEBUG', false) ? $exception->getMessage() : 'An unexpected error occurred.';
    Response::error(500, $message, $request->path)
        ->withHeaders(App::commonHeaders($request))
        ->send();
}

