<?php
/* Opencast plugin constants */
require_once 'constants.php';

/* Stud.IP dependencies*/
require_once 'lib/models/Institute.class.php';
require_once 'lib/raumzeit/raumzeit_functions.inc.php';
require_once 'vendor/trails/trails.php';

/* OC plugin dependencies*/
require_once 'classes/Configuration.php';
require_once 'classes/ConfigurationAction.php';

require_once 'classes/lti/OAuth.php';
require_once 'classes/lti/AccessControlList.php';
require_once 'classes/lti/OpencastLTI.php';
require_once 'classes/lti/LtiLink.php';
require_once 'models/OCAccessControl.php';

require_once 'controllers/opencast_controller.php';

// StudipAutoloader::addAutoloadPath(__DIR__ . '/classes');
StudipAutoloader::addAutoloadPath(__DIR__ . '/models', 'Opencast\\Models');

require_once 'models/OCModel.php';
require_once 'models/OCCourseModel.class.php';
require_once 'models/OCSeriesModel.php';

require_once 'classes/OCJobManager.php';
require_once 'classes/OCJob.php';
require_once 'classes/OCJobLocation.php';
require_once 'classes/OCJsonFile.php';
require_once 'classes/OCPerm.php';

require_once 'classes/OCRestClient/OCRestClient.php';

require_once 'classes/OCRestClient/ACLManagerClient.php';
require_once 'classes/OCRestClient/ArchiveClient.php';
require_once 'classes/OCRestClient/ApiEventsClient.php';
require_once 'classes/OCRestClient/ApiSeriesClient.php';
require_once 'classes/OCRestClient/ApiWorkflowsClient.php';
require_once 'classes/OCRestClient/CaptureAgentAdminClient.php';
require_once 'classes/OCRestClient/IngestClient.php';
require_once 'classes/OCRestClient/OCRestClient.php';
require_once 'classes/OCRestClient/SchedulerClient.php';
require_once 'classes/OCRestClient/SearchClient.php';
require_once 'classes/OCRestClient/SeriesClient.php';
require_once 'classes/OCRestClient/ServicesClient.php';
require_once 'classes/OCRestClient/UploadClient.php';
require_once 'classes/OCRestClient/WorkflowClient.php';


/* cronjobs */
require_once 'lib/classes/CronJob.class.php';
