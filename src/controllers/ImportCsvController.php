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
    protected $allowAnonymous = ['index', 'do-something'];

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
        $result = 'Welcome to the ImportCsvController actionIndex() method ';

        return json_encode($result);
    }

    /**
     * Handle a request going to our plugin's actionDoSomething URL,
     * e.g.: actions/csv-to-category-importer/import-csv/do-something
     *
     * @return mixed
     */
    public function actionDoSomething()
    {

        $file_url = Craft::$app->request->getBodyParams()['url'];
        $file_name = 'ToyLocationImport.csv';
        $locations = Csvtocategoryimporter::$plugin->importCsv->readLocationsFromCsv($file_url);
        $location_category_group_id = Craft::$app->categories->getGroupByHandle('locations')->id;

        $report = [];

        // Loop through each location from the CSV file.

        foreach ($locations as $key=>$location) {

            $slug = $location['Slug'];
            $title = $location['Name'];
            $latitude = $location['Latitude'];
            $longitude = $location['Longitude'];

            // Determine if category already exists.

            $does_category_already_exist = false;
            $are_category_field_values_identical = false;

            $existing_category = Category::find()
                ->groupId($location_category_group_id)
                ->slug($slug)
                ->title($title)
                ->one();

            // Determine if existing category needs updating.

            if ($existing_category) {

                $does_category_already_exist = true;

                $existing_latitude = number_format((float)$existing_category->getFieldValue('latitude'), 6, '.', '');
                $existing_longitude = number_format((float)$existing_category->getFieldValue('longitude'), 6, '.', '');

                $are_field_values_matching = $existing_latitude == $latitude &&
                    $existing_longitude == $longitude;
                if ($are_field_values_matching) {
                    $are_category_field_values_identical = true;
                }
            }

            $this_report = new \stdClass;
            $this_report->title = $title;
            $this_report->status = "No change";

            // If category doesn't exist yet, make a new category.

            if (!$does_category_already_exist) {

                $category = new Category();
                $category->groupId = $location_category_group_id;
                $category->slug = $slug;
                $category->title = $title;
                $category->setFieldValues([
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ]);

                Craft::$app->elements->saveElement($category);
                $this_report->status = "Created";


            } // If category exists but some fields need updating, update fields.

            elseif ($does_category_already_exist && !$are_category_field_values_identical) {

                $existing_category->groupId = $location_category_group_id;
                $existing_category->slug = $slug;
                $existing_category->setFieldValues([
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ]);

                Craft::$app->elements->saveElement($existing_category);
                $this_report->status = "Updated";

            }

            $report[$key] = $this_report;


        }

//        $this->returnJson($report);

//        Craft::$app->urlManager->setRouteVariables(array('variable' => $report));
//        return Craft::$app->view->renderTemplate('csv-to-category-importer/report', $report);
        return $this->renderTemplate('csv-to-category-importer/report', ['report' => $report]);

    }


    public function actionReturnCsvData()
    {
        $this->redirectToPostedUrl();
    }
}
