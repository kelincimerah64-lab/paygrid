<?php

namespace App\Services\Jira;

use Illuminate\Support\Facades\Http;

class JiraClient
{
    public function createIssue(string $summary, string $description): string
    {
        $response = $this->request('POST', '/rest/api/3/issue', [
            'fields' => [
                'project' => ['key' => config('paygrid.jira_sync.project_key')],
                'issuetype' => ['name' => config('paygrid.jira_sync.issue_type')],
                'summary' => $summary,
                'description' => $this->toAdf($description),
            ],
        ]);

        return (string) $response->json('key');
    }

    /**
     * Jira has no "set status directly" call - you walk the workflow one
     * transition at a time. $targetStatusName must be reachable from the
     * issue's current status in a single hop (true for this project's flat
     * To Do -> In Progress -> Done workflow, not true in general).
     */
    public function transitionIssueTo(string $issueKey, string $targetStatusName): bool
    {
        $transitions = $this->request('GET', "/rest/api/3/issue/{$issueKey}/transitions")->json('transitions') ?? [];

        $match = collect($transitions)->first(fn ($t) => ($t['to']['name'] ?? null) === $targetStatusName);
        if (! $match) {
            return false;
        }

        $this->request('POST', "/rest/api/3/issue/{$issueKey}/transitions", [
            'transition' => ['id' => $match['id']],
        ]);

        return true;
    }

    private function toAdf(string $text): array
    {
        $paragraphs = array_values(array_filter(explode("\n", $text), fn ($line) => $line !== ''));

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => array_map(fn ($line) => [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $line]],
            ], $paragraphs ?: ['']),
        ];
    }

    private function request(string $method, string $path, array $body = [])
    {
        $request = Http::withBasicAuth(
            (string) config('paygrid.jira_sync.email'),
            (string) config('paygrid.jira_sync.api_token'),
        )->acceptJson()->timeout(15);

        $url = config('paygrid.jira_sync.base_url').$path;

        return match ($method) {
            'GET' => $request->get($url)->throw(),
            'POST' => $request->post($url, $body)->throw(),
            default => throw new \InvalidArgumentException("Unsupported method {$method}"),
        };
    }
}
