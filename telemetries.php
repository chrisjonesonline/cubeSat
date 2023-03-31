<?php

$csvFile = "assets/telemetries/FOX1E_rttelemetry.csv"; // Select CSV file

// Open the CSV file
$fileHandle = fopen($csvFile, "r");

// Read the contents of the CSV file and display them
if ($fileHandle !== false) {
	echo "<details><summary><strong>" . "Telemetry API Call: " . basename($csvFile, ".csv") . "</strong></summary>";
    
    while (($data = fgetcsv($fileHandle, 1000, ",")) !== false) {
        // $data is an array containing the values for each row in the CSV file
        // Do something with $data, such as displaying it on the screen
        echo implode(",", $data);
    }
    
    echo "</details>";

    // Close the file handle
    fclose($fileHandle);
} else {
    echo "Failed to open file: " . $csvFile;
}

?>
