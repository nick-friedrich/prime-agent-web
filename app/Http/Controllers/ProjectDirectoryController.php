<?php

namespace App\Http\Controllers;

use App\Services\LocalProjectDirectories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProjectDirectoryController extends Controller
{
    public function __construct(private readonly LocalProjectDirectories $directories) {}

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $query = $request->filled('q') ? $request->string('q')->toString() : '';

        return response()->json([
            'repositories' => array_slice($this->directories->search($query), 0, 30),
        ]);
    }

    public function browse(Request $request): JsonResponse
    {
        $request->validate(['path' => ['nullable', 'string', 'max:1000']]);
        $path = $request->filled('path') ? $request->string('path')->toString() : null;

        try {
            return response()->json($this->directories->browse($path));
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
    }
}
