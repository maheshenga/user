<?php

namespace App\Http\Controllers\user;

use App\User\InviteService;
use Illuminate\Http\JsonResponse;

class InviteController extends UserApiController
{
    public function summary(InviteService $invites): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('请先登录。');
        }

        return $this->jsonSuccess('邀请概览', $invites->inviteSummary($userId));
    }

    public function records(InviteService $invites): JsonResponse
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->jsonError('请先登录。');
        }

        return $this->jsonSuccess('邀请记录', $invites->inviteRecords($userId));
    }

}
