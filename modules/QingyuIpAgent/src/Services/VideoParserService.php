<?php

namespace Modules\QingyuIpAgent\Services;

use App\User\VipService;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class VideoParserService
{
    private const MAX_HTML_BYTES = 1048576;

    private const SUPPORTED_HOST_SUFFIXES = [
        'douyin.com',
        'iesdouyin.com',
        'kuaishou.com',
        'chenzhongtech.com',
        'xiaohongshu.com',
        'xhslink.com',
    ];

    public function __construct(private readonly VipService $vip) {}

    public function parse(array $user, array $input): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('请先登录。');
        }
        if (! ($this->vip->summary($userId)['active'] ?? false)) {
            throw new InvalidArgumentException('会员权限不足，请先激活会员后再提取文案。');
        }

        $text = $this->firstString($input, ['text', 'content', 'shareText', 'raw', 'source']);
        $url = $this->firstString($input, ['url', 'videoUrl', 'link']);
        if ($url === '') {
            $url = $this->extractFirstUrl($text);
        }
        $url = $this->normalizeUrl($url);

        $sharedCopy = $this->extractCopyText($text);
        if ($sharedCopy !== '') {
            return $this->buildResult($sharedCopy, [
                'url' => $url,
                'originalUrl' => $url,
                'platform' => $this->platformForUrl($url),
                'title' => $this->shortTitle($sharedCopy),
                'description' => $sharedCopy,
                'author' => '',
                'source' => 'share_text',
                'fetched' => false,
            ]);
        }

        foreach ($this->pageCandidates($url) as $candidate) {
            $html = $this->fetchPage($candidate);
            if ($html === null) {
                continue;
            }

            $metadata = $this->extractMetadata($html);
            $content = $metadata['description'] ?: $metadata['title'];
            if ($content === '') {
                continue;
            }

            return $this->buildResult($content, [
                'url' => $candidate,
                'originalUrl' => $url,
                'platform' => $this->platformForUrl($candidate),
                'title' => $metadata['title'] ?: $this->shortTitle($content),
                'description' => $content,
                'author' => $metadata['author'],
                'source' => $metadata['source'],
                'fetched' => true,
            ]);
        }

        throw new InvalidArgumentException('链接有效，但平台未返回可提取的文案，请稍后重试或粘贴完整分享文本。');
    }

    private function firstString(array $input, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($input[$key]) && is_scalar($input[$key])) {
                return trim((string) $input[$key]);
            }
        }

        return '';
    }

    private function extractFirstUrl(string $text): string
    {
        if (! preg_match('~https?://[^\s<>"\'，。、“”‘’]+~u', $text, $matches)) {
            return '';
        }

        return rtrim($matches[0], " \t\n\r\0\x0B.,!?;:)]}）】，。！？；：");
    }

    private function normalizeUrl(string $url): string
    {
        $url = rtrim(trim($url), " \t\n\r\0\x0B.,!?;:)]}）】，。！？；：");
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('未找到有效的视频分享链接。');
        }

        if (! $this->isSupportedUrl($url)) {
            throw new InvalidArgumentException('暂不支持该视频平台链接。');
        }

        return $url;
    }

    private function isSupportedUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($scheme, ['http', 'https'], true) && $this->isSupportedHost($host);
    }

    private function isSupportedHost(string $host): bool
    {
        foreach (self::SUPPORTED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function extractCopyText(string $text): string
    {
        $text = preg_replace('~https?://[^\s<>"\'，。、“”‘’]+~u', '', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text, " \t\n\r\0\x0B#，。！？；：,.!?;:-");
    }

    private function pageCandidates(string $url): array
    {
        $candidates = [$url];
        $videoId = $this->douyinVideoId($url);
        if ($videoId !== '') {
            $candidates[] = 'https://m.douyin.com/share/video/'.$videoId;
            $candidates[] = 'https://www.iesdouyin.com/share/video/'.$videoId.'/';
            $candidates[] = 'https://www.douyin.com/video/'.$videoId;
        }

        return array_values(array_unique($candidates));
    }

    private function douyinVideoId(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! ($host === 'douyin.com' || str_ends_with($host, '.douyin.com') || $host === 'iesdouyin.com' || str_ends_with($host, '.iesdouyin.com'))) {
            return '';
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query !== '') {
            parse_str($query, $params);
            $modalId = isset($params['modal_id']) && is_scalar($params['modal_id']) ? (string) $params['modal_id'] : '';
            if (preg_match('/^\d{8,}$/', $modalId)) {
                return $modalId;
            }
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('~/(?:share/)?video/(\d{8,})~', $path, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function fetchPage(string $url): ?string
    {
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= 5; $redirects++) {
            try {
                $response = Http::withOptions(['allow_redirects' => false])->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                    'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148',
                ])->connectTimeout(5)->timeout(15)->get($currentUrl);
            } catch (Throwable) {
                return null;
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                if ($redirects === 5) {
                    return null;
                }

                $currentUrl = $this->resolveRedirectUrl($currentUrl, (string) $response->header('Location'));
                if ($currentUrl === null) {
                    return null;
                }

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            if (trim($html) === '') {
                return null;
            }

            return substr($html, 0, self::MAX_HTML_BYTES);
        }

        return null;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        try {
            $url = (string) UriResolver::resolve(new Uri($baseUrl), new Uri($location));
        } catch (Throwable) {
            return null;
        }

        return $this->isSupportedUrl($url) ? $url : null;
    }

    private function extractMetadata(string $html): array
    {
        $douyin = $this->extractDouyinRouterMetadata($html);
        if ($douyin !== null) {
            return $douyin;
        }

        $title = $this->cleanText($this->matchTitle($html));
        $description = $this->cleanText($this->matchMeta($html, ['og:description', 'description', 'twitter:description']));
        $metaTitle = $this->cleanText($this->matchMeta($html, ['og:title', 'twitter:title']));

        return [
            'title' => $metaTitle ?: $title,
            'description' => $description,
            'author' => '',
            'source' => 'page_meta',
        ];
    }

    private function extractDouyinRouterMetadata(string $html): ?array
    {
        if (! preg_match('~window\._ROUTER_DATA\s*=\s*(\{.*?\})\s*</script>~s', $html, $matches)) {
            return null;
        }

        $data = json_decode($matches[1], true);
        if (! is_array($data)) {
            return null;
        }

        $item = $this->findDouyinItem($data);
        if ($item === null) {
            return null;
        }

        $description = $this->cleanText((string) ($item['desc'] ?? ''));
        if ($description === '') {
            return null;
        }

        return [
            'title' => $this->shortTitle($description),
            'description' => $description,
            'author' => $this->cleanText((string) ($item['author']['nickname'] ?? '')),
            'source' => 'douyin_router',
        ];
    }

    private function findDouyinItem(array $value): ?array
    {
        if (isset($value['aweme_id']) && array_key_exists('desc', $value)) {
            return $value;
        }

        foreach ($value as $child) {
            if (! is_array($child)) {
                continue;
            }
            $item = $this->findDouyinItem($child);
            if ($item !== null) {
                return $item;
            }
        }

        return null;
    }

    private function matchTitle(string $html): string
    {
        return preg_match('~<title[^>]*>(.*?)</title>~is', $html, $matches) ? $matches[1] : '';
    }

    private function matchMeta(string $html, array $names): string
    {
        foreach ($names as $name) {
            $quoted = preg_quote($name, '~');
            $patterns = [
                '~<meta[^>]+(?:name|property)=["\']'.$quoted.'["\'][^>]+content=["\']([^"\']*)["\'][^>]*>~is',
                '~<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:name|property)=["\']'.$quoted.'["\'][^>]*>~is',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $matches)) {
                    return $matches[1];
                }
            }
        }

        return '';
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function platformForUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_contains($host, 'douyin') || str_contains($host, 'iesdouyin')) {
            return 'douyin';
        }
        if (str_contains($host, 'kuaishou') || str_contains($host, 'chenzhongtech')) {
            return 'kuaishou';
        }
        if (str_contains($host, 'xiaohongshu') || str_contains($host, 'xhslink')) {
            return 'xiaohongshu';
        }

        return 'unknown';
    }

    private function shortTitle(string $content): string
    {
        return function_exists('mb_substr') ? mb_substr($content, 0, 60) : substr($content, 0, 60);
    }

    private function buildResult(string $content, array $videoInfo): array
    {
        return [
            'content' => $content,
            'extractedContent' => $content,
            'videoInfo' => $videoInfo,
        ];
    }
}
