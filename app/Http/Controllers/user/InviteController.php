<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\User\InviteService;
use Illuminate\Http\JsonResponse;

class InviteController extends Controller
{
    public function summary(InviteService $invites): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('User login required.');
        }

        return $this->jsonSuccess('Invite summary', $invites->inviteSummary($userId));
    }

    public function records(InviteService $invites): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('User login required.');
        }

        return $this->jsonSuccess('Invite records', $invites->inviteRecords($userId));
    }

    private function currentUserId(): ?int
    {
        $id = session('user.id');

        return $id === null ? null : (int) $id;
    }

    private function jsonSuccess(string $message, array $data): JsonResponse
    {
        return response()->json([
            'code' => 1,
            'msg' => $message,
            'data' => $data,
            'url' => '',
            'wait' => 3,
            '__token__' => csrf_token(),
        ]);
    }

    private function jsonError(string $message): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'msg' => $message,
            'data' => [],
            'url' => '',
            'wait' => 3,
            '__token__' => csrf_token(),
        ]);
    }
}
