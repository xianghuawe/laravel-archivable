<?php

declare(strict_types=1);

namespace Xianghuawe\Archivable;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

trait Archivable
{
    use ArchivableTableStructureSync;

    /**
     * archive all archivable models in the database.
     *
     * @return int
     */
    public function archiveAll(?int $chunkSize = null)
    {
        $chunkSize = $chunkSize ?? config('archive.chunk_size');
        $total = $this->archivable()->count();
        if ($total == 0) {
            return 0;
        }
        $archiveTableName = $this->getTable();
        if (Schema::connection(config('archive.db'))->hasTable($archiveTableName)) {
            if (!empty($this->getStructureDiff($this->getTable()))) {
                throw new LogicException('archive table ' . $archiveTableName . ' table structure is changed');
            }
        } else {
            throw new LogicException('archive table ' . $archiveTableName . ' does not exist');
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
        $archiveTableName = $this->getTable();

        return DB::connection(config('archive.db'))->table($archiveTableName)->insertOrIgnore($this->attributes);
    }
}
