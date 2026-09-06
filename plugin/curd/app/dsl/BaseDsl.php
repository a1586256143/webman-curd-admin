<?php

namespace plugin\curd\app\dsl;

class BaseDsl
{
    /**
     * 处理表名，兼容 a_business表名 或 ABusiness模型
     * @param $table
     * @return mixed|string|null
     */
    protected static function processTable($table){
        if (str_contains($table, "\\")){
            $explode = explode("\\", $table);
            $table = array_pop($explode);
        }
        return $table;
    }
}