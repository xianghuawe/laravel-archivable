<?php

declare(strict_types=1);

namespace Xianghuawe\Archivable;

use Illuminate\Support\Facades\DB;

trait ArchivableTableStructureSync
{
    /**
     * 检查表是否存在
     */
    protected function tableExists(string $conn, string $table): bool
    {
        return DB::connection($conn)->getDoctrineSchemaManager()->tablesExist($table);
    }

    /**
     * 检查表是否存在
     */
    protected function sourceTableExists(string $table): bool
    {
        return DB::connection()->getDoctrineSchemaManager()->tablesExist($table);
    }

    /**
     * 检查表是否存在
     */
    protected function destinationTableExists(string $table): bool
    {
        return DB::connection(config('archive.db'))->getDoctrineSchemaManager()->tablesExist($table);
    }

    /**
     * 从原库复制表结构创建新表
     */
    protected function createTable(string $table): void
    {
        $createSql = DB::connection()
            ->selectOne("SHOW CREATE TABLE `{$table}`")->{'Create Table'};
        DB::connection(config('archive.db'))->statement($createSql);
    }

    /**
     * 对比原表和目标表的结构差异
     */
    protected function getStructureDiff(string $table): array
    {
        $diff = [];

        // 获取原表和目标表的字段信息
        $sourceColumns = $this->getTableColumns(null, $table);
        $targetColumns = $this->getTableColumns(config('archive.db'), $table);

        // 1. 检查目标表是否缺少字段
        foreach ($sourceColumns as $colName => $sourceCol) {
            if (!isset($targetColumns[$colName])) {
                // 目标表缺少字段 → 新增字段（包含 nullable 约束）
                $diff[] = $this->buildAddColumnSql($colName, $sourceCol);
            } else {
                // 字段存在 → 检查类型、nullable 等差异
                $targetCol = $targetColumns[$colName];
                $hasDiff = false;

                // 检查字段类型差异
                if ($targetCol['type'] !== $sourceCol['type']) {
                    $hasDiff = true;
                }

                // 检查 nullable 差异（核心新增逻辑）
                if ($targetCol['null'] !== $sourceCol['null']) {
                    $hasDiff = true;
                }

                // 检查默认值差异（可选）
                if ($this->defaultValueDiff($targetCol['default'], $sourceCol['default'])) {
                    $hasDiff = true;
                }

                // 有差异则生成 MODIFY COLUMN 语句
                if ($hasDiff) {
                    $diff[] = $this->buildModifyColumnSql($colName, $sourceCol);
                }
            }
        }

        // 3. 检查目标表是否有多余字段（可选：是否删除，默认不删除）
        foreach ($targetColumns as $colName => $targetCol) {
            if (!isset($sourceColumns[$colName])) {
                // 谨慎：删除字段会丢失数据，默认只记录不执行
                $diff[] = [
                    'type' => 'drop_column',
                    'sql' => "DROP COLUMN `{$colName}`",
                    'warning' => '删除字段可能导致数据丢失，默认不执行',
                ];
            }
        }

        // 4. 对比索引差异（简化版，可扩展）
        $sourceIndexes = $this->getTableIndexes(null, $table);
        $targetIndexes = $this->getTableIndexes(config('archive.db'), $table);
        foreach ($sourceIndexes as $indexName => $sourceIndex) {
            if (!isset($targetIndexes[$indexName])) {
                $diff[] = [
                    'type' => 'add_index',
                    'sql' => $sourceIndex['sql'],
                ];
            }
        }

        return $diff;
    }

    /**
     * 获取表字段信息（类型、长度等）
     */
    protected function getTableColumns(?string $conn, string $table): array
    {
        $columns = [];
        $rows = DB::connection($conn)->select("DESCRIBE `{$table}`");
        foreach ($rows as $row) {
            $columns[$row->Field] = [
                'type' => $row->Type, // 如 'int(11)', 'varchar(255)'
                'null' => $row->Null === 'YES',
                'default' => $row->Default,
                'extra' => $row->Extra,
            ];
        }

        return $columns;
    }

    /**
     * 获取表索引信息
     */
    protected function getTableIndexes(?string $conn, string $table): array
    {
        $indexes = [];
        $rows = DB::connection($conn)->select("SHOW INDEX FROM `{$table}`");
        foreach ($rows as $row) {
            if ($row->Key_name === 'PRIMARY') {
                continue;
            } // 主键通常在创建表时已处理
            $indexes[$row->Key_name] = [
                'columns' => $row->Column_name,
                'sql' => "ADD INDEX `{$row->Key_name}` (`{$row->Column_name}`)",
            ];
        }

        return $indexes;
    }

    /**
     * 应用结构差异（执行ALTER TABLE）
     */
    protected function applyDiff(string $table, array $diff): void
    {
        foreach ($diff as $item) {
            // 跳过删除字段（避免误删数据，按需开启）
            if ($item['type'] === 'drop_column') {
                continue;
            }
            // 执行ALTER TABLE语句
            DB::connection(config('archive.db'))->statement("ALTER TABLE `{$table}` {$item['sql']}");
        }
    }

    /**
     * 生成新增字段的 SQL（包含 nullable 约束）
     */
    protected function buildAddColumnSql(string $colName, array $sourceCol): array
    {
        // 构建字段定义（包含 NOT NULL 或 NULL）
        $columnDef = $sourceCol['type'];
        $columnDef .= $sourceCol['null'] ? ' NULL' : ' NOT NULL';

        // 处理默认值（如需要）
        if ($sourceCol['default'] !== null) {
            $columnDef .= " DEFAULT '{$sourceCol['default']}'";
        }

        // 处理自增（如需要）
        if (str_contains($sourceCol['extra'], 'auto_increment')) {
            $columnDef .= ' AUTO_INCREMENT';
        }

        return [
            'type' => 'add_column',
            'sql' => "ADD COLUMN `{$colName}` {$columnDef}",
        ];
    }

    /**
     * 生成修改字段的 SQL（包含 nullable 约束）
     */
    protected function buildModifyColumnSql(string $colName, array $sourceCol): array
    {
        // 逻辑同新增字段，确保 nullable 状态被正确应用
        $columnDef = $sourceCol['type'];
        $columnDef .= $sourceCol['null'] ? ' NULL' : ' NOT NULL';

        if ($sourceCol['default'] !== null) {
            $columnDef .= " DEFAULT '{$sourceCol['default']}'";
        }

        if (str_contains($sourceCol['extra'], 'auto_increment')) {
            $columnDef .= ' AUTO_INCREMENT';
        }

        return [
            'type' => 'modify_column',
            'sql' => "MODIFY COLUMN `{$colName}` {$columnDef}",
        ];
    }

    /**
     * 检查默认值是否有差异（辅助方法）
     */
    private function defaultValueDiff($targetDefault, $sourceDefault): bool
    {
        // 处理 NULL 默认值的特殊情况
        if ($targetDefault === null && $sourceDefault === null) {
            return false;
        }

        return $targetDefault != $sourceDefault;
    }
}
