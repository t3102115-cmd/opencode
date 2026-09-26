<?php

declare(strict_types=1);

final class GitHubClient
{
    /** repo_url may be "owner/repo" or a full https/ssh GitHub URL. */
    public static function parseOwnerRepo(string $repoUrl): array
    {
        $repoUrl = trim($repoUrl);
        if (preg_match('#^([\w.-]+)/([\w.-]+)$#', $repoUrl, $m)) {
            return [$m[1], $m[2]];
        }
        if (preg_match('#github\.com[:/]([\w.-]+)/([\w.-]+?)(\.git)?/?$#', $repoUrl, $m)) {
            return [$m[1], $m[2]];
        }

        throw new RuntimeException("Could not parse GitHub owner/repo from: $repoUrl");
    }

    public static function cloneUrlWithToken(string $owner, string $repo): string
    {
        $token = (string) Config::get('github_token');
        if ($token === '') {
            throw new RuntimeException('No GitHub token configured. Add one in Settings.');
        }

        return "https://x-access-token:{$token}@github.com/{$owner}/{$repo}.git";
    }

    public static function createPullRequest(
        string $owner,
        string $repo,
        string $head,
        string $base,
        string $title,
        string $body
    ): string {
        $token = (string) Config::get('github_token');
        if ($token === '') {
            throw new RuntimeException('No GitHub token configured. Add one in Settings.');
        }

        $ch = curl_init("https://api.github.com/repos/{$owner}/{$repo}/pulls");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'User-Agent: agent-php',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'title' => $title,
                'body' => $body,
                'head' => $head,
                'base' => $base,
            ]),
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $response, true);
        if ($status >= 300 || !is_array($decoded) || !isset($decoded['html_url'])) {
            throw new RuntimeException("GitHub PR creation failed (HTTP $status): " . substr((string) $response, 0, 2000));
        }

        return $decoded['html_url'];
    }
}
