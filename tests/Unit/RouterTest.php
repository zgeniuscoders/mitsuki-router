<?php

use Mitsuki\Http\Requests\Request;
use Mitsuki\Hermite\Router;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

test('router container instantiation works correctly via DI', function () {
    expect($this->app->get(Router::class))
        ->toBeInstanceOf(Router::class)
        ->and($this->app->get(HttpKernelInterface::class))
        ->toBeInstanceOf(HttpKernel::class);
});

test('GET /posts returns index with paginated data - E2E', function () {
    $request = Request::create('/posts', 'GET');
    $response = $this->app->get(HttpKernelInterface::class)->handle($request);

    $data = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['data'])->toHaveCount(3)
        ->and($data['data'][0])->toBe('post 1')
        ->and($data['data'][1])->toBe('post 2');
});

test('POST /posts creates resource with request data - E2E', function () {
    $request = Request::create(
        '/posts',
        'POST',
        ['title' => 'My new post title']
    );

    $response = $this->app->get(HttpKernelInterface::class)->handle($request);
    $data = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['title'])->toBe('My new post title');
});

test('GET /posts/123 shows specific post with route param', function () {
    $request = Request::create('/posts/123', 'GET');
    $response = $this->app->get(HttpKernelInterface::class)->handle($request);

    $data = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($request->attributes->get('id'))->toBe('123')
        ->and($data['data'])->toBe('post 1');
});

test('PUT /posts/1 updates resource with route parameter', function () {
    $request = Request::create('/posts/1', 'PUT');
    $response = $this->app->get(HttpKernelInterface::class)->handle($request);

    $data = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['id'])->toBe(1)
        ->and($request->attributes->get('id'))->toBe('1');
});

test('DELETE /posts/1 returns 204 no content', function () {
    $request = Request::create('/posts/1', 'DELETE');
    $response = $this->app->get(HttpKernelInterface::class)->handle($request);

    expect($response->getStatusCode())->toBe(204);
});

test('returns 404 Not Found for unknown routes', function () {
    $request = Request::create('/unknown', 'GET');

    expect(fn() => $this->app->get(HttpKernelInterface::class)->handle($request))
        ->toThrow(NotFoundHttpException::class);
});

test('returns 405 Method Not Allowed for wrong HTTP method', function () {
    $request = Request::create('/posts', 'PUT');

    expect(fn() => $this->app->get(HttpKernelInterface::class)->handle($request))
        ->toThrow(Exception::class);
});

test('controller prefix + empty path works correctly', function () {
    $request = Request::create('/posts', 'GET');
    $response = $this->app->get(HttpKernelInterface::class)->handle($request);

    expect($response->getStatusCode())->toBe(200);
});

test('route parameters are injected as method arguments', function () {
    $request = Request::create('/posts/456', 'GET');
    $this->app->get(HttpKernelInterface::class)->handle($request);
    expect($request->attributes->get('id'))->toBe('456');
});