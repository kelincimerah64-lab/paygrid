<?php

namespace App\Http\Controllers;

use App\Services\MaAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaAssistantController extends Controller
{
    public function ask(Request $request, MaAssistantService $assistant): JsonResponse
    {
        if (is_string($request->input('history'))) {
            $decoded = json_decode($request->input('history'), true);
            $request->merge(['history' => is_array($decoded) ? $decoded : []]);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:20'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);

        $image = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $image = [
                'mediaType' => $file->getMimeType(),
                'data' => base64_encode(file_get_contents($file->getRealPath())),
            ];
        }

        $reply = $assistant->ask(
            $request->user(),
            $data['message'],
            $this->sanitizeHistory($data['history'] ?? []),
            $image,
        );

        return response()->json(['reply' => $reply]);
    }

    /**
     * The client resends its own running history each request (this endpoint
     * is stateless). Re-validate its shape server-side rather than trusting
     * it - only role/content keys with role in {user,assistant} pass through.
     */
    private function sanitizeHistory(array $history): array
    {
        $clean = [];
        foreach ($history as $turn) {
            if (! is_array($turn) || ! in_array($turn['role'] ?? null, ['user', 'assistant'], true) || ! is_string($turn['content'] ?? null) || $turn['content'] === '') {
                continue;
            }
            $clean[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }

        return array_slice($clean, -20);
    }
}
