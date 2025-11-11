<?php

namespace Xianghuawe\Archivable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Xianghuawe\Archivable\DateFieldArchivable;

class TestModel extends Model
{
    use DateFieldArchivable;

    protected $table = 'test_models';

    public function getDestinationTable()
    {
        return 'test_models_' . now()->format('Ym');
    }
}
