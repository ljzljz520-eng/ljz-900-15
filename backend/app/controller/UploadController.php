<?php
declare(strict_types=1);
namespace app\controller;
use app\model\User;
use think\facade\Log;
use think\facade\Request;
use think\Response;
class UploadController
{
    /** 允许的图片扩展名 => 可接受的 MIME */
    private const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const ALLOWED_IMAGE_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    /** 员工/管理员整改图统一上限：10MB */
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;

    private function getBearerToken(): ?string
    {
        $header = (string) Request::header('authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function image(): Response
    {
        try {
            // 必须是：管理员登录态（Bearer auth_token）或员工 token（二选一），禁止匿名上传
            $adminToken = $this->getBearerToken();
            $employeeToken = (string) Request::param('token');
            $authedUser = null;
            $scope = null;
            if ($adminToken) {
                $authedUser = User::where('auth_token', $adminToken)
                    ->where('role', 'admin')
                    ->where('auth_token_expires', '>', date('Y-m-d H:i:s'))
                    ->find();
                if ($authedUser) {
                    $scope = 'admin';
                }
            }
            if (!$authedUser && $employeeToken) {
                $authedUser = User::where('token', $employeeToken)
                    ->where('role', 'employee')
                    ->find();
                if ($authedUser) {
                    if (isset($authedUser->is_active) && (int) $authedUser->is_active !== 1) {
                        return api_json(['code' => 403, 'message' => '账号已禁用', 'data' => null], 200);
                    }
                    $scope = 'employee';
                }
            }
            if (!$authedUser) {
                return api_json(['code' => 401, 'message' => '未授权上传', 'data' => null], 200);
            }

            $file = Request::file('file');
            if (!$file) {
                return api_json(['code' => 400, 'message' => '请选择文件', 'data' => null]);
            }

            // 大小限制（服务端兜底，前端也会先拦截）
            $size = $file->getSize();
            if ($size === false || (int) $size <= 0) {
                return api_json(['code' => 400, 'message' => '文件无效或为空，请重新拍摄后上传', 'data' => null]);
            }
            if ($size > self::MAX_IMAGE_SIZE) {
                $mb = round(self::MAX_IMAGE_SIZE / 1024 / 1024);
                return api_json([
                    'code' => 413,
                    'message' => "图片大小不能超过 {$mb}MB（当前约 " . round($size / 1024 / 1024, 1) . 'MB），请压缩或重新拍摄后再上传',
                    'data' => ['max_size' => self::MAX_IMAGE_SIZE],
                ]);
            }

            // 扩展名校验
            $ext = strtolower($file->extension());
            if (!in_array($ext, self::ALLOWED_IMAGE_EXT, true)) {
                return api_json([
                    'code' => 400,
                    'message' => '仅支持 JPG、PNG、GIF、WEBP 格式的图片，请重新拍摄或转换格式后上传',
                    'data' => null,
                ]);
            }

            // 真实 MIME 校验，防止把非图片文件改后缀上传
            $mime = '';
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
            $tmpPath = $file->getRealPath() ?: $file->getPathname();
            if ($finfo && $tmpPath) {
                $mime = (string) finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
            }
            if ($mime && !in_array($mime, self::ALLOWED_IMAGE_MIME, true)) {
                return api_json([
                    'code' => 400,
                    'message' => '文件不是有效的图片（JPG/PNG/GIF/WEBP），请重新拍摄后上传',
                    'data' => null,
                ]);
            }

            $baseDir = public_path() . 'uploads';
            $dir = $baseDir;
            if ($scope === 'employee') {
                $dir = $baseDir . DIRECTORY_SEPARATOR . 'employees' . DIRECTORY_SEPARATOR . (string) $authedUser->id;
            } elseif ($scope === 'admin') {
                $dir = $baseDir . DIRECTORY_SEPARATOR . 'admin';
            }
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $name = date('YmdHis') . '_' . uniqid() . '.' . $ext;
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if ($tmpPath && is_uploaded_file($tmpPath)) {
                move_uploaded_file($tmpPath, $path);
            } else {
                @rename($tmpPath, $path);
            }
            $url = '/uploads/' . ($scope === 'employee' ? ('employees/' . $authedUser->id . '/') : ($scope === 'admin' ? 'admin/' : '')) . $name;
            return api_json(['code' => 0, 'message' => 'ok', 'data' => ['url' => $url, 'path' => $url, 'size' => (int) $size]]);
        } catch (\Throwable $e) {
            Log::error('UploadController@image: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
}
