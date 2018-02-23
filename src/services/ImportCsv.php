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
    // Public Methods
    // =========================================================================

    /**
     * This function can literally be anything you want, and you can have as many service
     * functions as you want
     *
     * From any other plugin file, call it like this:
     *
     *     Csvtocategoryimporter::$plugin->importCsv->exampleService()
     *
     * @return mixed
     */


    public function readLocationsFromCsv($file_url) {


        // Get the CSV file.

        $client = new \GuzzleHttp\Client();
//        $url = 'http://craft.test/assets/data/'.$file_name;
        $client->request('GET', $file_url, ['save_to' => '../temp_data_storage.csv']);
        $csv_file =  @fopen("../temp_data_storage.csv", "r");


        // Convert the CSV file to an array.

        $array = $fields = array(); $i = 0;
        if ($csv_file) {
            while (($row = fgetcsv($csv_file, 4096)) !== false) {
                if (empty($fields)) {
                    $fields = $row;
                    continue;
                }
                foreach ($row as $k=>$value) {
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

}
