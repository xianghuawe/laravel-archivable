<?php

namespace Xianghuawe\Archivable;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;

trait Archivable
{
    /**
     * Get archive table name
     *
     * @return string
     */
    public function getArchiveTable()
    {
        return $this->getTable();
    }

    /**
     * check if origin table changed
     *
     * @param string $table1
     * @param string $table2
     * @return boolean
     */
    function isTableChanged(string $table)
    {
        $sql        = 'show columns from `%s`';
        $table1Json = collect($this->getConnection()->select(sprintf($sql, $table)))->toJson();
        $table2Json = collect(DB::connection(config('archive.db'))->select(sprintf($sql, $table)))->toJson();
        return $table1Json !== $table2Json;
    }

    /**
     * archive all archivable models in the database.
     *
     * @param int $chunkSize
     * @return int
     */
    public function archiveAll(int $chunkSize = null)
    {
        $chunkSize = $chunkSize ?? config('archive.chunk_size');
        $total = $this->archivable()->count();
        if ($total == 0) {
            return 0;
        }
        $archiveTableName = $this->getTable();
        if (Schema::connection(config('archive.db'))->hasTable($archiveTableName)) {
            if ($this->isTableChanged($this->getTable())) {
                Log::warning('archive table ' . $archiveTableName . ' table structure is changed');
                return 0;
            }
        } else {
            Log::warning('archive table ' . $archiveTableName . ' does not exist');
            return 0;
        }

        $totalArchived = 0;
        $runTimes = 1000; // 设置运行次数最大上限, 避免归档失败无限循环
        while ($runTimes--) {
            $data = $this->archivable()->limit($chunkSize)->get();
            if ($data->count() == 0) {
                break;
            }
            DB::transaction(function () use ($data, $archiveTableName, &$totalArchived) {
                DB::connection(config('archive.db'))->table($archiveTableName)->insertOrIgnore($data->map->getAttributes()->all());
                $totalArchived += $this->archivable()->whereIn($this->getKeyName(), $data->pluck($this->getKeyName())->toArray())->forceDelete(); // 删除操作必须保证插入成功才能删除
            });
        }

        event(new ModelsArchived(static::class, $totalArchived));
        return $totalArchived;
    }

    /**
     * Get the archivable model query.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function archivable()
    {
        throw new LogicException('Please implement the archivable method on your model.');
    }

    /**
     * backup the model in the database.
     *
     * @return bool|null
     */
    public function archive()
    {
        $archiveTableName = $this->getArchiveTable();
        return DB::connection(config('archive.db'))->table($archiveTableName)->insertOrIgnore($this->attributes);
    }
}
