<?php

namespace Xianghuawe\Archivable\Console;

use Illuminate\Contracts\Events\Dispatcher;
use Xianghuawe\Archivable\{
    ModelsArchived,
    ArchivableTableStructureSync,
};

class TableStructureSyncCommand extends ArchiveCommand
{
    use ArchivableTableStructureSync;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'model:archive-structure-sync
                                {--model=* : Class names of the models to be archivable}
                                {--except=* : Class names of the models to be excluded from archivable}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive models that are no longer needed';

    /**
     * Execute the console command.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function handle(Dispatcher $events)
    {
        $models = $this->models();

        if ($models->isEmpty()) {
            $this->output->info('No archiveAble models found.');

            return;
        }

        $models->each(function ($model) {

            $model = new $model;
            $table = $model->getTable();
            // 1. 检查原表是否存在
            if (!$this->sourceTableExists($table)) {
                $this->output->error("原库不存在表: {$table}");
                return;
            }

            // 2. 目标表不存在 → 直接创建
            if (!$this->destinationTableExists($table)) {
                $this->createTable($table);
                $this->output->success("成功创建表: {$table}");
                return;
            }

            // 3. 目标表已存在 → 对比差异并更新
            $diff = $this->getStructureDiff($table);
            if (empty($diff)) {
                $this->output->info("表结构一致，跳过: {$table}");
                return;
            }

            // 4. 执行差异更新
            $this->applyDiff($table, $diff);
            $this->output->success("成功更新表结构: {$table}（差异数: " . count($diff) . "）");
        });

        $events->forget(ModelsArchived::class);
    }
}
