<?php

namespace App\Http\Controllers;

use App\Consortium;
use App\User;
use App\Role;
use App\Institution;
use App\InstitutionGroup;
use App\Provider;
use App\GlobalProvider;
use App\SushiSetting;
use App\ConnectionField;
use App\Report;
use App\HarvestLog;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:Admin');
    }

    //Index method for Admin Controller
    public function index()
    {
        // Get the consortium name
        $cur_instance = Consortium::where('ccp_key', session('ccp_con_key'))->first();
        $conso_name = ($cur_instance) ? $cur_instance->name : "Template";

        // Get all roles
        $role_data = Role::orderBy('id', 'ASC')->get(['name', 'id']);
        $roles = array();
        foreach ($role_data as $_role) {
            if ($_role->id > auth()->user()->maxRole()) continue;
            if ($_role->name == "Manager") $_role->name = "Local Admin";
            if ($_role->name == 'Admin') $_role->name = "Consortium Admin";
            if ($_role->name == 'Viewer') $_role->name = "Consortium Viewer";
            $roles[] = $_role;
        }

        // Get institutions
        $institutionData = Institution::orderBy('name', 'ASC')->get(['id','name']);

        // Get institution groups , include members and non-members
        $data = InstitutionGroup::with('institutions:id,name')->orderBy('name', 'ASC')->get();
        $groups = array();
        foreach ($data as $group) {
            $group->count = $group->institutions->count();
            $members = $group->institutions->pluck('id')->toArray();
            $group->not_members = $institutionData->except($members);
            $groups[] = $group->toArray();
        }

        // Get master report definitions
        $master_reports = Report::where('revision',5)->where('parent_id',0)->orderBy('dorder','ASC')->get(['id','name']);

        // Get all (consortium) providers, extract array of global IDs
        $conso_providers = Provider::with('reports:id,name','institution:id,name,is_active')->orderBy('name','ASC')->get();

        // Get all active global providers
        $global_providers = GlobalProvider::where('is_active', true)->orderBy('name', 'ASC')->get();

        // Get COUNTER API settings for the active globals
        $global_ids = $global_providers->pluck('id')->toArray();
        $all_sushi_settings = SushiSetting::whereIn('prov_id',$global_ids)->with('lastHarvest')->get();

        // Build the array of providers we will pass back
        $output_providers = [];
        foreach ($global_providers as $rec) {
            $rec->global_prov = $rec->toArray();
            $rec->connectors = $rec->connectionFields();
            $rec->active = ($rec->is_active) ? 'Active' : 'Inactive';
            $sushi_settings = $all_sushi_settings->where('prov_id', $rec->id);  // settings for this provider

            // Setup connected institution data
            $connected_providers = $conso_providers->where('global_id',$rec->id);
            $rec->connection_count = count($connected_providers);
            $conso_connection = $connected_providers->where('inst_id',1)->first();
            $rec->can_connect = ($conso_connection) ? false : true;
            $conso_reports = ($conso_connection) ? $conso_connection->reports->pluck('id')->toArray() : [];
            $rec->conso_id = ($conso_connection) ? $conso_connection->id : null;
            // Reset master reports (from an array of IDs) to the globally available reports (array of objects)
            $master_ids = $rec->master_reports;
            $rec->master_reports = $master_reports->whereIn('id', $master_ids)->values()->toArray();
            $rec->is_conso = ($conso_connection) ? true : false;
            $rec->allow_inst_specific = ($conso_connection) ? $conso_connection->allow_inst_specific : 0; // default
            $rec->last_harvest_id = $sushi_settings->max('last_harvest_id');
            $rec->last_harvest = $sushi_settings->max('last_harvest');
            if ($rec->last_harvest_id > 0) {
                $_last = HarvestLog::where('id',$rec->last_harvest_id)->first();
                if ($_last) {
                    if ($_last->updated_at) {
                        $rec->last_harvest  = $_last->yearmon . " (run ";
                        $rec->last_harvest .= date("Y-m-d H:i", strtotime($_last->updated_at)) . ")";
                    }
                } else {
                    $rec->last_harvest_id = 0;
                }
            }
            $parsedUrl = parse_url($rec->server_url_r5);
            $rec->host_domain = (isset($parsedUrl['host'])) ? $parsedUrl['host'] : "-missing-";          

            // Setup flags to control per-report icons in the U/I
            $report_flags = $this->setReportFlags($master_reports, $master_ids, $conso_reports);
            foreach ($report_flags as $rpt) {
                $rec->{$rpt['name'] . "_status"} = $rpt['status'];
            }
            // If global provider is connected
            if ($rec->connection_count > 0) {
                $rec->inst_id = ($conso_connection) ? 1 : null;
                // Build an array of details for connected insts
                $all_inactive = true;
                $all_deleteable = true;
                $connected_data = array();
                foreach ($connected_providers as $prov_data) {
                    $_rec = $prov_data->toArray();
                    if ($rec->inst_id == null && $rec->connection_count == 1) {
                        $rec->inst_id = $prov_data->inst_id;
                    }
                    $_rec['day_of_month'] = $rec->day_of_month;
                    $_rec['host_domain'] = $rec->host_domain;
                    $_rec['inst_name'] = ($prov_data->inst_id == 1) ? 'Consortium' : $prov_data->institution->name;
                    $_rec['inst_stat'] = ($prov_data->institution->is_active) ? "isActive" : "isInactive";
                    $_inst_reports = $prov_data->reports->pluck('id')->toArray();
                    $combined_ids = array_unique(array_merge($conso_reports, $_inst_reports));
                    $_rec['master_reports'] = $rec->master_reports;
                    $_rec['report_state'] = $this->reportState($master_reports, $conso_reports, $combined_ids);
                    // last harvest (for connected) is based on the INST ($prov_data->inst_id) sushisetting
                    $_rec['last_harvest'] = null;         // default to no harvest
                    $_rec['last_harvest_id'] = 0;        //    "    "  "    "
                    if ($_rec['inst_id'] == 1) {          // set conso-wide to the global values
                        $_rec['last_harvest'] = $rec->last_harvest;
                        $_rec['last_harvest_id'] = $rec->last_harvest_id;
                    } else if ($_rec['inst_id'] > 1) {    // set to inst-specific values?
                        $_setting = $sushi_settings->where('inst_id',$_rec['inst_id'])->first();
                        if ($_setting) {
                            if ($_setting->last_harvest_id > 0 && $_setting->lastHarvest) {
                                $_rec['last_harvest']  = $_setting->lastHarvest->yearmon . " (run ";
                                $_rec['last_harvest'] .= date("Y-m-d H:i", strtotime($_setting->lastHarvest->updated_at)) . ")";
                                $_rec['last_harvest_id'] = $_setting->last_harvest_id;
                            }
                        }
                    }
                    $_rec['can_edit'] = true;
                    $_rec['can_delete'] = (is_null($_rec['last_harvest'])) ? true : false;
                    $_rec['allow_inst_specific'] = ($prov_data->inst_id == 1) ? $prov_data->allow_inst_specific : 0;
                    if ($_rec['is_active']) $all_inactive = false;
                    if (!$_rec['can_delete']) $all_deleteable = false;
                    $connected_data[] = $_rec;
                }
                $rec->connected = $connected_data;
                $rec->can_edit = true;
                if ($all_inactive) $rec->active = 'Inactive';
                $rec->can_delete = ($all_deleteable);

            // Not connected
            } else {
                $rec->connected = [];
                $rec->can_edit = false;
                $rec->can_delete = false; // unconnected globals only deletable by serverAdmin
                $rec->report_state = $this->reportState($master_reports, $conso_reports, []);
            }
            $output_providers[] = $rec->toArray();
        }
        $providers = array_values($output_providers);

        // Get global provider definitions for unconnected providers
        $existingIds = $conso_providers->pluck('global_id')->toArray();
        $unset_global = GlobalProvider::where('is_active', true)->whereNotIn('id',$existingIds)->orderBy('name', 'ASC')->get();
        $institutions = $institutionData->toArray();

        // Load the view with the data
        return view('admin.home', compact('conso_name','roles','institutions','groups','providers','master_reports',
                                          'unset_global'));
    }

    /**
     * Build array of flags by-report for the UI
     *
     * @param  Collection master_reports
     * @param  Array  $master_ids  (ID's available from the global platform)
     * @param  Array  $conso_enabled  (ID's enabled for the consortium)
     * @param  Array  $prov_enabled  (ID's enabled for the institution)
     * @return Array  $flags
     */
    private function setReportFlags($master_reports, $master_ids, $conso_enabled) {
        $flags = array();
        foreach ($master_reports as $mr) {
            $rpt = array('name' => $mr->name, 'status' => 'NA');
            if (in_array($mr->id, $conso_enabled)) {
                $rpt['status'] = 'C';
            } else if (in_array($mr->id, $master_ids)) {
                $rpt['status'] = 'A';
            }
            $flags[] = $rpt;
        }
        return $flags;
    }

    /**
     * Return an array of booleans for report-state from provider reports columns
     *
     * @param  Collection master_reports
     * @param  Array  $conso_enabled  (ID's)
     * @param  Array  $prov_enabled  (ID's)
     * @return Array  $report-state
     */
    private function reportState($master_reports, $conso_enabled, $prov_enabled) {
        $rpt_state = array();
        foreach ($master_reports as $rpt) {
            $rpt_state[$rpt->name] = array();
            $rpt_state[$rpt->name]['prov_enabled'] = (in_array($rpt->id, $prov_enabled)) ? true : false;
            $rpt_state[$rpt->name]['conso_enabled'] = (in_array($rpt->id, $conso_enabled)) ? true : false;
        }
        return $rpt_state;
    }

}
