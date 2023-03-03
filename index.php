<?php
error_reporting(E_ERROR|E_PARSE);
?>
<html>
<head>
<title>CubeSat</title>
<link rel='stylesheet' href='assets/css/style.css' />
</head>
<body>

<?php

$imgUrl = 'https://db-satnogs.freetls.fastly.net/media/';
$url = 'https://db.satnogs.org/api/satellites/';
$apiKey = array('?norad_cat_id=&status=dead&in_orbit=&sat_id=', /*[0] DEAD*/ '?norad_cat_id=&status=alive&in_orbit=&sat_id=', /*[1] ALIVE*/ '?norad_cat_id=&status=future&in_orbit=&sat_id=') /*[2] FUTURE*/;
$arrayInput = isset($_POST['input']) ? $_POST['input'] : 0;
$api = ($url . $apiKey[$arrayInput]); //get api call from user input
$json = file_get_contents($api);
$array = json_decode($json, true);

// (Debugging:) Dump API Array Information
#echo '<details><summary>API Array Information</summary>';
#var_dump($array);
#echo '</details>';

echo "
<div class='center'>
	<form method='post'>
		<input class='btn' type='submit' name='input' value='0' />
		<input class='btn' type='submit' name='input' value='1' />
		<input class='btn' type='submit' name='input' value='2' />
		<br /><br />
	</form>
</div>
";

echo "<div class='flex-container'>";

//(Debugging:) Dump Telemetry Data

// Specify the path to the CSV file
$csvFile = 'assets/telemetries/CubeSatSim_maxtelemetry.csv'; // TODO: Create a dynamic call to the required CSV telemetry file

// Open the CSV file for reading
$fileHandle = fopen($csvFile, 'r');

// Read the contents of the CSV file and display them
if ($fileHandle !== false) {
    echo '<br />';
    echo '<details>';
    echo '<summary><strong>' . 'Telemetry API Call: ' . basename($csvFile, ".csv") . '</strong></summary>';
    
    while (($data = fgetcsv($fileHandle, 1000, ',')) !== false) {
        // $data is an array containing the values for each row in the CSV file
        // Do something with $data, such as displaying it on the screen
        echo implode(',', $data);
    }
    
    echo '</details>';

    // Close the file handle
    fclose($fileHandle);
} else {
    echo 'Failed to open file: ' . $csvFile;
}

echo '<br /><br />';

foreach ($array as $object) {
	
	$id = $object['sat_id'];
	$name = $object['name'];
	
	$dateStr = $object['launched'];
	$timestamp = strtotime($dateStr);
	$launchDate = date("F j, Y", $timestamp);
	
	$status = strtoupper($object['status']);
		if (isset($object['image'])) {
			$imageUrl = $imgUrl . $object['image'];
			$imageSize = @getimagesize($imageUrl);
		if ($imageSize) {
			$image = $imageUrl;
		} else {
			$image = 'https://imgs.search.brave.com/jdfgo5AXBDB5xhLbdRCKhwyOhEv3H5XRy7wsc4NGlek/rs:fit:800:600:1/g:ce/aHR0cHM6Ly9zcGFj/ZWZsaWdodDEwMS5j/b20vd3AtY29udGVu/dC91cGxvYWRzLzIw/MTYvMDkvYnBjX3Bs/ZWlhZGVzLXNhdGVs/bGl0ZS1pbGx1c3Ry/YXRpb25fcDMxMDc5/LmpwZw';
		}} else {
			$image = 'https://imgs.search.brave.com/jdfgo5AXBDB5xhLbdRCKhwyOhEv3H5XRy7wsc4NGlek/rs:fit:800:600:1/g:ce/aHR0cHM6Ly9zcGFj/ZWZsaWdodDEwMS5j/b20vd3AtY29udGVu/dC91cGxvYWRzLzIw/MTYvMDkvYnBjX3Bs/ZWlhZGVzLXNhdGVs/bGl0ZS1pbGx1c3Ry/YXRpb25fcDMxMDc5/LmpwZw';
		}

echo "
	<div class='flex-item'>
	<img class='flex-img' src='$image'>
		<div class='flex-txt'>
			<strong>Satellite ID:</strong> $id <br />
			<strong>Name:</strong> $name <br />
			<strong>Launch Date:</strong> $launchDate <br />
			<strong>Status:</strong> $status
		</div>
	</div>
";

}

echo "</div>";

?>
</body>
</html>
