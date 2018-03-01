<?php
/**
 * csv-to-category-importer plugin for Craft CMS 3.x
 *
 * Reads a CSV file and imports the rows. It turns each row into a location category.
 *
 * @link      http://samhenderson.xyz/
 * @copyright Copyright (c) 2018 Sam Henderson
 */

namespace workyard\csvtocategoryimporter\controllers;
use workyard\csvtocategoryimporter\Csvtocategoryimporter;
use craft\web\Controller as BaseController;
use craft\elements\Category;
use craft;
use workyard\csvtocategoryimporter\services\ImportCsv as ImportSvc;

/**
 * ImportCsv Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Sam Henderson
 * @package   Csvtocategoryimporter
 * @since     1.0.0
 */
class ImportCsvController extends BaseController
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected $allowAnonymous = ['index', 'upload-csv'];

    // Public Methods
    // =========================================================================

    /**
     * Handle a request going to our plugin's index action URL,
     * e.g.: actions/csv-to-category-importer/import-csv
     *
     * @return mixed
     */



    public function actionIndex()
    {
        $file_url = "http://craft.test/assets/data/ToyLocationImport.csv";
        $import_svc = new ImportSvc();
        $locations = $import_svc->readLocationsFromCsv($file_url);

        $location = $locations[3];

        $location_category_group_id = Craft::$app->categories->getGroupByHandle('locations')->id;

        $nearby_locations_string_from_csv = $location['Nearby Locations']; // e.g. santa-monica,palos-verdes-estate
        $nearby_locations_slugs_from_csv = explode(",", $nearby_locations_string_from_csv); // e.g. ["santa-monica","palos-verdes-estate"]



        // Get all nearby location categories associated with the slugs from the CSV.

        $nearby_locations = [];
        foreach ($nearby_locations_slugs_from_csv as $nearby_location_slug) {
            $nearby_location = Category::find()
                ->groupId($location_category_group_id)
                ->slug($nearby_location_slug)
                ->one();

            if (!is_null($nearby_location)) {
                array_push($nearby_locations, $nearby_location);
            }

        }



        // Get the existing location category associated with the location in the CSV

        $existing_location = Category::find()
            ->groupId($location_category_group_id)
            ->slug($location["Slug"])
            ->one();




        // Get the category object associated with the "Parent Category" slug from the CSV file.

        $existing_location_parent = Category::find()
            ->slug($location["Parent Category"])
            ->one();

        // TODO: delete $existing_nearby_location_slugs
        $existing_nearby_location_slugs = [];

        $are_nearby_categories_the_same = false;

        if ($existing_location) {


            $existing_locations_nearby_locations = $existing_location
                                                    ->getFieldValue("nearbyLocations")
                                                    ->descendantOf($existing_location_parent) // get children (not LA or Sydney)
                                                    ->asArray();


            foreach ($existing_locations_nearby_locations as $existing_locations_nearby_location) {
                $existing_nearby_location_slug = $existing_locations_nearby_location['slug'];
                array_push($existing_nearby_location_slugs, $existing_nearby_location_slug);
            }

//            function identical_values( $arrayA , $arrayB ) {
//                sort( $arrayA );
//                sort( $arrayB );
//                return $arrayA == $arrayB;
//            }

            $are_nearby_categories_the_same = identical_values($existing_nearby_location_slugs, $nearby_locations_slugs_from_csv);

        }


        $result = [
            'location' => $location['Name'],
            'are_nearby_categories_the_same' => $are_nearby_categories_the_same,
            'nearby_locations_slugs_from_csv' => $nearby_locations_slugs_from_csv,
            'existing_nearby_location_slugs' => $existing_nearby_location_slugs,
        ];


        // initialise nearby_locations (actual category objects from Nearby Location slugs in csv)

        // Calculate existing_nearby_locations
        // Calculate are_nearby_categories_the_same. Add that to $are_category_properties_identical

        // Add nearbyLocations key to the setFieldValues call and insert nearby_locations

//        $existing_locations_nearby = count($existing_location->getFieldValue("nearbyLocations")); // array of categories



        return \GuzzleHttp\json_encode($result);
    }

    /**
     * Handle a request going to our plugin's actionUploadCsv URL,
     * e.g.: actions/csv-to-category-importer/import-csv/upload-csv
     *
     *  1) Check if the record in the CSV exists already and has all properties the same as specified
     *     in the CSV
     *  2) If the record doesn't exist or has a field that needs updated
     *      - Update every field to be what is specified in the CSV.
     *
     * @return mixed
     */
    public function actionUploadCsv()
    {

        // Read the CSV file at the specified file_url and convert it into an array called locations.

        $file_url = str_replace(' ', '', Craft::$app->request->getBodyParams()['url']);
        $import_svc = new ImportSvc();
        $locations = $import_svc->readLocationsFromCsv($file_url);


        // Update or create any locations that need it. If updating, we only update the latitude, longitude & parent in
        // updateOrCreateLocations.

        $initial_report = $import_svc->updateOrCreateLocations($locations);


        // Now that all the locations are saved and updated in the database, we can update each location's nearbyLocations
        // field. We couldn't do this on the first pass as there may be locations in the nearbyLocations that had not
        // yet been saved.


        $nearby_location_update = $import_svc->updateNearbyLocations($locations, $initial_report);
        $report = $nearby_location_update['report'];
        $failed_nearby_location_uploads = $nearby_location_update['failed_uploads'];

        return $this->renderTemplate('csv-to-category-importer/report',
            [
                'report' => $report,
                'failed_nearby_location_uploads' => $failed_nearby_location_uploads
            ]
        );

    }


    public function actionReturnCsvData()
    {
        $this->redirectToPostedUrl();
    }
}
