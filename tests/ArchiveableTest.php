<?php

namespace Xianghuawe\Archivable\Tests;

use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Database\Eloquent\Model;
use Xianghuawe\Archivable\{Archivable, ModelsArchived};
use Illuminate\Foundation\Testing\RefreshDatabase;

// 创建测试模型
class TestModel extends Model
{
    use Archivable;
    protected $table = 'test_models';

    /**
     * Get the archivable model query.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function archivable()
    {
        return $this->query()->where('created_at', '<', now()->subMonths(6));
    }
}

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function no_need_archive_any_data()
    {
        // 准备测试数据
        $data = [
            ['name' => 'fake data', 'created_at' => now()->subMonths(1), 'data' => json_encode(['key' => 'value'])],
            ['name' => 'fake data', 'created_at' => now()->subMonths(1), 'data' => json_encode(['key' => 'value'])],
            ['name' => 'fake data', 'created_at' => now()->subMonths(1), 'data' => json_encode(['key' => 'value'])],
            ['name' => 'fake data', 'created_at' => now()->subMonths(1), 'data' => json_encode(['key' => 'value'])],
            ['name' => 'fake data', 'created_at' => now()->subMonths(2), 'data' => json_encode(['key' => 'value'])],
        ];

        TestModel::insert($data);

        // 执行备份
        $archiveModel = new TestModel();
        $needBackupCount = $archiveModel->archivable()->count();
        $this->assertEquals($needBackupCount, 0, '备份数据数量与模型数量不一致');
        $archiveModel->archiveAll();
        // 验证备份数据
        $this->assertDatabaseCount($archiveModel->getTable(), count: count($data));
    }

    /** @test */
    public function need_archive_some_data()
    {
        $eventBackedUp = 0;
        Event::listen(ModelsArchived::class, function (ModelsArchived $event) use (&$eventBackedUp) {
            $eventBackedUp += $event->count;
        });

        $archiveModel = new TestModel();

        // 准备测试数据
        $backupDataCount = 0;
        $data = [
            [
                'name' => 'fake data',
                'created_at' => now(),
                'data' => json_encode(['key' => 'value']),
            ]
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
        $this->assertEquals(1, TestModel::count());
        $totalBackedUp = DB::connection(config('archive.archive_db'))->table($archiveModel->getTable())->count();
        // 验证备份数据

        $this->assertEquals($backupDataCount, $totalBackedUp, '备份数据数量与事件数量不一致');
        $this->assertEquals($backupDataCount, $eventBackedUp, '备份数据数量与事件数量不一致');
    }
}
