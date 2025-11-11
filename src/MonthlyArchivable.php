<?php

namespace Xianghuawe\Archivable;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

trait MonthlyArchivable
{
    use Archivable;

    protected string $dateField = 'created_at';

    protected int $archiveMonthLimit = 6;

    public function getDestinationTable()
    {
        return $this->getDestinationTableByDate(now()->startOfMonth()->subMonths($this->archiveMonthLimit));
    }

    /**
     * @param $date
     *
     * @return string
     */
    public function getDestinationTableByDate($date)
    {
        return $this->getTable() . '_' . $date->format('Ym');
    }

    /**
     * 获得可归档的查询对象
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function archivable()
    {
        $query = static::where($this->dateField, '<', now()->subMonths($this->archiveMonthLimit)->toDateTimeString());
        if (in_array(
            SoftDeletes::class,
            class_uses($this)
        )) {
            return $query->withTrashed();
        }

        return $query;
    }

    /**
     * archive all archivable models in the database.
     *
     * @return int
     *
     * @throws \Throwable
     */
    public function archiveAll(?int $chunkSize = null)
    {
        $chunkSize = $chunkSize ?? config('archive.default_chunk_size');
        $total     = $this->archivable()->count();
        if ($total == 0) {
            return 0;
        }

        $dates = $this->archivable()
            ->groupByRaw("date_format( $this->dateField,'%Y-%m')")
            ->selectRaw("date_format($this->dateField,'%Y-%m') as date")
            ->pluck('date');

        $totalArchived = 0;
        foreach ($dates as $date) {
            $dateObj          = Carbon::parse($date);
            $archiveTableName = $this->getDestinationTableByDate($dateObj);
            $this->makeSureDestinationTableExists($archiveTableName);

            $runTimes = 1000; // 设置运行次数最大上限, 避免归档失败无限循环
            while ($runTimes--) {
                $data = $this->archivable()
                    ->where($this->dateField, '>=', $dateObj->copy()->toDateTimeString())
                    ->where($this->dateField, '<', $dateObj->copy()->addMonth()->toDateTimeString())
                    ->limit($chunkSize)
                    ->get();
                if ($data->count() == 0) {
                    break;
                }
                try {
                    $this->getArchiveDB()->table($archiveTableName)->insertOrIgnore($data->map->getAttributes()->all());
                    $totalArchived += $this->archivable()->whereIn($this->getKeyName(), $data->pluck($this->getKeyName())->toArray())->forceDelete(); // 删除操作必须保证插入成功才能删除
                } catch (\Exception $e) {
                    Log::error($e);
                }
            }
        }

        event(new ModelsArchived(static::class, $totalArchived));

        return $totalArchived;
    }


    /**
     * backup the model in the database.
     *
     * @return bool|null
     */
    public function archive()
    {
        $archiveTableName = $this->getDestinationTableByDate(Carbon::parse($this->${$this->dateField}));
        $this->makeSureDestinationTableExists($archiveTableName);
        return $this->getArchiveDB()->table($archiveTableName)->insertOrIgnore($this->attributes);
    }
}
