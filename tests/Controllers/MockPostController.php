<?php

namespace Tests\Controllers;

use Mitsuki\Attributes\Controller;
use Mitsuki\Attributes\Route;
use Mitsuki\Controller\BaseController;
use Mitsuki\Http\Requests\Request;
use Mitsuki\Http\Responses\JsonResponse;

#[Controller('posts')]
class MockPostController extends BaseController
{

    #[Route('posts.index', '', 'GET')]
    public function index(): JsonResponse
    {
        return $this->json(['data' => ['post 1', 'post 2', 'post 3']]);
    }

    #[Route('posts.store', '', 'POST')]
    public function store(Request $request): JsonResponse
    {
        return $this->json([
            'title' => $request->request->get('title'),
        ], status: 200);
    }

    #[Route('posts.show', '{id}', 'GET')]
    public function show(int $id): JsonResponse
    {
        return $this->json(['data' => 'post 1']);
    }

    #[Route('posts.update', '{id}', 'PUT')]
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->json([
            'id' => $id
        ], status: 200);
    }

    #[Route('posts.destroy', '{id}', 'DELETE')]
    public function destroy(int $id): JsonResponse
    {
        return $this->json([], status: 204);
    }

}