<?php
namespace plugin\crud\app\controller;

use OSS\Core\OssException;
use OSS\OssClient;
use support\Request;
use Webman\Http\UploadFile;

/**
 * 文件上传（本地 / 阿里云 OSS）
 *
 * 用法：
 *   POST /api/upload         通用文件上传（字段名 file，存 uploads/files）
 *   POST /api/upload/image   图片上传（字段名 file，校验图片类型，存 uploads/images）
 *
 * 存储驱动由 config('admin.upload_driver') 控制：local | oss
 * 上传目录由 config('admin.upload_path') 控制，相对 public 目录，如 uploads
 * 成功返回：{ code:200, msg:'上传成功', data:{ url:'uploads/files/xxx.png' } }（不带域名）
 */
class UploadController
{
    protected const MAX_SIZE = 5 * 1024 * 1024; // 5MB

    /**
     * 通用上传（存 uploads/files）
     */
    public function upload(Request $request)
    {
        return $this->handle($request, 'files', false);
    }

    /**
     * 图片上传（存 uploads/images，仅允许图片）
     */
    public function image(Request $request)
    {
        return $this->handle($request, 'images', true);
    }

    /**
     * 上传处理（按驱动选择本地或 OSS）
     */
    protected function handle(Request $request, string $subDir, bool $onlyImage): \support\Response
    {
        $file = $request->file('file');
        if (!$file) {
            return json(['code' => 400, 'msg' => '未收到文件（字段名 file）']);
        }
        // webman UploadFile 用 isValid()/getUploadErrorCode()（无 Symfony 的 getError()）
        if (!$file->isValid()) {
            return json(['code' => 400, 'msg' => '上传失败（错误码 ' . (string)($file->getUploadErrorCode() ?? 'unknown') . '）']);
        }
        if ($file->getSize() > self::MAX_SIZE) {
            return json(['code' => 400, 'msg' => '文件大小不能超过 5MB']);
        }
        if ($onlyImage) {
            $mime = $file->getUploadMimeType();
            if (!$mime) {
                // 客户端未上报 mime 时，用 finfo 读真实类型兜底
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: '';
            }
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'], true)) {
                return json(['code' => 400, 'msg' => '只允许上传图片（jpg/png/gif/webp/bmp）']);
            }
        }

        $driver = (string)config('admin.upload_driver', 'local');
        if ($driver === 'oss') {
            return $this->storeOss($file, $subDir);
        }
        return $this->storeLocal($file, $subDir);
    }

    /**
     * 本地上传：public/{upload_path}/{subDir}/{文件名}
     * 返回不带域名的相对路径，前端用 image_server/staticHost 拼完整地址
     */
    protected function storeLocal(UploadFile $file, string $subDir): \support\Response
    {
        $uploadPath = trim((string)config('admin.upload_path', 'uploads'), '/');
        $relDir = $uploadPath !== '' ? $uploadPath . '/' . $subDir : $subDir;
        $name = $this->buildFilename($file);
        $relPath = $relDir . '/' . $name;

        try {
            $file->move(public_path() . '/' . $relPath);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '本地存储上传失败：' . $e->getMessage()]);
        }

        return json(['code' => 200, 'msg' => '上传成功', 'data' => ['url' => $relPath]]);
    }

    /**
     * OSS 上传：key = [OSS_PREFIX/]{upload_path}/{subDir}/{文件名}
     * 返回不带域名的相对路径（含 OSS_PREFIX），前端用 image_server/staticHost 拼完整地址
     */
    protected function storeOss(UploadFile $file, string $subDir): \support\Response
    {
        $accessKey = (string)getenv('OSS_ACCESS_KEY');
        $secretKey = (string)getenv('OSS_SECRET_KEY');
        $endpoint  = (string)getenv('OSS_ENDPOINT');
        $bucket    = (string)getenv('OSS_BUCKET');
        if (!$accessKey || !$secretKey || !$endpoint || !$bucket) {
            return json(['code' => 500, 'msg' => 'OSS 未配置（.env 缺少 OSS_*）']);
        }

        $uploadPath = trim((string)config('admin.upload_path', 'uploads'), '/');
        $prefix = trim((string)getenv('OSS_PREFIX'), '/');
        $relDir = trim(($uploadPath !== '' ? $uploadPath . '/' : '') . $subDir, '/');
        $name = $this->buildFilename($file);
        $keyParts = array_filter([$prefix, $relDir]);
        $key = implode('/', $keyParts) . '/' . $name;

        try {
            $oss = new OssClient($accessKey, $secretKey, $endpoint);
            $oss->setTimeout(30);
            $oss->setConnectTimeout(10);
            $oss->uploadFile($bucket, $key, $file->getPathname());

            return json(['code' => 200, 'msg' => '上传成功', 'data' => ['url' => $key]]);
        } catch (OssException $e) {
            return json(['code' => 500, 'msg' => 'OSS 上传失败：' . $e->getMessage()]);
        }
    }

    /**
     * 生成文件名：时间戳 + uniqid + 原扩展名
     */
    protected function buildFilename(UploadFile $file): string
    {
        $ext = strtolower(pathinfo($file->getUploadName(), PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', (string)$ext) ?: 'bin';
        return date('YmdHis') . '_' . uniqid() . '.' . $ext;
    }
}
