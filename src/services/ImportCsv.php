<?php
/**
 * csv-to-category-importer plugin for Craft CMS 3.x
 *
 * Reads a CSV file and imports the rows. It turns each row into a location category.
 *
 * @link      http://samhenderson.xyz/
 * @copyright Copyright (c) 2018 Sam Henderson
 */

namespace workyard\csvtocategoryimporter\services;

use workyard\csvtocategoryimporter\Csvtocategoryimporter;
use craft\base\Component;
use craft;
use craft\elements\Category;


/**
 * ImportCsv Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Sam Henderson
 * @package   Csvtocategoryimporter
 * @since     1.0.0
 */
class ImportCsv extends Component
{


    const LOCATION_UNTOUCHED = "Record already existed. No changes were made.";
    const LOCATION_UPDATED = "Record already existed & was updated.";
    const LOCATION_CREATED = "New record created";


    // Public Methods
    // =========================================================================

    /**
     * @param $file_url (String)
     * @param $save_to (String)
     * @return mixed  (Array of locations)
     */


    public function readLocationsFromCsv($file_url="http://craft.test/assets/data/ToyLocationImport.csv", $save_to='../temp_data_storage.csv')
    {

        // Get the CSV file.

        $client = new \GuzzleHttp\Client();
        $client->request('GET', $file_url, ['save_to' => $save_to]);
        $csv_file = @fopen("../temp_data_storage.csv", "r");


        // Convert the CSV file to an array.

        $array = $fields = array();
        $i = 0;
        if ($csv_file) {
            while (($row = fgetcsv($csv_file, 4096)) !== false) {
                if (empty($fields)) {
                    $fields = $row;
                    continue;
                }
                foreach ($row as $k => $value) {
                    $array[$i][$fields[$k]] = $value;
                }
                $i++;
            }
            if (!feof($csv_file)) {
                echo "Error: unexpected fgets() fail\n";
            }
            fclose($csv_file);
        }


        // Order the parent locations first (so child locations can reference them).

        $locations = $array;
        $parent_locations = [];
        $children_locations = [];
        foreach ($locations as $location) {

            $isParent = !isset($location['Parent Category']) || $location['Parent Category'] === "";

            if ($isParent) {
                array_push($parent_locations, $location);
            } else {
                array_push($children_locations, $location);
            }
            $locations = array_merge($parent_locations, $children_locations);
        }
        return $locations;
    }

    /**
     * @param $arrayA (Array)
     * @param $arrayB (Array)
     * @return boolean
     */

    public function identical_values($arrayA, $arrayB)
    {
        sort($arrayA);
        sort($arrayB);
        return $arrayA == $arrayB;
    }

    /**
     * @param $locations (Array)
     * @return array
     */

    public function updateOrCreateLocations($locations)
    {
        $location_category_group_id = Craft::$app->categories->getGroupByHandle('locations')->id;

        $report = [];

        // Loop through each location from the CSV file to update Latitude, Longitude and Parent Category.

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
                $this_report->status = self::LOCATION_UNTOUCHED;
            } // If category needs to be created or updated.
            else {

                $is_updating_required = $does_category_already_exist && !$are_category_properties_identical;
                $category = $is_updating_required ? $existing_category : new Category();

                $category->groupId = $location_category_group_id;
                $category->slug = $slug;
                $category->title = $title;


                $category->setFieldValues([
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);


                $category->newParentId = $is_a_valid_parent_specified_in_csv ? $new_parent->id : null;

                Craft::$app->elements->saveElement($category);
                $this_report->status = $is_updating_required ? self::LOCATION_UPDATED : self::LOCATION_CREATED;
            }

            $report[$key] = $this_report;
        }

        return $report;
    }

    /**
     * @param $locations (Array)
     * @param $report (Array)
     * @return array
     */

    public function updateNearbyLocations($locations, $report)
    {
        $failed_location_uploads = [];

        foreach ($locations as $key=>$location) {

            $slug = $location['Slug'];
            $title = $location['Name'];
            $parent_slug = $location['Parent Category'];
            $location_category_group_id = Craft::$app->categories->getGroupByHandle('locations')->id;

            $existing_category = Category::find()
                ->groupId($location_category_group_id)
                ->slug($slug)
                ->title($title)
                ->one();

            $new_parent = Category::find()
                ->slug($parent_slug)
                ->one();

            $nearby_locations_string_from_csv = str_replace(' ', '', $location['Nearby Locations']); // e.g. santa-monica,palos-verdes-estate
            $nearby_locations_slugs_from_csv = $nearby_locations_string_from_csv == '' ? [] : explode(",", $nearby_locations_string_from_csv); // e.g. ["santa-monica","palos-verdes-estate"]
            $nearby_location_ids = [];

            foreach ($nearby_locations_slugs_from_csv as $nearby_location_slug) {
                $nearby_location = Category::find()
                    ->groupId($location_category_group_id)
                    ->slug($nearby_location_slug)
                    ->one();

                if (!is_null($nearby_location)) {
                    array_push($nearby_location_ids, $nearby_location->id);
                } else {

                    $failed_upload = new \stdClass();
                    $failed_upload->location = $slug;
                    $failed_upload->nearby_location_that_failed = $nearby_location_slug;
                    array_push($failed_location_uploads, $failed_upload);
                }
            }


            $existing_locations_nearby_locations = $existing_category
                ->getFieldValue("nearbyLocations")
                ->descendantOf($new_parent); // get children (not LA or Sydney)


            $existing_nearby_location_slugs = [];

            foreach ($existing_locations_nearby_locations as $existing_locations_nearby_location) {
                $existing_nearby_location_slug = $existing_locations_nearby_location['slug'];
                array_push($existing_nearby_location_slugs, $existing_nearby_location_slug);
            }

            $are_nearby_categories_the_same = self::identical_values($existing_nearby_location_slugs, $nearby_locations_slugs_from_csv);


            if (!$are_nearby_categories_the_same) {

                $existing_report = null;
                $index = null;

                foreach ($report as $key=>$this_report) {
                    if ($this_report->title === $title) {
                        $index = $key;
                        $existing_report = $this_report;
                        break;
                    }
                }

                if ($existing_report && $existing_report->status === self::LOCATION_UNTOUCHED) {
                    $report[$index]->status = self::LOCATION_UPDATED;
                }


                // If category needs to be created or updated.

                $category = $existing_category;

                $category->setFieldValues([
                    'nearbyLocations' => $nearby_location_ids
                ]);

                Craft::$app->elements->saveElement($category);

            }

        }
        return ['report' => $report, 'failed_uploads' => $failed_location_uploads];
    }
}
