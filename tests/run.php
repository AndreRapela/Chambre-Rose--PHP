<?php

declare(strict_types=1);

putenv('JWT_SECRET=test-secret-with-more-than-thirty-two-bytes-123456');
putenv('JWT_EXPIRATION_MINUTES=5');

require dirname(__DIR__) . '/bootstrap.php';

use ChambreRose\ApiException;
use ChambreRose\App;
use ChambreRose\Jwt;
use ChambreRose\MultipartParser;
use ChambreRose\Request;
use ChambreRose\Response;
use ChambreRose\UploadedFile;
use ChambreRose\Validator;

$tests = 0;

$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$jwt = new Jwt();
$token = $jwt->generate('admin@example.com', 'ADMIN');
$claims = $jwt->verify($token);
$assert($claims['sub'] === 'admin@example.com', 'JWT must preserve the subject.');
$assert($claims['role'] === 'ADMIN', 'JWT must preserve the role.');

$tampered = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');

try {
    $jwt->verify($tampered);
    $assert(false, 'A tampered JWT must fail.');
} catch (ApiException $exception) {
    $assert($exception->status === 401, 'A tampered JWT must return 401.');
}

$login = Validator::login(['email' => ' Test@Example.com ', 'password' => '123456']);
$assert($login['email'] === 'Test@Example.com', 'Login validator must trim email.');
$assert(Validator::accountType([]) === 'VISITOR', 'Visitor must be the default account type.');
$assert(Validator::accountType(['accountType' => 'user']) === 'VISITOR', 'Legacy USER must map to VISITOR.');
$assert(Validator::accountType(['accountType' => 'escort']) === 'ESCORT', 'Escort account type must be supported.');
$assert(Validator::locale(['locale' => 'pt-BR']) === 'pt', 'Locales must be normalized.');

try {
    Validator::accountType(['accountType' => 'ROOT']);
    $assert(false, 'Unknown account types must fail.');
} catch (ApiException $exception) {
    $assert($exception->status === 400 && isset($exception->fields['accountType']), 'Account type errors must be explicit.');
}

try {
    Validator::register([]);
    $assert(false, 'Missing registration fields must fail.');
} catch (ApiException $exception) {
    $assert($exception->status === 400 && isset($exception->fields['email']), 'Registration must report field errors.');
}

$visitor = Validator::register([
    'firstName' => 'Ana', 'lastName' => 'Silva', 'email' => 'ana@example.com',
    'phone' => '12345678', 'password' => 'Strong9!pass',
]);
$assert($visitor['address'] === '' && $visitor['city'] === '', 'Visitor address fields must remain optional.');

$boundary = 'test-boundary';
$body = "--{$boundary}\r\n"
    . "Content-Disposition: form-data; name=\"name\"\r\n\r\nProduct\r\n"
    . "--{$boundary}\r\n"
    . "Content-Disposition: form-data; name=\"mainImage\"; filename=\"image.png\"\r\n"
    . "Content-Type: image/png\r\n\r\nPNGDATA\r\n"
    . "--{$boundary}--\r\n";
$multipart = MultipartParser::parse($body, "multipart/form-data; boundary={$boundary}");
$assert($multipart['fields']['name'] === 'Product', 'Multipart parser must read fields.');
$assert($multipart['files']['mainImage']->bytes() === 'PNGDATA', 'Multipart parser must preserve file bytes.');

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    true
);
$assert(is_string($png), 'PNG fixture must be valid base64.');
$image = new UploadedFile('pixel.png', 'text/plain', strlen($png), null, $png);
$assert($image->detectedContentType() === 'image/png', 'Uploaded image type must come from its bytes.');
$assert($image->actualSize() === strlen($png), 'Uploaded image size must come from its bytes.');

$failedUpload = new UploadedFile('large.png', 'image/png', 0, null, null, UPLOAD_ERR_INI_SIZE);
$assert(!$failedUpload->isEmpty(), 'A rejected upload must not be mistaken for an empty file.');

try {
    $failedUpload->bytes();
    $assert(false, 'A server-rejected upload must fail.');
} catch (ApiException $exception) {
    $assert($exception->status === 413, 'An oversized server-rejected upload must return 413.');
}

$request = new Request('GET', '/api/test', [], [], '0123456789abcdef');
$assert($request->requestId === '0123456789abcdef', 'Request IDs must remain stable during a request.');
$headers = App::commonHeaders($request);
$assert(($headers['X-Request-ID'] ?? null) === $request->requestId, 'Responses must expose the request ID.');

$error = Response::error(400, 'Invalid request data.', '/api/test');
$decoded = json_decode($error->body, true, 16, JSON_THROW_ON_ERROR);
$assert($decoded['status'] === 400 && $decoded['fields'] === [], 'Error response must match the API contract.');
$assert(($error->headers['Cache-Control'] ?? null) === 'no-store', 'Error responses must not be cached.');

$oversized = new Request('POST', '/api/auth/register', ['content-length' => (string) (31 * 1024 * 1024)], []);

try {
    (new App())->handle($oversized);
    $assert(false, 'Oversized requests must fail before database boot.');
} catch (ApiException $exception) {
    $assert($exception->status === 413, 'Oversized requests must return 413.');
}

fwrite(STDOUT, "OK - {$tests} assertions\n");
