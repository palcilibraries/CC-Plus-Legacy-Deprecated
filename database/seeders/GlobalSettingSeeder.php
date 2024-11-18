<?php

namespace Database\Seeders;

use DB;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class GlobalSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Make sure we're talking to the global database
        $_db = \Config::get('database.connections.globaldb.database');
        $table = $_db . ".global_settings";

        // Make sure table is empty
        if (DB::table($table)->get()->count() == 0) {
            $password = Hash::make('ChangeMeNow!');
            DB::table($table)->insert([
                                 ['id' => 1, 'type' =>'config', 'name' => 'root_url', 'value' => 'http://localhost/'],
                                 ['id' => 2, 'type' =>'config', 'name' => 'server_admin', 'value' => 'ServerAdmin'],
                                 ['id' => 3, 'type' =>'config', 'name' => 'server_admin_pass', 'value' => $password],
                                 ['id' => 4, 'type' =>'config', 'name' => 'log_login_fails', 'value' => '0'],
                                 ['id' => 5, 'type' =>'config', 'name' => 'max_harvest_retries', 'value' => '10'],
                                 ['id' => 6, 'type' =>'config', 'name' => 'reports_path', 'value' => '/usr/local/stats_reports/'],
                                 ['id' => 7, 'type' =>'config', 'name' => 'fiscalYr', 'value' => 'July'],
                                 ['id' => 8, 'type' =>'config', 'name' => 'cookie_life', 'value' => '90'],
                                 ['id' => 9, 'type' =>'config', 'name' => 'max_name_length', 'value' => '191'],
                                 ['id' => 10, 'type' =>'mail', 'name' => 'driver', 'value' => 'smtp'],
                                 ['id' => 11, 'type' =>'mail', 'name' => 'host', 'value' => null],
                                 ['id' => 12, 'type' =>'mail', 'name' => 'port', 'value' => 465],
                                 ['id' => 13, 'type' =>'mail', 'name' => 'username', 'value' => null],
                                 ['id' => 14, 'type' =>'mail', 'name' => 'password', 'value' => null],
                                 ['id' => 15, 'type' =>'mail', 'name' => 'encryption', 'value' => 'ssl'],
                                 ['id' => 16, 'type' =>'mail', 'name' => 'from_address', 'value' => null],
                                 ['id' => 17, 'type' =>'mail', 'name' => 'from_name', 'value' => 'CC-Plus-System'],
                              ]);
        }
    }
}
