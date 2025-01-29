<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Consortium;
use App\SushiSetting;
use DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Crypt;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;


class AuditSushiSettings extends Command
{
    /**
     * The name and signature for the Sushi Batch processing console command.
     * @var string
     */
    protected $signature = "ccplus:auditsushisettings {consortium : Consortium ID or key-string}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates spreadsheet of sushisettings against last-observed raw JSON data';

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
        $ident = "Sushi Settings Auditor";
        $ts = date("Y-m-d H:i:s") . " ";
        $conarg = $this->argument('consortium');
        $consortium = Consortium::find($conarg);
        if (is_null($consortium)) {
            $consortium = Consortium::where('ccp_key', '=', $conarg)->first();
        }
        if (is_null($consortium)) {
            $this->line($ts . $ident . 'Cannot locate Consortium: ' . $conarg);
            return 0;
        }
        if (!$consortium->is_active) {
            $this->line($ts . $ident . 'Consortium: ' . $conarg . " is NOT ACTIVE ... quitting.");
            return 0;
        }

        // Point the consodb connection at specified consortium's database and initialize the
        // path for raw JSON datafiles
        config(['database.connections.consodb.database' => 'ccplus_' . $consortium->ccp_key]);
        DB::reconnect();
        if (is_null(config('ccplus.reports_path'))) {
            $this->line($ts . "ReportProcessor: Global Setting for reports_path is not defined - Stopping!");
            return 0;
        }
        $report_path = null;
        if (!is_null(config('ccplus.reports_path'))) {
            $report_path = config('ccplus.reports_path');
        }
        if (is_null($report_path)) {
            $this->line('Report path not defined in config/ccplus ... quitting.');
            return 0;
        }
        $consortium_root = $report_path . $consortium->id . '/';

        // Get all Sushi Settings with successful harvestlogs that have a rawfile set
        $settings = SushiSetting::with(['provider','institution',
                                        'harvestLogs' => function ($qry) {
                                            $qry->where('status','Success')->whereNotNull('rawfile')->orderBy('yearmon','DESC');
                                        }
                                      ])
                                ->get();
        if (!$settings) {
            $this->line('No settings to audit.');
            return 0;
        }

        // Setup the spreadsheet and build the static ReadMe sheet
        $spreadsheet = new Spreadsheet();
        $settings_sheet = $spreadsheet->getActiveSheet();
        $settings_sheet->setTitle('COUNTER Settings');

        // Setup a new sheet for the data rows
        $settings_sheet->setCellValue('A1', 'Platform Name');
        $settings_sheet->setCellValue('B1', 'JSON Platform Value');
        $settings_sheet->setCellValue('C1', 'JSON Item Platform Value');
        $settings_sheet->setCellValue('D1', 'Institution Name');
        $settings_sheet->setCellValue('E1', 'JSON Institution Value');
        $row = 2;

        // Loop over the settings
        $bar = $this->output->createProgressBar(count($settings));
        $count = 0;
        foreach ($settings as $setting) {
            $bar->advance();
            $settings_sheet->setCellValue('A'.$row, $setting->provider->name);
            $settings_sheet->setCellValue('D'.$row, $setting->institution->name);
            $json_plat = 'no-JSON-found'; // default to no-data-found
            $json_inst = 'no-JSON-found'; // default to no-data-found
            $json_item_plat = 'no-Value-found'; // default to no-data-found

            // Find the most-recent rawfile in the harvestlogs for this setting
            if ($setting->harvestLogs) {
                foreach ($setting->harvestLogs as $harv) {
                    $jsonFile = $consortium_root . '/' . $setting->inst_id . '/' . $setting->prov_id . '/' . $harv->rawfile;
                    if (file_exists($jsonFile)) {
                        // decrypt and decompress the file
                        $json = json_decode(bzdecompress(Crypt::decrypt(File::get($jsonFile), false)));
                        // get JSON fields
                        if (isset($json->Report_Header)) {
                           $header = $json->Report_Header;
                           $json_plat = (isset($header->Created_By)) ? $header->Created_By : "no-Created_By";
                           $json_inst = (isset($header->Institution_Name)) ? $header->Institution_Name : "no-Institution_Name";
                           if (isset($json->Report_Items) && is_array($json->Report_Items)) {
                                if (isset($json->Report_Items[0]->Platform)) {
                                    $json_item_plat = $json->Report_Items[0]->Platform;
                                }
                           }
                        } else {
                            $json_plat = 'no-Report_Header';
                            $json_inst = 'no-Report_Header';
                        }
                    }

                    // if we got values, go on to the next sushi setting (otherwise, try another harvest)
                    if (substr($json_plat,0,3)!="no-" && substr($json_inst,0,3)!="no-") {
                        break;
                    }
                }
            }
            $settings_sheet->setCellValue('B'.$row, $json_plat);
            $settings_sheet->setCellValue('C'.$row, $json_item_plat);
            $settings_sheet->setCellValue('E'.$row, $json_inst);
            $row++;
        }
        // Auto-size the columns
        $columns = array('A','B','C','D','E');
        foreach ($columns as $col) {
            $settings_sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $bar->finish();

        // Save the data in the storage path
        $fileName = storage_path() . "/SushiSettings_Audit_" . date("Y_m_d" . ".xlsx");
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($fileName);

        // all done 
        $this->line(' ');
        $this->line('Saved spreadsheet in : ' . $fileName);
        return 1;
    }
}
