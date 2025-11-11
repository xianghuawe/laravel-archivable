<?php

namespace Xianghuawe\Archivable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Xianghuawe\Archivable\MonthlyArchivable;

class MonthlyTestModel extends Model
{
    use MonthlyArchivable;
    protected $table = 'monthly_test_models';
}
