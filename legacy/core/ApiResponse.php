<?php
// core/ApiResponse.php — Phase 7 standardized JSON payloads

class ApiResponse
{
    public const CODE_OK = 'ok';
    public const CODE_ERROR = 'error';
    public const CODE_CSRF_INVALID = 'csrf_invalid';
    public const CODE_UNAUTHORIZED = 'unauthorized';
    public const CODE_FORBIDDEN = 'forbidden';
    public const CODE_RATE_LIMITED = 'rate_limited';
    public const CODE_VALIDATION = 'validation_error';
    public const CODE_NOT_FOUND = 'not_found';

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function success(array $data = [], string $message = 'OK', string $code = self::CODE_OK): array
    {
        return array_merge([
            'status'  => 'success',
            'code'    => $code,
            'message' => $message,
        ], $data);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function error(string $message, string $code = self::CODE_ERROR, array $extra = []): array
    {
        return array_merge([
            'status'  => 'error',
            'code'    => $code,
            'message' => $message,
        ], $extra);
    }

    /**
     * Sequential list (0..n-1 keys only) — must not merge status into the list itself.
     */
    private static function isListArray(array $data): bool
    {
        if ($data === []) {
            return true;
        }

        $keys = array_keys($data);

        return $keys === range(0, count($data) - 1);
    }

    /**
     * Ensure every JSON API payload has status, message, and code.
     *
     * @param array<string, mixed>|mixed $data
     * @return array<string, mixed>
     */
    public static function normalize($data, int $httpCode = 200): array
    {
        if (!is_array($data)) {
            return self::error('Invalid response payload.', self::CODE_ERROR);
        }

        if (self::isListArray($data)) {
            $status = $httpCode >= 400 ? 'error' : 'success';

            return [
                'status'  => $status,
                'message' => $status === 'success' ? 'OK' : 'Request failed.',
                'code'    => $status === 'success' ? self::CODE_OK : self::inferCode('error', $httpCode, []),
                'data'    => $data,
            ];
        }

        $payload = $data;
        $status = (string)($payload['status'] ?? '');

        if ($status === '') {
            if (!empty($payload['success'])) {
                $status = 'success';
            } else {
                $status = $httpCode >= 400 ? 'error' : 'success';
            }
            $payload['status'] = $status;
        }

        if (!isset($payload['message']) || $payload['message'] === '') {
            if ($status === 'success') {
                $payload['message'] = 'OK';
            } elseif ($status === 'credit_limit_exceeded') {
                $payload['message'] = $payload['message'] ?? 'Credit limit exceeded.';
            } else {
                $payload['message'] = 'Request failed.';
            }
        }

        if (!isset($payload['code']) || $payload['code'] === '') {
            $payload['code'] = self::inferCode($status, $httpCode, $payload);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function inferCode(string $status, int $httpCode, array $payload): string
    {
        if ($status === 'credit_limit_exceeded') {
            return 'credit_limit_exceeded';
        }
        if ($status === 'success') {
            return self::CODE_OK;
        }
        if ($httpCode === 401) {
            return self::CODE_UNAUTHORIZED;
        }
        if ($httpCode === 403) {
            return self::CODE_FORBIDDEN;
        }
        if ($httpCode === 429) {
            return self::CODE_RATE_LIMITED;
        }
        if ($httpCode === 404) {
            return self::CODE_NOT_FOUND;
        }
        if ($status === 'warn' || $status === 'info') {
            return $status;
        }

        return self::CODE_ERROR;
    }

    public static function emit(array $payload, int $httpCode = 200): void
    {
        if ($httpCode !== 200) {
            http_response_code($httpCode);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(self::normalize($payload, $httpCode), JSON_UNESCAPED_UNICODE);
        exit;
    }
}