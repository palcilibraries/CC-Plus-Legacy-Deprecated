<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Consortium;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Crypt;

class JsonCrypter extends Command
{
    /**
     * The name and signature for the console command.
     * @var string
     */
    protected $signature = "ccplus:jsoncrypter { consortium : Consortium ID or key-string [ALL] }
                                               { --E|encrypt : [Decrypt]}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Decrypt/Encrypt Saved JSON report datafiles';

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
        $conarg = $this->argument('consortium');
        if (is_null($conarg)) {
            $conarg = "ALL";
        } else {
            $consortium = Consortium::find($conarg);
            if (is_null($consortium)) {
                $consortium = Consortium::where('ccp_key', '=', $conarg)->first();
            }
            if (is_null($consortium)) {
                $this->line('Cannot locate Consortium: ' . $conarg);
                return 0;
            }
        }
        $type = ($this->option('encrypt')) ? 'encrypt' : 'decrypt';

        // Set path to be processed
        $report_path = null;
        if (!is_null(config('ccplus.reports_path'))) {
            $report_path = config('ccplus.reports_path');
        }
        if (is_null($report_path)) {
            $this->line('Report path not defined in config/ccplus ... quitting.');
        }
        $report_path = preg_replace("/\/$/","",$report_path);

        // Confirm path and operation type before proceeding
        $confirm_path = $report_path;
        $confirm_path .= ($conarg=="ALL") ? "" : "/" . $consortium->id;
        $this->line('');
        $this->info("About to ".$type." *.json files in-place in the path: " . $confirm_path);
        if (!$this->confirm('Proceed?')) {
            return 0;
        }

        // Traverse the given (or current) path
        $fileSystemIterator = new \FilesystemIterator($report_path);
        foreach ($fileSystemIterator as $consoFile) {

            // Set path for institution-level of the tree
            $_cname = $consoFile->getFilename();
            if ($conarg != 'ALL' && $_cname != strval($consortium->id)) continue;
            $path_inst = $report_path . "/" . $_cname;

            // Iterate through the folders in the consortium
            $consoFolderIterator = new \FilesystemIterator($path_inst);
            foreach ($consoFolderIterator as $instFile) {
                $_iname = $instFile->getFilename();

                // Process provider folders inside
                $path_prov = $path_inst . "/" . $_iname;
                $instFolderIterator = new \FilesystemIterator($path_prov);
                foreach ($instFolderIterator as $provFile) {
                    $_pname = $provFile->getFilename();
                    // Process datafiles inside
                    $path_json = $path_prov . "/" . $_pname;
                    $provFolderIterator = new \FilesystemIterator($path_json);
                    foreach ($provFolderIterator as $jsonFile) {
                        $this->line('File: ' . $jsonFile);
                        if ($type == 'encrypt') {
                            $data = file_get_contents($jsonFile);
                            if (File::put($jsonFile, Crypt::encrypt(bzcompress($data, 9), false)) === false) {
                                $this->line("Failed to save raw data in: " . $jsonFile);
                            }
                        } else {
                            $data = bzdecompress(Crypt::decrypt(File::get($jsonFile), false));
                            file_put_contents($jsonFile, $data);
                        }
                    } // process JSON files
                } // process provider folders
            } // process institution-folders
        } // process consortium-folders
    }
}
