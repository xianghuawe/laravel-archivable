<?php

namespace Xianghuawe\Archivable;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ArchivableDb
{
    public function getSourceDBConnectionName()
    {
        return $this->getConnectionName();
    }

    public function getArchiveDBConnectionName()
    {
        return config('archive.db');
    }

    /**
     * @return \Illuminate\Database\Connection
     */
    public function getArchiveDB()
    {
        return DB::connection($this->getArchiveDBConnectionName());
    }

    /**
     * @return \Illuminate\Database\Connection
     */
    public function getSourceDB()
    {
        return DB::connection($this->getSourceDBConnectionName());
    }

    /**
     * @return \Illuminate\Database\Schema\Builder
     */
    public function getArchiveSchema()
    {
        return Schema::connection($this->getArchiveDBConnectionName());
    }

    /**
     * @return \Illuminate\Database\Schema\Builder
     */
    public function getSourceSchema()
    {
        return Schema::connection($this->getSourceDBConnectionName());
    }
}
