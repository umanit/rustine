<?php

declare(strict_types=1);

namespace Rustine\GitLab;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * {@see GitLabClient} over the REST API, with a personal/group access token.
 */
final readonly class HttpGitLabClient implements GitLabClient
{
    private const PER_PAGE = 100;
    private const MAX_PAGES = 20;

    private HttpClientInterface $http;
    private string $projectPath;

    /**
     * @param string $apiUrl    API root, e.g. `https://gitlab.example.com/api/v4` (`CI_API_V4_URL`)
     * @param string $projectId numeric id (`CI_PROJECT_ID`) or full path of the project
     */
    public function __construct(
        HttpClientInterface $http,
        string $apiUrl,
        string $projectId,
        #[\SensitiveParameter]
        string $token,
    ) {
        $this->http = $http->withOptions([
            'base_uri' => rtrim($apiUrl, '/') . '/',
            'headers' => ['PRIVATE-TOKEN' => $token, 'Accept' => 'application/json'],
        ]);
        $this->projectPath = 'projects/' . rawurlencode($projectId);
    }

    public function currentUser(): GitLabUser
    {
        $data = $this->request('GET', 'user');

        return new GitLabUser(
            $this->int($data, 'id'),
            $this->string($data, 'username'),
            $this->string($data, 'name'),
            isset($data['commit_email']) && \is_string($data['commit_email']) && '' !== $data['commit_email'] ? $data['commit_email'] : null,
        );
    }

    public function findOpenMergeRequest(string $sourceBranch): ?MergeRequest
    {
        $items = $this->request('GET', $this->projectPath . '/merge_requests', ['query' => ['state' => 'opened', 'source_branch' => $sourceBranch]]);

        foreach ($items as $item) {
            if (\is_array($item)) {
                $mr = $this->mergeRequest($item);
                if ($mr->sourceBranch === $sourceBranch) {
                    return $mr;
                }
            }
        }

        return null;
    }

    public function listOpenMergeRequests(array $labels): array
    {
        $merged = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $query = ['state' => 'opened', 'per_page' => self::PER_PAGE, 'page' => $page];
            if ([] !== $labels) {
                $query['labels'] = implode(',', $labels);
            }
            $items = $this->request('GET', $this->projectPath . '/merge_requests', ['query' => $query]);

            foreach ($items as $item) {
                if (\is_array($item)) {
                    $merged[] = $this->mergeRequest($item);
                }
            }
            if (\count($items) < self::PER_PAGE) {
                break;
            }
        }

        return $merged;
    }

    public function createMergeRequest(NewMergeRequest $request): MergeRequest
    {
        $body = [
            'source_branch' => $request->sourceBranch,
            'target_branch' => $request->targetBranch,
            'title' => $request->title,
            'description' => $request->description,
            'remove_source_branch' => true,
        ];
        if ([] !== $request->labels) {
            $body['labels'] = implode(',', $request->labels);
        }
        if ([] !== $request->assigneeIds) {
            $body['assignee_ids'] = $request->assigneeIds;
        }
        if ([] !== $request->reviewerIds) {
            $body['reviewer_ids'] = $request->reviewerIds;
        }

        return $this->mergeRequest($this->request('POST', $this->projectPath . '/merge_requests', ['json' => $body]));
    }

    public function updateMergeRequest(int $iid, string $title, string $description, array $addLabels = [], array $removeLabels = []): MergeRequest
    {
        $body = ['title' => $title, 'description' => $description];
        if ([] !== $addLabels) {
            $body['add_labels'] = implode(',', $addLabels);
        }
        if ([] !== $removeLabels) {
            $body['remove_labels'] = implode(',', $removeLabels);
        }

        return $this->mergeRequest($this->request('PUT', sprintf('%s/merge_requests/%d', $this->projectPath, $iid), ['json' => $body]));
    }

    public function closeMergeRequest(int $iid): void
    {
        $this->request('PUT', sprintf('%s/merge_requests/%d', $this->projectPath, $iid), ['json' => ['state_event' => 'close']]);
    }

    public function addNote(int $iid, string $body): void
    {
        $this->request('POST', sprintf('%s/merge_requests/%d/notes', $this->projectPath, $iid), ['json' => ['body' => $body]]);
    }

    public function listNoteBodies(int $iid): array
    {
        $bodies = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $notes = $this->request('GET', sprintf('%s/merge_requests/%d/notes', $this->projectPath, $iid), ['query' => ['per_page' => self::PER_PAGE, 'page' => $page]]);

            foreach ($notes as $note) {
                if (\is_array($note) && \is_string($note['body'] ?? null)) {
                    $bodies[] = $note['body'];
                }
            }
            if (\count($notes) < self::PER_PAGE) {
                break;
            }
        }

        return $bodies;
    }

    public function findUserId(string $username): ?int
    {
        $users = $this->request('GET', 'users', ['query' => ['username' => $username]]);

        foreach ($users as $user) {
            if (\is_array($user) && ($user['username'] ?? null) === $username && \is_int($user['id'] ?? null)) {
                return $user['id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     *
     * @throws GitLabException
     */
    private function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->http->request($method, $path, $options);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new GitLabException(sprintf('GitLab answered HTTP %d to %s %s: %s', $status, $method, $path, $this->errorMessage($response)));
            }

            return $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new GitLabException(sprintf('GitLab request %s %s failed: %s', $method, $path, $e->getMessage()), 0, $e);
        }
    }

    private function errorMessage(ResponseInterface $response): string
    {
        $data = $response->toArray(false);
        $message = $data['message'] ?? $data['error'] ?? null;

        return \is_string($message) ? $message : trim(json_encode($message ?? $data, \JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param array<mixed> $data
     */
    private function mergeRequest(array $data): MergeRequest
    {
        $labels = [];
        foreach (\is_array($data['labels'] ?? null) ? $data['labels'] : [] as $label) {
            if (\is_string($label)) {
                $labels[] = $label;
            }
        }

        return new MergeRequest(
            $this->int($data, 'iid'),
            $this->string($data, 'title'),
            \is_string($data['description'] ?? null) ? $data['description'] : '',
            $this->string($data, 'web_url'),
            $this->string($data, 'source_branch'),
            $labels,
            true === ($data['has_conflicts'] ?? null),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function int(array $data, string $key): int
    {
        if (!\is_int($data[$key] ?? null)) {
            throw new GitLabException(sprintf('Unexpected GitLab response: "%s" is missing or not an integer.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private function string(array $data, string $key): string
    {
        if (!\is_string($data[$key] ?? null)) {
            throw new GitLabException(sprintf('Unexpected GitLab response: "%s" is missing or not a string.', $key));
        }

        return $data[$key];
    }
}
