<?php

declare(strict_types=1);

namespace Rustine\Tests\GitLab;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\GitLab\GitLabException;
use Rustine\GitLab\HttpGitLabClient;
use Rustine\GitLab\NewMergeRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpGitLabClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: ?array<mixed>, token: ?string}> */
    private array $requests = [];

    #[Test]
    public function itReadsTheOwnerOfTheToken(): void
    {
        $client = $this->client([$this->json(['id' => 7, 'username' => 'rustine-bot', 'name' => 'Rustine Bot', 'commit_email' => 'bot@example.com'])]);

        $user = $client->currentUser();

        self::assertSame(7, $user->id);
        self::assertSame('rustine-bot', $user->username);
        self::assertSame('bot@example.com', $user->commitEmail);
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame('https://gitlab.example.com/api/v4/user', $this->requests[0]['url']);
        self::assertSame('s3cret', $this->requests[0]['token']);
    }

    #[Test]
    public function anEmptyCommitEmailIsNull(): void
    {
        $client = $this->client([$this->json(['id' => 7, 'username' => 'bot', 'name' => 'Bot', 'commit_email' => ''])]);

        self::assertNull($client->currentUser()->commitEmail);
    }

    #[Test]
    public function itFindsTheOpenMergeRequestOfABranch(): void
    {
        $client = $this->client([$this->json([$this->mr(12, 'security/rustine-yarn-others')])]);

        $mr = $client->findOpenMergeRequest('security/rustine-yarn-others');

        self::assertSame(12, $mr?->iid);
        self::assertSame(['security'], $mr->labels);
        self::assertStringContainsString('/projects/42/merge_requests?state=opened&source_branch=security/rustine-yarn-others', urldecode($this->requests[0]['url']));
    }

    #[Test]
    public function itReturnsNullWhenThereIsNoOpenMergeRequest(): void
    {
        self::assertNull($this->client([$this->json([])])->findOpenMergeRequest('security/rustine-yarn-others'));
    }

    #[Test]
    public function itFollowsThePagesWhenListing(): void
    {
        $first = array_map(fn (int $i): array => $this->mr($i, 'b' . $i), range(1, 100));
        $client = $this->client([$this->json($first), $this->json([$this->mr(101, 'b101')])]);

        $all = $client->listOpenMergeRequests(['security', 'bot']);

        self::assertCount(101, $all);
        self::assertStringContainsString('labels=security,bot', urldecode($this->requests[0]['url']));
        self::assertStringContainsString('page=2', $this->requests[1]['url']);
    }

    #[Test]
    public function itCreatesAMergeRequest(): void
    {
        $client = $this->client([$this->json($this->mr(5, 'security/rustine-yarn-others'))]);

        $mr = $client->createMergeRequest(new NewMergeRequest('security/rustine-yarn-others', 'main', 'Title', 'Body', ['security', 'severity::high'], [3], [4, 5]));

        self::assertSame(5, $mr->iid);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame([
            'source_branch' => 'security/rustine-yarn-others',
            'target_branch' => 'main',
            'title' => 'Title',
            'description' => 'Body',
            'remove_source_branch' => true,
            'labels' => 'security,severity::high',
            'assignee_ids' => [3],
            'reviewer_ids' => [4, 5],
        ], $this->requests[0]['body']);
    }

    #[Test]
    public function updatingKeepsTheLabelsOthersAdded(): void
    {
        $client = $this->client([$this->json($this->mr(5, 'b'))]);

        $client->updateMergeRequest(5, 'T', 'D', ['severity::high'], ['severity::low']);

        self::assertSame('PUT', $this->requests[0]['method']);
        self::assertStringEndsWith('/projects/42/merge_requests/5', $this->requests[0]['url']);
        self::assertSame(['title' => 'T', 'description' => 'D', 'add_labels' => 'severity::high', 'remove_labels' => 'severity::low'], $this->requests[0]['body']);
    }

    #[Test]
    public function itClosesAMergeRequestAndAddsNotes(): void
    {
        $client = $this->client([$this->json($this->mr(5, 'b')), $this->json(['id' => 1])]);

        $client->closeMergeRequest(5);
        $client->addNote(5, 'Hello');

        self::assertSame(['state_event' => 'close'], $this->requests[0]['body']);
        self::assertStringEndsWith('/merge_requests/5/notes', $this->requests[1]['url']);
        self::assertSame(['body' => 'Hello'], $this->requests[1]['body']);
    }

    #[Test]
    public function itListsTheNoteBodies(): void
    {
        $client = $this->client([$this->json([['id' => 1, 'body' => 'first'], ['id' => 2, 'body' => 'second']])]);

        self::assertSame(['first', 'second'], $client->listNoteBodies(5));
        self::assertStringContainsString('/merge_requests/5/notes', $this->requests[0]['url']);
    }

    #[Test]
    public function itResolvesUsernamesExactly(): void
    {
        $client = $this->client([
            $this->json([['id' => 9, 'username' => 'alice-2'], ['id' => 8, 'username' => 'alice']]),
            $this->json([]),
        ]);

        self::assertSame(8, $client->findUserId('alice'));
        self::assertNull($client->findUserId('nobody'));
    }

    #[Test]
    public function itExplainsHttpErrors(): void
    {
        $client = $this->client([new MockResponse('{"message":"401 Unauthorized"}', ['http_code' => 401, 'response_headers' => ['content-type: application/json']])]);

        $this->expectException(GitLabException::class);
        $this->expectExceptionMessage('HTTP 401 to GET user: 401 Unauthorized');

        $client->currentUser();
    }

    #[Test]
    public function itRejectsUnexpectedPayloads(): void
    {
        $client = $this->client([$this->json(['username' => 'bot'])]);

        $this->expectException(GitLabException::class);

        $client->currentUser();
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): HttpGitLabClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = $options['body'] ?? null;
            $decoded = \is_string($body) && '' !== $body ? json_decode($body, true, 512, \JSON_THROW_ON_ERROR) : null;
            $headers = $options['headers'] ?? [];
            $token = null;
            foreach (\is_array($headers) ? $headers : [] as $header) {
                if (\is_string($header) && str_starts_with(strtolower($header), 'private-token:')) {
                    $token = trim(substr($header, 14));
                }
            }
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_array($decoded) ? $decoded : null,
                'token' => $token,
            ];

            return array_shift($responses) ?? self::fail('Unexpected request: ' . $method . ' ' . $url);
        });

        return new HttpGitLabClient($http, 'https://gitlab.example.com/api/v4', '42', 's3cret');
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mr(int $iid, string $branch): array
    {
        return ['iid' => $iid, 'title' => 'T', 'description' => 'D', 'web_url' => 'https://gitlab.example.com/g/p/-/merge_requests/' . $iid, 'source_branch' => $branch, 'labels' => ['security']];
    }
}
