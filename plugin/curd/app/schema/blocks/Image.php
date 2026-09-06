<?php

namespace plugin\curd\app\schema\blocks;

use plugin\curd\app\schema\SchemaNode;

/**
 * 图片 → el-image（src 支持 {{key.path}} 占位符）
 */
class Image extends SchemaNode
{
    public function __construct()
    {
        $this->type = 'image';
    }

    /** 图片地址 */
    public function src(string $src): static
    {
        return $this->set('src', $src);
    }

    /** 填充方式：fill / contain / cover / none / scale-down */
    public function fit(string $fit): static
    {
        return $this->set('fit', $fit);
    }

    /** 原图地址列表（开启点击预览大图） */
    public function previewSrcList(array $srcList): static
    {
        return $this->set('previewSrcList', $srcList);
    }

    /** 预览是否挂载到 body（避免被容器 overflow 裁剪） */
    public function previewTeleported(bool $previewTeleported = true): static
    {
        return $this->set('previewTeleported', $previewTeleported);
    }

    /** 替代文本 */
    public function alt(string $alt): static
    {
        return $this->set('alt', $alt);
    }

    /** 懒加载 */
    public function lazy(bool $lazy = true): static
    {
        return $this->set('lazy', $lazy);
    }
}
