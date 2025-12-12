<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class JournalSeeder extends Seeder
{
    /**
     * サンプル論文誌のシーダー
     *
     * 本番環境では使用せず，ユーザーが自身で論文誌を登録します．
     */
    public function run(): void
    {
        // 論文誌はユーザーごとに登録するため，シーダーでは作成しない
    }
}
