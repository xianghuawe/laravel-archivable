<?php

namespace Xianghuawe\Archivable\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Xianghuawe\Archivable\ArchivableTableStructureSync;
use Xianghuawe\Archivable\ModelsArchived;
use Xianghuawe\Archivable\Tests\Models\TestModel;

class ArchiveTest extends TestCase
{
    use RefreshDatabase, ArchivableTableStructureSync;

    private function getConnectionName()
    {
        return config('database.default');
    }

    /** @test */
    public function sync_archive_table_structure()
    {
        $testModel = new TestModel();

        Artisan::call('model:archive-structure-sync --model=' . str_replace('\\', '\\\\', get_class($testModel)));

        $this->assertTrue(empty($this->getStructureDiff($testModel->getTable())), '归档表结构与模型结构不一致');
    }

    /**
     * @test
     * @depends sync_archive_table_structure
     */
    public function no_need_archive_any_data()
    {
        // 准备测试数据
        $data = [
            ['name' => 'fake data', 'created_at' => now()->subMonths(1), 'data' => json_encode(['key' => 'value'])],
            ['name' => 'fake data', 'created_at' => now()->subMonths(1), 'data' => json_encode(['key' => 'value'])],
        ];

        TestModel::insert($data);

        // 执行备份
        $archiveModel = new TestModel;
        $needBackupCount = $archiveModel->archivable()->count();
        $this->assertEquals($needBackupCount, 0, '备份数据数量与模型数量不一致');
        $archiveModel->archiveAll();
        // 验证备份数据
        $this->assertDatabaseCount($archiveModel->getTable(), count: count($data));
    }


    /**
     * @test
     * @depends sync_archive_table_structure
     */
    public function need_archive_some_data()
    {
        $eventBackedUp = 0;
        Event::listen(ModelsArchived::class, function (ModelsArchived $event) use (&$eventBackedUp) {
            $eventBackedUp += $event->count;
        });

        $archiveModel = new TestModel;

        // 准备测试数据
        $backupDataCount = 0;
        $data = [
            [
                'name' => 'fake data',
                'created_at' => now(),
                'data' => json_encode(['key' => 'value']),
            ],
        ];
        for ($i = 0; $i < 10000; $i++) {
            $c = now()->startOfMonth()->subMonths(6)->addSeconds($i);
            $data[] = [
                'name' => 'fake data' . $i,
                'created_at' => $c,
                'data' => json_encode(['key' => 'value']),
            ];
            $backupDataCount++;
        }

        foreach (array_chunk($data, 2000) as $chunkData) {
            TestModel::insert($chunkData);
        }

        // 执行备份
        $needBackupCount = $archiveModel->archivable()->count();
        $this->assertEquals($backupDataCount, $needBackupCount, '备份数据数量与模型数量不一致');
        $archiveModel->archiveAll();

        // 验证备份数据
        $this->assertEquals(1, TestModel::count(), '无需备份的数据数量不一致');
        $totalBackedUp = DB::connection(config('archive.db'))->table($archiveModel->getTable())->count();
        // 验证备份数据
        $this->assertEquals($backupDataCount, $totalBackedUp, '实际备份数量不一致');
        $this->assertEquals($backupDataCount, $eventBackedUp, '备份数据数量与事件数量不一致');
    }
}
