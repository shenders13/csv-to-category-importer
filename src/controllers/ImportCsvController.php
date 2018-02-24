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
        $file_url = "http://craft.test/assets/data/ToyLocationImport.csv";
        $locations = Csvtocategoryimporter::$plugin->importCsv->readLocationsFromCsv($file_url);
        $location = $locations[2];

//        $parent_slug = $location['Parent Category'];
//        $parent = Category::find()
//            ->slug($parent_slug)
//            ->one();

        $category = Category::find()
            ->slug($location['Slug'])
            ->one();

        $parent_slug = $location['Parent Category'];
        $new_parent = Category::find()
            ->slug($parent_slug)
            ->one();

        $is_parent_specified = $new_parent && $new_parent->slug === $parent_slug;

//        $category->newParentId = $parent->id;

//        Craft::$app->elements->saveElement($category);

        return json_encode($is_parent_specified);
    }

    /**
     * Handle a request going to our plugin's actionDoSomething URL,
     * e.g.: actions/csv-to-category-importer/import-csv/upload-csv
     *
     * @return mixed
     */
    public function actionUploadCsv()
    {

        $file_url = Craft::$app->request->getBodyParams()['url'];
        $locations = Csvtocategoryimporter::$plugin->importCsv->readLocationsFromCsv($file_url);



        $location_category_group_id = Craft::$app->categories->getGroupByHandle('locations')->id;

        $report = [];

        // Loop through each location from the CSV file.


        foreach ($locations as $key=>$location) {

            $slug = $location['Slug'];
            $title = $location['Name'];
            $latitude = $location['Latitude'];
            $longitude = $location['Longitude'];
            $parent_slug = $location['Parent Category'];


            // Determine if category already exists (existing_category).

            $does_category_already_exist = false;
            $are_category_properties_identical = false;

            $existing_category = Category::find()
                ->groupId($location_category_group_id)
                ->slug($slug)
                ->title($title)
                ->one();

            $new_parent = Category::find()
                ->slug($parent_slug)
                ->one();

            $is_a_valid_parent_specified_in_csv = $new_parent && $new_parent->slug === $parent_slug;


            // Determine if existing category needs updating.

            if ($existing_category) {

                $does_category_already_exist = true;

                $existing_latitude = number_format((float)$existing_category->getFieldValue('latitude'), 6, '.', '');
                $existing_longitude = number_format((float)$existing_category->getFieldValue('longitude'), 6, '.', '');

                $existing_category_parent = $existing_category->getParent();

                $is_latitude_the_same = $existing_latitude == $latitude;
                $is_longitude_the_same = $existing_longitude == $longitude;
                $is_parent_the_same = $existing_category_parent && $new_parent && $existing_category_parent->slug == $new_parent->slug;


                $are_category_properties_identical = $is_latitude_the_same &&
                                                     $is_longitude_the_same &&
                                                     $is_parent_the_same;
            }



            $this_report = new \stdClass;
            $this_report->title = $title;



            // If location category doesn't need to be updated, do nothing.
            if ($does_category_already_exist && $are_category_properties_identical) {
                $this_report->status = "Record already existed. No changes were made.";
            }


            // If category needs to be created or updated.
            else {

                $is_updating_required = $does_category_already_exist && !$are_category_properties_identical;
                $category = $is_updating_required ? $existing_category : new Category();

                $category->groupId = $location_category_group_id;
                $category->slug = $slug;
                $category->title = $title;
                $category->setFieldValues([
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ]);

                $category->newParentId = $is_a_valid_parent_specified_in_csv ? $new_parent->id  : null;

                Craft::$app->elements->saveElement($category);
                $this_report->status = $is_updating_required ? "Record already existed & was updated." : "New record created";

            }

            $report[$key] = $this_report;

        }

        return $this->renderTemplate('csv-to-category-importer/report', ['report' => $report]);

    }


    public function actionReturnCsvData()
    {
        $this->redirectToPostedUrl();
    }
}
