<?php
# @name: crowdsource_search.php
# @version: 0.2
# @creation_date: 2019-05-29
# @license: The MIT License <https://opensource.org/licenses/MIT>
# @author: Simon Bowie <sb174@soas.ac.uk>
# @purpose: A prototype of a web application to crowdsource cataloguing for SOAS' bibliographic records
# @description: This page retrieves and displays search results for bibliographic records by querying OLE's Apache Solr API. It then directs the user to an edit page for the record they select.
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<title>SOAS Library crowdsourced cataloguing</title>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
<!--===============================================================================================-->	
	<link rel="icon" type="image/png" href="images/icons/soas-favicon.ico"/>
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="vendor/bootstrap/css/bootstrap.min.css">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="fonts/font-awesome-4.7.0/css/font-awesome.min.css">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="vendor/animate/animate.css">
<!--===============================================================================================-->	
	<link rel="stylesheet" type="text/css" href="vendor/css-hamburgers/hamburgers.min.css">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="vendor/animsition/css/animsition.min.css">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="vendor/select2/select2.min.css">
<!--===============================================================================================-->	
	<link rel="stylesheet" type="text/css" href="vendor/daterangepicker/daterangepicker.css">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="css/util.css">
	<link rel="stylesheet" type="text/css" href="css/soas.css">
<!--===============================================================================================-->
</head>
<body>
<?php
require __DIR__ . '/vendor/autoload.php';

### RETRIEVE CONFIGURATION VARIABLES FROM THE CONFIG.ENV FILE
$dotenv = Dotenv\Dotenv::create(__DIR__, 'config.env');
$dotenv->load();

### CONNECT TO GOOGLE SHEETS API
/*
 * We need to get a Google_Client object first to handle auth and api calls, etc.
 */
$client = new \Google_Client();
$client->setApplicationName('crowdsource');
$client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
$client->setAccessType('offline');

/*
 * The JSON auth file can be provided to the Google Client in two ways, one is as a string which is assumed to be the
 * path to the json file. This is a nice way to keep the creds out of the environment.
 */
#$jsonAuth = getenv('JSON_AUTH');
#$client->setAuthConfig(json_decode($jsonAuth, true));
$client->setAuthConfig(__DIR__ . '/crowdsource-ecca04407a4e.json');

/*
 * With the Google_Client we can get a Google_Service_Sheets service object to interact with sheets
 */
$sheets = new \Google_Service_Sheets($client);

### RETRIEVE THE LANGUAGE FOR THE APPLICATION TO WORK ON FROM THE GOOGLE SHEETS SPREADSHEET IDENTIFIED IN THE CONFIG.ENV FILE
$spreadsheetId = $_ENV['spreadsheet_id'];
$range = 'config!A3';

#$language = $_ENV['language'];
$language_array = $sheets->spreadsheets_values->get($spreadsheetId, $range);
$language = $language_array['values'][0][0];

?>

	<div class="limiter">
		<div class="container-login100">
			<div class="wrap-login100">
				<div class="logo-div">
					<a href="index.php"><img src="images/soas-logo-transparent.png" alt="SOAS Library" class="logo"></a>
				</div>
				<div class="login100-form p-l-55 p-r-55 p-t-150">
					<!-- THE LANGUAGE OF THE APPLICATION IS DETERMINED BY A VARIABLE SET IN THE GOOGLE SHEETS SPREADSHEET IDENTIFIED IN THE CONFIG.ENV FILE -->
					<span class="login100-form-title">
						Help us learn <?php echo $language; ?>
					</span>

					<div class="content100">
<?php

	### RETRIEVE THE SEARCH PARAMETER INPUTTED BY THE USER ON INDEX.PHP
	$search = urlencode($_POST["search"]);

	### ASSEMBLE A QUERY STRING TO SEND TO SOLR. THIS USES THE SOLR HOSTNAME FROM THE CONFIG.ENV FILE. SOLR'S QUERY SYNTAX CAN BE FOUND AT MANY SITES INCLUDING https://lucene.apache.org/solr/guide/6_6/the-standard-query-parser.html
	### THIS QUERY RETRIEVES ONLY THE BIB IDENTIFIER FIELD WHICH CAN BE USED TO UNIQUELY IDENTIFY RECORDS
	$solrurl = $_ENV['solr_hostname'] . '/solr/bib/select?fl=bibIdentifier&fq=DocType:bibliographic&fq=Language_search:' . $language . '&indent=on&q=Title_search:' . $search . '%20OR%20Author_search:' . $search . '%20OR%20Publisher_search:' . $search . '%20OR%20PublicationDate_search:' . $search . '%20OR%20PublicationPlace_search:' . $search . '%20OR%20LocalId_display:' . $search . '%20OR%20ItemBarcode_search:' . $search . '%20OR%20ISBN_search:' . $search . '&rows=5000&wt=xml';

	### PERFORM CURL REQUEST ON THE SOLR API
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $solrurl);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_HEADER, FALSE);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
	$response = curl_exec($ch);
	curl_close($ch);
	
	### TURN THE API RESPONSE INTO USEFUL XML
	$xml = new SimpleXMLElement($response); 
	
	### IF NO RESULTS ARE FOUND, DISPLAY A MESSAGE
	if ($xml->result->attributes()->numFound == '0'){
?>
					<div class="wrap-result100">
						<div class="wrap-header100">
							<div class="wrap-content100 p-t-05 p-b-50">
							No results found
							</div>
						</div>
					</div>
<?php
	}
	### ELSE FOR EACH RESULT FOUND, RETRIEVE FULL MARC BIBLIOGRAPHIC RECORDS FROM THE OLE DOCSTORE API AND DISPLAY RELEVANT FIELDS
	else {
		foreach ($xml->result->doc as $result){
			foreach ($result->arr->str as $id){
				
				### REMOVE THE wbm- PREFIX FROM THE BIB IDENTIFIER
				$bib_id = ltrim($id, "wbm-");
				### ASSEMBLE A URL FOR THE DOCSTORE API. THIS USES THE DOCSTORE HOSTNAME FROM THE CONFIG.ENV FILE
				$baseurl = $_ENV['docstore_hostname'] . '/oledocstore/documentrest/';
				$retrieve_bib = '/bib/doc?bibId=';
	
				### PERFORM CURL REQUEST ON THE OLE DOCSTORE API
				$ch = curl_init();
				$queryParams = $bib_id;
				curl_setopt($ch, CURLOPT_URL, $baseurl . $retrieve_bib . $queryParams);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($ch, CURLOPT_HEADER, FALSE);
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
				$response = curl_exec($ch);
				curl_close($ch);
	
				### TURN THE API RESPONSE INTO USEFUL XML
				$xml = new SimpleXMLElement($response); 
	
				$content = $xml->content;
				$content = new SimpleXMLElement($content);
	
				### XML NAMESPACES ARE IMPROPERLY SET IN MARCXML SO WE HAVE TO ASSIGN A NAMESPACE IN ORDER TO USE XPATH TO PERFORM ADVANCED XML RETRIEVAL BELOW
				foreach($content->getDocNamespaces() as $strPrefix => $strNamespace) {
					if(strlen($strPrefix)==0) {
						$strPrefix="a"; //Assign an arbitrary namespace prefix.
					}
					$content->registerXPathNamespace($strPrefix,$strNamespace);
				}		
?>			
				<div class="wrap-result100">
					<!-- EACH RESULT DISPLAYS THE TITLE OF THE BIB RECORD -->
					<div class="wrap-header100">
						<div class="wrap-content100 p-t-05 p-b-10">
							<strong>Title:</strong>
<?php
							### DISPLAY EACH SUBFIELD (THAT IS NOT A $6 SUBFIELD) FOR EACH 245 FIELD
							foreach ($content->xpath("///a:datafield[@tag='245']/a:subfield[@code!='6']") as $subfield) {
								echo (string) $subfield . " ";
							}
?>
						</div>
					</div>

				<!-- IF THERE IS AN ALTERNATIVE TITLE FIELD, DISPLAY THAT -->
<?php
				if ($content->xpath("///a:datafield[@tag='246']/a:subfield[@code!='6']")):
?>
					<div class="wrap-header100">
						<div class="wrap-content100 p-t-05 p-b-10">
							<strong>Alternative title:</strong>
<?php
							### DISPLAY EACH SUBFIELD (THAT IS NOT A $6 SUBFIELD) FOR EACH 246 FIELD
							foreach ($content->xpath("///a:datafield[@tag='246']/a:subfield[@code!='6']") as $subfield) {
								echo (string) $subfield . " ";
							}
?>
						</div>
					</div>
<?php
					endif;
?>

				<!-- IF THERE IS A MAIN AUTHOR FIELD, DISPLAY THAT -->
<?php
				if ($content->xpath("///a:datafield[@tag='100']/a:subfield[@code!='6']")):
?>
					<div class="wrap-header100">
						<div class="wrap-content100 p-t-05 p-b-10">
							<strong>Main author:</strong>
<?php
							### DISPLAY EACH SUBFIELD (THAT IS NOT A $6 SUBFIELD) FOR EACH 100 FIELD
							foreach ($content->xpath("///a:datafield[@tag='100']/a:subfield[@code!='6']") as $subfield) {
								echo (string) $subfield . " ";
							}
?>
						</div>
					</div>
<?php
					endif;
?>

				<!-- IF THERE IS A PUBLICATION DETAILS FIELD, DISPLAY THAT. NOTE THAT PUBLICATION DETAILS MAY BE IN EITHER THE 260 OR THE 264 FIELD -->
<?php
				if ($content->xpath("///a:datafield[@tag='260']/a:subfield[@code!='6']|///a:datafield[@tag='264']/a:subfield[@code!='6']")):
?>
					<div class="wrap-header100">
						<div class="wrap-content100 p-t-05 p-b-10">
							<strong>Publication details:</strong>
<?php
							### DISPLAY EACH SUBFIELD (THAT IS NOT A $6 SUBFIELD) FOR EACH 260 FIELD OR 264 FIELD
							foreach ($content->xpath("///a:datafield[@tag='260']/a:subfield[@code!='6']|///a:datafield[@tag='264']/a:subfield[@code!='6']") as $subfield) {
								echo (string) $subfield . " ";
							}
?>
						</div>
					</div>
<?php
					endif;
?>
			
			<!-- THIS FORM SENDS THE 001 BIB IDENTIFIER TO CROWDSOURCE_EDIT.PHP AS A HIDDEN PARAMETER. WE WILL USE IT IN CROWDSOURCE_EDIT TO RETRIEVE BIB DETAILS FROM THE MARC RECORD VIA THE OLE DOCSTORE API -->
			<form class="p-l-55 p-r-55 p-b-75" action="crowdsource_edit.php" method="POST">

				<input type="hidden" value="
			<?php 
				### CREATE A HIDDEN INPUT VALUE FOR EACH 001 FIELD (A RECORD SHOULD ONLY EVER HAVE ONE 001 FIELD
				foreach ($content->xpath("///a:controlfield[@tag='001']") as $controlfield) {
					echo (string) $controlfield;
				}
			?>
				" name="id" />
			
				<div class="container-login100-form-btn">
					<button class="login100-form-btn">
						Edit
					</button>
				</div>
			</form>
		</div>
<?php
			}
		}
	}
?>
					<span class="flex-col-c p-b-20">
						<a href="crowdsource_about.php">About the project</a>
					</span>
					<span class="flex-col-c p-b-20">
						<a href="mailto:libenquiry@soas.ac.uk">Send feedback</a>
					</span>
					<span class="flex-col-c p-b-40">
						<a href="index.php">Home</a>
					</span>
				</div>
			</div>
		</div>
	</div>
</div>
			
<!--===============================================================================================-->
	<script src="vendor/jquery/jquery-3.2.1.min.js"></script>
<!--===============================================================================================-->
	<script src="vendor/animsition/js/animsition.min.js"></script>
<!--===============================================================================================-->
	<script src="vendor/bootstrap/js/popper.js"></script>
	<script src="vendor/bootstrap/js/bootstrap.min.js"></script>
<!--===============================================================================================-->
	<script src="vendor/select2/select2.min.js"></script>
<!--===============================================================================================-->
	<script src="vendor/daterangepicker/moment.min.js"></script>
	<script src="vendor/daterangepicker/daterangepicker.js"></script>
<!--===============================================================================================-->
	<script src="vendor/countdowntime/countdowntime.js"></script>
<!--===============================================================================================-->
	<script src="js/main.js"></script>
<!--===============================================================================================-->

</body>
</html>