<?php

namespace Modules\QingyuIpAgent\Services;

use App\Models\UserAccount;
use App\User\ActivationCodeService;
use App\User\PasswordResetService;
use App\User\UserAuthService;
use App\User\VipService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ClientApiService
{
    public function __construct(
        private readonly UserAuthService $auth,
        private readonly PasswordResetService $passwords,
        private readonly ActivationCodeService $activationCodes,
        private readonly VipService $vip,
        private readonly VideoParserService $videoParser,
        private readonly RewriteService $rewrite,
        private readonly AuditLogService $audit
    ) {}

    public function bootstrap(): array
    {
        return [
            'csrf_token' => csrf_token(),
            'module' => 'qingyu_ip_agent',
        ];
    }

    public function register(array $payload, string $ip): array
    {
        $payload['source_module'] = 'qingyu_ip_agent';

        return $this->recorded('client.register', null, null, $payload, function () use ($payload, $ip): array {
            $registered = $this->auth->register($payload, $ip);
            $account = $payload['email'] ?? $payload['mobile'] ?? null;

            if (is_string($account) && trim($account) !== '' && ! empty($payload['password'])) {
                $this->auth->login([
                    'account' => $account,
                    'password' => $payload['password'],
                ], $ip);
            } else {
                session(['user' => $registered['user']]);
            }

            return $this->userPayload($registered['user']);
        });
    }

    public function login(array $payload, string $ip): array
    {
        return $this->recorded('client.login', null, null, $payload, function () use ($payload, $ip): array {
            $result = $this->auth->login($payload, $ip);

            return $this->userPayload($result['user']);
        });
    }

    public function profile(): array
    {
        $user = $this->currentUser();

        return $this->userPayload($user);
    }

    public function logout(): array
    {
        $this->auth->logout();

        return ['logged_out' => true];
    }

    public function activate(array $payload, string $ip): array
    {
        $user = $this->currentUser();

        return $this->recorded('client.activate', 'user_account', (int) $user['id'], $payload, function () use ($payload, $user, $ip): array {
            $result = $this->activationCodes->redeem([
                'code' => $payload['code'] ?? $payload['activationCode'] ?? null,
            ], (int) $user['id'], $ip);

            return $this->userPayload($this->currentUser()) + ['activation' => $result, 'vip' => $this->vip->summary((int) $user['id'])];
        });
    }

    public function parseContent(array $payload): array
    {
        $user = $this->currentUser();

        return $this->recorded(
            'client.video.parse',
            'user_account',
            (int) $user['id'],
            $payload,
            fn (): array => $this->videoParser->parse($user, $payload)
        );
    }

    public function rewrite(array $payload): array
    {
        $user = $this->currentUser();
        $vip = $this->vip->summary((int) $user['id']);
        if (! ($vip['active'] ?? false)) {
            throw new InvalidArgumentException('需要有效的 VIP 会员权限。');
        }

        $message = trim((string) ($payload['message'] ?? $payload['content'] ?? $payload['text'] ?? ''));
        $auditPayload = [
            'message_length' => mb_strlen($message, 'UTF-8'),
            'provider' => 'module-cloud',
        ];

        return $this->recorded(
            'client.rewrite',
            'user_account',
            (int) $user['id'],
            $auditPayload,
            fn (): array => $this->rewrite->rewrite($message)
        );
    }

    public function sendResetCode(array $payload, string $ip): array
    {
        return $this->recorded('client.password.forgot', null, null, $payload, function () use ($payload, $ip): array {
            return $this->passwords->requestReset([
                'account' => $payload['account'] ?? $payload['email'] ?? $payload['mobile'] ?? null,
            ], $ip);
        });
    }

    public function resetPassword(array $payload, string $ip): array
    {
        return $this->recorded('client.password.reset', null, null, $payload, function () use ($payload, $ip): array {
            return $this->passwords->resetPassword([
                'account' => $payload['account'] ?? $payload['email'] ?? $payload['mobile'] ?? null,
                'password' => $payload['password'] ?? $payload['newPassword'] ?? null,
                'token' => $payload['token'] ?? null,
                'code' => $payload['code'] ?? null,
            ], $ip);
        });
    }

    public function unsupported(string $action): array
    {
        throw new InvalidArgumentException("{$action} 暂未接入模块后台服务。");
    }

    private function currentUser(): array
    {
        $sessionUser = session('user');
        if (! is_array($sessionUser) || empty($sessionUser['id'])) {
            throw new InvalidArgumentException('请先登录。');
        }

        $account = UserAccount::query()->find((int) $sessionUser['id']);
        if ($account === null || ! in_array((string) $account->status, ['active'], true)) {
            session()->forget('user');
            throw new InvalidArgumentException('请先登录。');
        }

        $user = [
            'id' => (int) $account->id,
            'mobile' => $account->mobile,
            'email' => $account->email,
            'nickname' => $account->nickname,
            'status' => $account->status,
            'source_module' => $account->source_module ?: 'core',
        ];
        session(['user' => $user]);

        return $user;
    }

    private function userPayload(array $user): array
    {
        $vip = $this->vip->summary((int) $user['id']);
        $userInfo = $user + [
            'vip_level' => $vip['vip_level'],
            'vip_expire_at' => $vip['vip_expires_at'],
            'vip_expires_at' => $vip['vip_expires_at'],
            'is_vip' => $vip['active'] ? 1 : 0,
            'is_active' => $vip['active'] ? 1 : 0,
            'member_type' => $vip['active'] ? 'vip' : 'free',
            'daysRemaining' => $this->daysRemaining($vip['vip_expires_at']),
            'points' => 0,
        ];

        return [
            'user' => $user,
            'userInfo' => $userInfo,
            'vip' => $vip,
        ];
    }

    private function daysRemaining(?string $expiresAt): int
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return 0;
        }

        $expiresAt = Carbon::parse($expiresAt);
        if (! $expiresAt->isFuture()) {
            return 0;
        }

        return max(1, (int) ceil(Carbon::now()->diffInDays($expiresAt, false)));
    }

    private function recorded(string $action, ?string $targetType, ?int $targetId, array $payload, callable $callback): array
    {
        try {
            $result = $callback();
            $this->audit->record($action, $targetType, $targetId ?? $this->resultUserId($result), $payload, 'success');

            return $result;
        } catch (\Throwable $exception) {
            $this->audit->record($action, $targetType, $targetId, $payload, 'failed', $exception->getMessage());

            throw $exception;
        }
    }

    private function resultUserId(array $result): ?int
    {
        $user = $result['user'] ?? $result['userInfo'] ?? null;

        return is_array($user) && isset($user['id']) ? (int) $user['id'] : null;
    }
}
