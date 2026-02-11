<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    use HasFactory;

    // Снимаем защиту: разрешаем базе принимать любые колонки при массовом заполнение
    protected $guarded = [];
}
