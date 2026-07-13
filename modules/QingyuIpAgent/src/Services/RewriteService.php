<?php

namespace Modules\QingyuIpAgent\Services;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RewriteService
{
    public function rewrite(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('请输入需要改写的文案。');
        }
        if (mb_strlen($message, 'UTF-8') > 12000) {
            throw new InvalidArgumentException('需要改写的文案不能超过 12000 个字符。');
        }

        $baseUrl = trim((string) config('qingyu_ip_agent.llm.base_url', ''));
        $apiKey = trim((string) config('qingyu_ip_agent.llm.api_key', ''));
        $model = trim((string) config('qingyu_ip_agent.llm.model', ''));
        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new RuntimeException('云端改写服务未配置。');
        }
        if (! $this->isHttpsUrl($baseUrl)) {
            throw new RuntimeException('云端改写服务地址无效。');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout($this->timeout())
                ->post($this->endpoint($baseUrl), [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => '你是短视频口播文案改写助手。保留原意，增强开头吸引力、表达节奏和记忆点，只输出改写后的正文。',
                        ],
                        ['role' => 'user', 'content' => $message],
                    ],
                    'temperature' => (float) config('qingyu_ip_agent.llm.temperature', 0.8),
                    'max_tokens' => max(1, (int) config('qingyu_ip_agent.llm.max_tokens', 1200)),
                    'stream' => false,
                ]);
        } catch (Throwable) {
            throw new RuntimeException('云端改写服务请求失败，请稍后重试。');
        }

        if (! $response->successful()) {
            throw new RuntimeException('云端改写服务暂不可用，请稍后重试。');
        }

        $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        if ($content === '') {
            throw new RuntimeException('云端改写服务未返回有效文案。');
        }

        return [
            'content' => $content,
            'text' => $content,
            'rewrittenContent' => $content,
            'provider' => 'module-cloud',
        ];
    }

    private function endpoint(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        return str_ends_with($baseUrl, '/chat/completions')
            ? $baseUrl
            : $baseUrl.'/chat/completions';
    }

    private function isHttpsUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowedHosts = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) config('qingyu_ip_agent.llm.allowed_hosts', [])
        )));

        return $host !== '' && in_array($host, $allowedHosts, true);
    }

    private function timeout(): int
    {
        return max(5, min(120, (int) config('qingyu_ip_agent.llm.timeout', 45)));
    }
}
