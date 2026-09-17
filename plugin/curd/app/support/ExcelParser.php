<?php
namespace plugin\curd\app\support;

/**
 * 轻量 Excel/CSV 解析器（供 $form->excel() 字段用，不依赖 phpoffice/phpspreadsheet）
 *
 * 支持：
 *   - .xlsx（Office Open XML：ZipArchive + SimpleXML，覆盖 sharedStrings / inlineStr / 数字 / 公式结果字符串）
 *   - .csv（自动识别 UTF-8 BOM / GBK，首行表头）
 * 输出：首行作表头，每行为 ['表头' => 值, ...] 的关联数组；空行自动跳过。
 *
 * 说明：xlsx 内部不含每列的显示格式，日期单元格读出的是 Excel 序列数字
 * （如 45210.5），需要日期时业务侧自行换算或改用文本格式存储日期。
 */
class ExcelParser
{
    /** 默认最大解析行数（不含表头） */
    const DEFAULT_MAX_ROWS = 5000;

    /**
     * 解析上传文件为关联数组行集
     *
     * @param string $path     文件真实路径（$request->file('prop')->getRealPath()）
     * @param string $fileName 原始文件名（决定解析方式）
     * @param array  $options  ['maxRows' => int, 'sheet' => int(1 起，默认 1)]
     * @return array ['rows' => array[], 'count' => int, 'file_name' => string, 'sheets' => int|null]
     * @throws \RuntimeException 解析失败 / 文件格式不支持 / 超出 maxRows
     */
    public static function parse(string $path, string $fileName = '', array $options = []): array
    {
        $ext = strtolower(pathinfo($fileName ?: $path, PATHINFO_EXTENSION));
        $maxRows = (int)($options['maxRows'] ?? self::DEFAULT_MAX_ROWS);

        if ($ext === 'csv') {
            $rows = self::parseCsv($path, $maxRows);
            $sheets = null;
        } elseif (in_array($ext, ['xlsx', 'xlsm'], true)) {
            $rows = self::parseXlsx($path, $maxRows, (int)($options['sheet'] ?? 1), $sheets);
        } else {
            throw new \RuntimeException('不支持的文件格式：.' . $ext . '（请上传 xlsx 或 csv）');
        }

        if (empty($rows)) {
            throw new \RuntimeException('文件中没有可解析的数据行');
        }
        return [
            'rows' => $rows,
            'count' => count($rows),
            'file_name' => $fileName,
            'sheets' => $sheets,
        ];
    }

    // ============================================================
    // CSV
    // ============================================================

    protected static function parseCsv(string $path, int $maxRows): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('无法读取上传的文件');
        }
        // 去掉 UTF-8 BOM；GBK 输入转 UTF-8
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        } elseif (!preg_match('//u', $content)) {
            $converted = @iconv('GBK', 'UTF-8//IGNORE', $content);
            if ($converted !== false) {
                $content = $converted;
            }
        }
        // 按 "\\r\\n|\\r|\\n" 切行（保留引号内的换行：用状态机简化——常规导入文件直接按行切）
        $lines = preg_split("/\\r\\n|\\r|\\n/", trim($content));
        if (empty($lines)) {
            return [];
        }
        $header = self::parseCsvLine(array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = self::parseCsvLine($line);
            $row = [];
            foreach ($header as $i => $title) {
                $key = ($title !== '' && $title !== null) ? $title : self::colName($i);
                $row[$key] = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
            }
            // 整行为空则跳过
            if (implode('', array_map('strval', $row)) === '') {
                continue;
            }
            $rows[] = $row;
            if (count($rows) >= $maxRows) {
                break;
            }
        }
        return $rows;
    }

    /** 单行 CSV 切分（处理引号包裹、转义双引号、引号内逗号） */
    protected static function parseCsvLine(string $line): array
    {
        $cells = [];
        $cur = '';
        $inQuote = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($inQuote) {
                if ($ch === '"') {
                    if ($i + 1 < $len && $line[$i + 1] === '"') {
                        $cur .= '"';
                        $i++;
                    } else {
                        $inQuote = false;
                    }
                } else {
                    $cur .= $ch;
                }
            } elseif ($ch === '"') {
                $inQuote = true;
            } elseif ($ch === ',') {
                $cells[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        $cells[] = $cur;
        return $cells;
    }

    // ============================================================
    // XLSX
    // ============================================================

    protected static function parseXlsx(string $path, int $maxRows, int $sheetIndex, ?int &$sheets = null): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP 缺少 zip 扩展，无法解析 xlsx');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('无法打开 xlsx 文件（文件可能已损坏）');
        }

        // 共享字符串表：索引 → 文本
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false && $ssXml !== '') {
            $ss = simplexml_load_string($ssXml);
            if ($ss !== false) {
                foreach ($ss->si as $si) {
                    // 纯文本 <si><t>；富文本 <si><r><t>… 拼接
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string)$si->t;
                    } else {
                        foreach ($si->r as $r) {
                            $text .= isset($r->t) ? (string)$r->t : '';
                        }
                    }
                    $shared[] = $text;
                }
            }
        }

        // 目标 sheet：定位工作表文件（workbook 顺序 → rels 映射；失败回退 sheetN.xml）
        $sheetFile = self::resolveSheetFile($zip, $sheetIndex, $sheets);
        $sheetXml = $zip->getFromName($sheetFile);
        $zip->close();
        if ($sheetXml === false || $sheetXml === '') {
            throw new \RuntimeException('无法读取 xlsx 工作表数据');
        }
        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw new \RuntimeException('xlsx 工作表数据格式异常');
        }

        $rowsRaw = [];
        $maxCol = 0;
        foreach ($xml->sheetData->row as $rowNode) {
            $cells = [];
            foreach ($rowNode->c as $c) {
                $ref = isset($c['r']) ? (string)$c['r'] : '';
                $col = self::colIndex($ref !== '' ? preg_replace('/\\d+/', '', $ref) : '');
                $type = isset($c['t']) ? (string)$c['t'] : '';
                $value = '';
                if ($type === 's' && isset($c->v)) {
                    $idx = (int)(string)$c->v;
                    $value = isset($shared[$idx]) ? $shared[$idx] : '';
                } elseif ($type === 'inlineStr' && isset($c->is)) {
                    $value = isset($c->is->t) ? (string)$c->is->t : '';
                    foreach ($c->is->r as $r) {
                        $value .= isset($r->t) ? (string)$r->t : '';
                    }
                } elseif (isset($c->v)) {
                    $value = (string)$c->v; // 数字 / 公式结果字符串
                }
                $cells[$col] = $value;
                if ($col + 1 > $maxCol) {
                    $maxCol = $col + 1;
                }
            }
            $rowsRaw[] = $cells;
        }

        if (empty($rowsRaw)) {
            return [];
        }

        // 首行为表头（空表头用 A/B/C 兜底），后续行输出关联数组
        $headerCells = array_shift($rowsRaw);
        $header = [];
        for ($i = 0; $i < $maxCol; $i++) {
            $title = isset($headerCells[$i]) ? trim((string)$headerCells[$i]) : '';
            $header[$i] = $title !== '' ? $title : self::colName($i);
        }

        $rows = [];
        foreach ($rowsRaw as $cells) {
            $row = [];
            $isEmpty = true;
            for ($i = 0; $i < $maxCol; $i++) {
                $v = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
                if ($v !== '') {
                    $isEmpty = false;
                }
                $row[$header[$i]] = $v;
            }
            if ($isEmpty) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) >= $maxRows) {
                break;
            }
        }
        return $rows;
    }

    /** workbook.xml 顺序 → 关系文件映射到实际 sheet xml 路径 */
    protected static function resolveSheetFile(\ZipArchive $zip, int $sheetIndex, ?int &$sheets = null): string
    {
        $sheets = null;
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $wbXml = simplexml_load_string($wb);
            $relsXml = simplexml_load_string($rels);
            if ($wbXml !== false && $relsXml !== false) {
                $relMap = [];
                foreach ($relsXml->Relationship as $rel) {
                    $relMap[(string)$rel['Id']] = ltrim((string)$rel['Target'], '/');
                }
                $namespaces = $wbXml->getNamespaces(true);
                $rNs = isset($namespaces['r']) ? $namespaces['r'] : 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
                $sheetNodes = $wbXml->sheets->sheet;
                $sheets = count($sheetNodes);
                $idx = max(1, $sheetIndex) - 1;
                if (isset($sheetNodes[$idx])) {
                    $rid = (string)$sheetNodes[$idx]->attributes($rNs)->id;
                    if (isset($relMap[$rid]) && $zip->locateName($relMap[$rid]) !== false) {
                        return $relMap[$rid];
                    }
                }
            }
        }
        return 'xl/worksheets/sheet' . max(1, $sheetIndex) . '.xml';
    }

    /** 'A'→0, 'Z'→25, 'AA'→26 */
    protected static function colIndex(string $letters): int
    {
        $idx = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            if ($ch >= 'A' && $ch <= 'Z') {
                $idx = $idx * 26 + (ord($ch) - 64);
            }
        }
        return max(0, $idx - 1);
    }

    /** 0→'A', 25→'Z', 26→'AA' */
    protected static function colName(int $index): string
    {
        $name = '';
        $index += 1;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $index = intdiv($index - $mod, 26);
        }
        return $name;
    }
}
