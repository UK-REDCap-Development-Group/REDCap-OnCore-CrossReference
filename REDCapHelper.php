<?php

class REDCapHelper {

    public static function saveDataDictionary(int $pid, array $metadata) {
        // 1. Get the project object
        $project = new \Project($pid);

        // 2. Convert your flat array to the format REDCap expects
        $dd_array = \MetaData::convertFlatMetadataToDDarray($metadata);

        // 3. Instead of hitting the DB directly, use the dedicated save method
        // which handles the 'doc_id' and 'redcap_data_dictionaries' table automatically.
        // The second parameter 'false' skips the file-upload logging if you aren't uploading a file.
        $errors = \MetaData::save_metadata($dd_array, false, true, $pid);

        if (!empty($errors)) {
            throw new \Exception("Upload errors: " . strip_tags(implode("\n", $errors)));
        }

        return count($dd_array['A']);
    }


    private static function adaptToUTF8(&$ary) {
        if (!json_encode($ary)) {
            if (json_last_error() == JSON_ERROR_UTF8) {
                $ary = self::utf8ize($ary);
            } else {
                throw new \Exception("Error in JSON processing: " . json_last_error_msg());
            }
        }
    }

    private static function utf8ize($mixed) {
        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mixed[$key] = self::utf8ize($value);
            }
        } elseif (is_string($mixed)) {
            return utf8_encode($mixed);
        }
        return $mixed;
    }

}
