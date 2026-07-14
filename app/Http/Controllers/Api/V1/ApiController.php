<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\User\UserApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Validator;

abstract class ApiController extends Controller
{
    protected function success(array $data = [], string $message = 'ok', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error(string $message, int $status, string $code): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'data' => [],
        ], $status);
    }

    protected function validationError(Validator $validator): JsonResponse
    {
        return $this->error($validator->errors()->first(), 422, 'validation_failed');
    }

    protected function apiException(UserApiException $exception): JsonResponse
    {
        return $this->error(
            $exception->getMessage(),
            $exception->httpStatus(),
            $exception->errorCode()
        );
    }
}
