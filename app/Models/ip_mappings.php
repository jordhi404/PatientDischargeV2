<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ip_mappings extends Model
{
    use HasFactory;

    protected $connection = 'pgsql';
    protected $fillable = ['ip_address', 'unit'];
}