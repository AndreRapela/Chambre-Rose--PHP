<?php

declare(strict_types=1);

$bootstrapCandidates = [
    dirname(__DIR__) . '/bootstrap.php',
    dirname(__DIR__, 2) . '/chambre-rose-api/bootstrap.php',
];
$bootstrap = null;
foreach ($bootstrapCandidates as $candidate) {
    if (is_file($candidate)) {
        $bootstrap = $candidate;
        break;
    }
}
if ($bootstrap === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"message":"Backend bootstrap not found."}';
    exit;
}
require $bootstrap;

use ChambreRose\App;
use ChambreRose\ApiException;
use ChambreRose\Config;
use ChambreRose\Request;
use ChambreRose\Response;

$request = Request::fromGlobals();
$commonHeaders = App::commonHeaders($request);

try {
    (new App())->handle($request)->send();
} catch (ApiException $exception) {
    Response::error(
        $exception->status,
        $exception->getMessage(),
        $request->path,
        $exception->fields
    )->withHeaders($commonHeaders)->send();
} catch (Throwable $exception) {
    error_log(sprintf(
        '[Chambre Rose API] request=%s method=%s path=%s error=%s in %s:%d',
        $request->requestId,
        $request->method,
        $request->path,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    $message = Config::bool('APP_DEBUG', false)
        ? $exception->getMessage()
        : 'Unexpected server error.';

    Response::error(500, $message, $request->path)->withHeaders($commonHeaders)->send();
}
