<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Consortium;
use App\GlobalSetting;
use DB;
use Hash;
use Route;

class ResetAdminPW extends Command
{
    /**
     * The name and signature for the Sushi Batch processing console command.
     * @var string
     */
    protected $signature = "ccplus:resetadminpw {consortium? : A Consortium ID, key-string, or 'template'}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset Server Admin password in Consortium Databases';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
       // Make sure the username is set
        $server_admin = config('ccplus.server_admin');
        if (strlen($server_admin) == 0 ) {
            $this->error('Server Administrator username is not properly defined (verify or reseed the server settings table) !');
            return 0;
        }
        // Prompt for a new password value
        $server_admin_pass = $this->ask("Enter a new password for the '" . $server_admin . "' user (required) ", false);
        if (!$server_admin_pass) {
            $this->error('A new password value is required.');
            return 0;
        }

       // Default to NOT updating the template database
        $update_con_template = false;

       // If ID or Key given as input, go get it
        $databases = array();
        $conarg = $this->argument('consortium');
        if ($conarg) {
            // Allow update to just the template database
            if ($conarg == 'template') {
                $update_con_template = true;
            } else {
                $consortium = Consortium::find($conarg);
                if (is_null($consortium)) {
                    $consortium = Consortium::where('ccp_key', '=', $conarg)->first();
                }
                if (is_null($consortium)) {
                    $this->line('Cannot Find Consortium: ' . $conarg);
                    return 0;
                }
                $databases[] = "ccplus_" . $consortium->ccp_key;
            }
       // User wants to update all the consortia?
        } else {
            $confirmed = $this->ask('Reset all consortia system-wide, including the template [Y]?');
            if ($confirmed == "") {
                $confirmed = "Y";
            }
            if ($confirmed != 'Y') {
                $this->line('Exiting with no changes applied.');
                return 0;
            }
            // Get all the IDs as an array
            $consortia = Consortium::get();
            foreach ($consortia as $con) {
                $databases[] = "ccplus_" . $con->ccp_key;
            }
            $update_con_template = true;
        }

       // Update the value in the global_settings table
        $hashed_password = Hash::make($server_admin_pass);
        $_setting = GlobalSetting::where('name', 'server_admin_pass')->first();
        if (!$_setting) {
            $this->error('Cannot load global admin setting from database!');
            return 0;
        }
        $_setting->value = $hashed_password;
        $_setting->save();

        // Updating the template now if requested, ensure server admin user is properly defined
        if ($update_con_template) {
            config(['database.connections.consodb.database' => 'ccplus_con_template']);
            $serverAdminRole = \App\Role::where('name','ServerAdmin')->first();
            $user = \App\User::where('email', $server_admin)->where('id',1)->first();
            // If server admin user not found, zap users and reset things
            if ($user) {
                $pw_qry  = "UPDATE ccplus_con_template.users SET password = '" . $hashed_password;
                $pw_qry .= "' where email='" . $server_admin . "'";
                $result = DB::statement($pw_qry);
            // User not found, clear users and force an entry for id=1
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');
                $result = DB::statement("DELETE FROM ccplus_con_template.users");
                DB::table("ccplus_con_template.users")->insert([
                    ['id' => 1, 'name' => 'Server Administrator', 'password' => $hashed_password,
                     'email' => $server_admin, 'inst_id' => 1, 'is_active' => 1]
                    ]);
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
                    $user = \App\User::where('id',1)->first();
            }
            // make sure server admin role properly defined
            if ($serverAdminRole && $user) {
                $user->roles()->detach();
                $user->roles()->attach($serverAdminRole->id);
            }
            $this->line('<fg=cyan>ccplus_con_template Successfully Updated.');
        }

        // Update password for the other requested databases
        foreach ($databases as $_db) {
           $pw_qry  = "UPDATE " . $_db . ".users SET password = '" . $hashed_password;
           $pw_qry .= "' where email='" . $server_admin . "'";
           $result = DB::statement($pw_qry);
           $this->line('<fg=cyan>' . $_db . ' Successfully Updated.');
        }

        //Clear config cache:
        $exitCode = Artisan::call('config:cache');

        return 1;
    }
}
