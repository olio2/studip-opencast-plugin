<?php
/*
 * admin.php - admin plugin controller
 * Copyright (c) 2010  André Klaßen
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 */

require_once $this->trails_root.'/models/OCModel.php';
require_once $this->trails_root.'/models/OCEndpointModel.php';
require_once $this->trails_root.'/classes/OCRestClient/SearchClient.php';
require_once $this->trails_root.'/classes/OCRestClient/SeriesClient.php';
require_once $this->trails_root.'/classes/OCRestClient/CaptureAgentAdminClient.php';
require_once $this->trails_root.'/classes/OCRestClient/ServicesClient.php';
require_once $this->trails_root.'/classes/OCRestClient/WorkflowClient.php';

require_once $this->trails_root.'/classes/OCJobManager.php';
require_once $this->trails_root.'/classes/OCJob.php';
require_once $this->trails_root.'/classes/OCJobLocation.php';
require_once $this->trails_root.'/classes/OCJsonFile.php';
require_once $this->trails_root.'/classes/OCRestClient/IngestClient.php';
require_once $this->trails_root.'/classes/OCRestClient/UploadClient.php';


class AdminController extends OpencastController
{
    /**
     * Constructs the controller and provide translations methods.
     *
     * @param object $dispatcher
     * @see https://stackoverflow.com/a/12583603/982902 if you need to overwrite
     *      the constructor of the controller
     */
    public function __construct($dispatcher)
    {
        parent::__construct($dispatcher);

        $this->plugin = $dispatcher->current_plugin;

        // Localization
        $this->_ = function ($string) use ($dispatcher) {
            return call_user_func_array(
                [$dispatcher->current_plugin, '_'],
                func_get_args()
            );
        };

        $this->_n = function ($string0, $tring1, $n) use ($dispatcher) {
            return call_user_func_array(
                [$dispatcher->current_plugin, '_n'],
                func_get_args()
            );
        };
    }

    /**
     * Intercepts all non-resolvable method calls in order to correctly handle
     * calls to _ and _n.
     *
     * @param string $method
     * @param array  $arguments
     * @return mixed
     * @throws RuntimeException when method is not found
     */
    public function __call($method, $arguments)
    {
        $variables = get_object_vars($this);
        if (isset($variables[$method]) && is_callable($variables[$method])) {
            return call_user_func_array($variables[$method], $arguments);
        }
        throw new RuntimeException("Method {$method} does not exist");
    }

    /**
     * Common code for all actions: set default layout and page title.
     */
    function before_filter(&$action, &$args)
    {

        $this->flash = Trails_Flash::instance();

        // set default layout
        $layout = $GLOBALS['template_factory']->open('layouts/base');
        $this->set_layout($layout);

        // notify on trails action
        $klass = substr(get_called_class(), 0, -10);
        $name = sprintf('oc_admin.performed.%s_%s', $klass, $action);
        NotificationCenter::postNotification($name, $this);

    }

    /**
     * This is the default action of this controller.
     */
    function index_action()
    {
        $this->redirect('admin/config');
    }

    function config_action()
    {
        PageLayout::setTitle($this->_("Opencast Administration"));
        Navigation::activateItem('/admin/config/oc-config');

        $this->config = OCEndpointModel::getBaseServerConf();
    }


    private function getOCBaseVersion($service_host, $username, $password)
    {
        $oc = new OCRestClient([
            'service_url'      => $service_host,
            'service_user'     => $username,
            'service_password' => $password
        ]);

        $data = $oc->getJSON('/sysinfo/bundles/version?prefix=matterhorn');

        // always use the first found version information
        if (is_array($data->versions)) {
            $data = $data->versions[0];
        }

        return (int)substr($data->version, 0, 1);
    }

    function update_action()
    {
        // invalidate series-cache when editing configuration
        StudipCacheFactory::getCache()->expire('oc_allseries');

        foreach (Request::getArray('config') as $config_id => $config) {
            // if no data is given (i.e.: The selected config shall be deleted!),
            // remove config data properly
            if (!$config['url']) {
                OCRestClient::clearConfig($config_id);
                continue;
            }

            $service_url =  parse_url($config['url']);

            // check the selected url for validity
            if (!array_key_exists('scheme', $service_url)) {
                $this->flash['messages'] = [
                    'error' => sprintf(
                        $this->_('Ungültiges URL-Schema: "%s"'),
                        $config['url']
                    )
                ];
                OCRestClient::clearConfig($config_id);
            } else {
                $service_host =
                    $service_url['scheme'] .'://' .
                    $service_url['host'] .
                    (isset($service_url['port']) ? ':' . $service_url['port'] : '');

                $version = $this->getOCBaseVersion($service_host, $config['user'], $config['password']);

                OCRestClient::clearConfig($config_id);
                OCRestClient::setConfig($config_id, $service_host, $config['user'], $config['password'], $version);

                // check, if the same url has been provided for multiple oc-instances
                foreach (Request::getArray('config') as $zw_id => $zw_conf) {
                    if ($zw_id != $config_id && $zw_conf['url'] == $config['url']) {
                        $this->flash['messages'] = array(
                            'error' => sprintf(
                                $this->_('Sie haben mehr als einmal dieselbe URL für eine Opencast Installation angegeben.
                                    Dies ist jedoch nicht gestattet. Bitte korrigieren Sie Ihre Eingaben. URL: "%s"'),
                                 $config['url']
                            )
                        );

                        continue 2;
                    }
                }


                OCEndpointModel::setEndpoint($config_id, $service_host .'/services', 'services');

                $services_client = new ServicesClient($config_id);
                $comp = $services_client->getRESTComponents();

                if ($comp) {
                    $services = OCModel::retrieveRESTservices($comp, $service_url['scheme']);

                    foreach($services as $service_url => $service_type) {
                        OCEndpointModel::setEndpoint($config_id, $service_url, $service_type);
                    }

                    $success_message = sprintf(
                        $this->_("Änderungen wurden erfolgreich übernommen. Es wurden %s Endpoints für die angegebene Opencast Installation gefunden und in der Stud.IP Konfiguration eingetragen."),
                        count($services)
                    );

                    $this->flash['messages'] = array('success' => $success_message);
                } else {
                    OCEndpointModel::removeEndpoint($config_id, 'services');
                    $this->flash['messages'] = array(
                        'error' => sprintf(
                            $this->_('Es wurden keine Endpoints für die Opencast Installation mit der URL "%s" gefunden. Überprüfen Sie bitte die eingebenen Daten.'),
                            $service_host
                        )
                    );
                }
            }
        }

        // after updating the configuration, clear the cached series data
        OCSeriesModel::clearCachedSeriesData();

        $this->redirect('admin/config');
    }


    function endpoints_action()
    {
        PageLayout::setTitle($this->_("Opencast Endpoint Verwaltung"));
        Navigation::activateItem('/admin/config/oc-endpoints');
        // hier kann eine Endpointüberischt angezeigt werden.
        //$services_client = ServicesClient::getInstance();
        $this->endpoints = OCEndpointModel::getEndpoints();
    }

    function update_endpoints_action()
    {
        $this->redirect('admin/endpoints');
    }



    /**
     * brings REST URL in one format before writing in db
     */
    function cleanClientURLs()
    {
        $urls = array('series', 'search', 'scheduling', 'ingest', 'captureadmin'
            , 'upload', 'mediapackage');

        foreach($urls as $pre) {
            $var = $pre.'_url';
            $this->$var = rtrim($this->$var,"/");
        }

    }

    function resources_action()
    {
        PageLayout::setTitle($this->_("Opencast Capture Agent Verwaltung"));
        Navigation::activateItem('/admin/config/oc-resources');

        $this->resources = OCModel::getOCRessources();
        if(empty($this->resources)) {
            $this->flash['messages'] = array('info' => $this->_('Es wurden keine passenden Ressourcen gefunden.'));

        }

        $caa_client = CaptureAgentAdminClient::getInstance();
        $workflow_client = WorkflowClient::getInstance();

        $agents = $caa_client->getCaptureAgents();
        $this->agents = $caa_client->getCaptureAgents();


        foreach ($this->resources as $resource) {
            $assigned_agents = OCModel::getCAforResource($resource['resource_id']);
            if($assigned_agents){
                $existing_agent = false;
                foreach($agents as $key => $agent) {
                    if($agent->name ==  $assigned_agents['capture_agent']) {
                        unset($agents->$key);
                        $existing_agent = true;
                    }
                 }
                if(!$existing_agent){
                    OCModel::removeCAforResource($resource['resource_id'], $assigned_agents['capture_agent']);
                    $this->flash['messages'] = array('info' => sprintf($this->_("Der Capture Agent %s existiert nicht mehr und wurde entfernt."),$assigned_agents['capture_agent'] ));
                }
            }
        }

        $this->available_agents = $agents;
        $this->definitions = $workflow_client->getDefinitions();

        $this->assigned_cas = OCModel::getAssignedCAS();

        $this->workflows = array_filter(
            $workflow_client->getTaggedWorkflowDefinitions(),
            function ($element) {
                return (in_array('schedule', $element['tags']) !== false
                    || in_array('schedule-ng', $element['tags']) !== false)
                    ? $element
                    : false;
            }
        );

        $this->current_workflow = OCCourseModel::getWorkflowWithCustomCourseID('default_workflow','upload');
    }


    function update_resource_action()
    {
        $this->resources = OCModel::getOCRessources();
        foreach ($this->resources as $resource) {
            if (Request::get('action') == 'add') {
                if (($candidate_ca = Request::get($resource['resource_id'])) && $candidate_wf = Request::get('workflow')) {
                    $success = OCModel::setCAforResource($resource['resource_id'], $candidate_ca, $candidate_wf);
                }
            }
        }

        $messages = [];

        if ($success) {
            $messages['success'][] = $this->_("Capture Agents wurden zugewiesen.");
        }

        $workflow = Request::get('oc_course_uploadworkflow');
        if (!OCCourseModel::getWorkflowWithCustomCourseID('default_workflow', 'upload')) {
            $workflow_success = OCCourseModel::setWorkflowWithCustomCourseID('default_workflow', $workflow, 'upload');
        } else {
            $workflow_success = OCCourseModel::updateWorkflowWithCustomCourseID('default_workflow', $workflow, 'upload');
        }

        if ($workflow_success) {
            $messages['success'][] = $this->_("Standardworkflow eingestellt.");
            $override = Request::option('override_other_workflows','off'); // on / off
            if($override == 'on'){
                $override_success = OCCourseModel::removeWorkflowsWithoutCustomCourseID('default_workflow','upload');
                if($override_success){
                    $messages['success'][] = $this->_("Andere Workflow Einstellungen wurden entfernt.");
                }else{
                    $messages['error'][] = $this->_('Andere Workflows konnten nicht entfernt werden.');
                }
            }
        }else{
            $messages['error'][] = $this->_("Standardworkflow konnte nicht eingestellt werden.");
        }

        foreach ($messages as $type=>$collection){
            $messages[$type] = implode(' ',$collection);
        }
        $this->flash['messages'] = $messages;

        $this->redirect('admin/resources');
    }

    function remove_ca_action($resource_id, $capture_agent)
    {
        OCModel::removeCAforResource($resource_id, $capture_agent);
        $this->redirect('admin/resources');
    }

    // client status
    function client_action()
    {
        $caa_client    = CaptureAgentAdminClient::getInstance();
        $this->agents  = $caa_client->getCaptureAgents();
    }

    function refresh_episodes_action($ticket){
        if(check_ticket($ticket) && $GLOBALS['perm']->have_studip_perm('admin',$this->course_id)) {
            $stmt = DBManager::get()->prepare("SELECT DISTINCT ocs.seminar_id, ocs.series_id FROM oc_seminar_series AS ocs WHERE 1");
            $stmt->execute(array());
            $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (is_array($courses)) {
                foreach ($courses as $course) {

                    $ocmodel = new OCCourseModel($course['seminar_id']);
                    $ocmodel->getEpisodes(true);
                    unset($ocmodel);
                }
                $this->flash['messages'] = array('success' => $this->_("Die Episodenliste aller Series  wurde aktualisiert."));
            }
        }
        $this->redirect('admin/config/');
    }

    function mediastatus_action(){
        PageLayout::setTitle($this->_("Opencast Medienstatus"));
        Navigation::activateItem('/admin/config/oc-mediastatus');

        // EPISODES
        $series_data = OCSeriesModel::getSeminarAndSeriesData();
        for($index = 0; $index<count($series_data); $index++){
            $oc_course = new OCCourseModel($series_data[$index]['seminar_id']);
            $series_data[$index]['episodes'] = $oc_course->getEpisodes(true);
        }
        $this->uploaded_episodes = $series_data;
        $this->uploading_episodes = OCModel::getWorkflowStatesFromSQL(OCModel::getWorkflowIDs());

        //OPENCAST TMP-DIRECTORY CONTENT
        $undeleted_jobs = OCJobManager::existent_jobs();
        $this->upload_jobs = ['successful'=>[],'unfinished'=>[]];
        foreach ($undeleted_jobs as $undeleted_job_id){
            $job = new OCJob($undeleted_job_id);
            $this->upload_jobs[($job->both_uploads_succeeded()?'successful':'unfinished')][] = $job;
        }

        $this->memory_space = OCJobManager::save_dir_size();
    }
}
?>
