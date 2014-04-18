<?php
/*Copyright (c) 2009-2011, Dan "Ducky" Little & GeoMOOSE.org

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.*/

include('config.php');

require('fpdf/fpdf.php');

# Turn off the warning reporting
$DEBUG = false;

if(!$DEBUG) {
	error_reporting(E_ERROR | E_PARSE);
}

# Get the query id from which we are going to get the files
$query_id = urldecode($_REQUEST['queryid']);

# format for the labels
$output = urldecode($_REQUEST['output']);
if(!isset($output)) { $output = 'html'; }

$qValue = json_decode(urldecode($_REQUEST['qstring']),true);
$queryItem = urldecode($_REQUEST['qitem']);
$layersToSearch = explode(':', urldecode($_REQUEST['qlayers']));

# get some config
$tempDirectory = $CONFIGURATION['temp'];

# get some layout information
$maxRows = $CONFIGURATION['label_rows'];
$maxColumns = $CONFIGURATION['label_columns'];

# This will load the line template into an Array
$labelLines = (int)$CONFIGURATION['label_lines'];
$lineTemplate = array();
for($i = 1; $i <= $labelLines; $i++) {
	array_push($lineTemplate, $CONFIGURATION['label_line_'.$i]);
}

$mapbook = getMapbook();
$msXML = $mapbook->getElementsByTagName('map-source');

# Array to store the popups found.
$content = '';
$totalResults = 0;
$firstResult = false;

$resultFeatures = array();

for($i = 0; $i < $msXML->length; $i++) {
	$node = $msXML->item($i);
	$layers = $node->getElementsByTagName('layer');
	for($l = 0; $l < $layers->length; $l++) {
		$layer = $layers->item($l);
		$layerName = $layer->getAttribute('name');
		$path = $node->getAttribute('name').'/'.$layerName;
		if(in_array($path, $layersToSearch)) {
			$file = $node->getElementsByTagName('file')->item(0)->firstChild->nodeValue;
			# Okay, now it's time to cook
			if(substr($file,0,1) == '.') {
				$file = $CONFIGURATION['root'].$file;
			}
			$map = ms_newMapObj($file);

			# Create an array of query layers
			$queryLayers = array();
			if($layerName == 'all') {
				for($ml = 0; $ml < $map->numlayers; $ml++) {
					array_push($queryLayers, $map->getLayer($ml));
				}
			} else {
				# Turn on the specific layer
				array_push($queryLayers, $map->getLayerByName($layerName));
			}

			# Iterate through the queryLayers...
			foreach($queryLayers as $queryLayer) {
				foreach($qValue as $queryValue) {
					$queryLayer->set('template', 'dummy.html');
					$queryLayer->set('status', MS_DEFAULT);
					$queryLayer->set('filteritem', $queryItem);
					$queryLayer->setFilter($queryValue);
					$queryLayer->queryByRect($queryLayer->getExtent());

					$queryLayer->open();
					$numResults = $queryLayer->getNumResults();
					for($rx = 0; $rx < $numResults; $rx++) {
						$res = $queryLayer->getShape($queryLayer->getResult($rx));
						array_push($resultFeatures, $res);
					}	
				}
				$queryLayer->close();
			}
		}
	}
}

$numRecords = sizeof($resultFeatures);
# put the labels into a larger array
$allAddresses = array();

# this is added to support a more elaborate CSV format
$csv_addresses = array();

for($i = 0; $i < $numRecords; $i++) {
	$rec = $resultFeatures[$i]->values;
	$address = array();
	for($line = 0; $line < $labelLines; $line++) {
		$address[$line] = $lineTemplate[$line];
		foreach($rec as $k=>$v) {
			$address[$line] = str_replace('%'.$k.'%', trim($v), $address[$line]);
		}
		$address[$line] = trim($address[$line]);
	}

	array_push($allAddresses, $address);

	# build the virtual csv file
	$current_address = $CONFIGURATION['csv_record'];
	foreach($rec as $k=>$v) {
		$current_address = str_replace('%'.$k.'%', trim($v), $current_address);
	}

	$csv_addresses[] = $current_address;

}

# So I wrote this to just test the functionality.  It seems to work pretty well.
if($output == 'html') {
	print "<html><body>";
	$i = 0;
	$column = 0;
	$row = 0;
	while($i < $numRecords) {
		if($row == 0 and $column == 0) {
			print "<table>";
		}
		if($column == 0) {
			print "<tr>";
		}

		print "<td>";
		for($line = 0; $line < $labelLines; $line++) {
			print $allAddresses[$i][$line]."<br/>";
		}
		print "</td>";
		$i++;
		$column++;

		if($column >= $maxColumns) {
			print "</tr>";
			$column = 0;
			$row++;
		}
		if($row >= $maxRows) {
			print "</table>";
			print "<br/></br>";
			$row = 0;
			$column  = 0;
			print "<table>";
		}

	}
	print "</table>";
	print "</body></html>";
} elseif($output == 'csv') {
	header('Content-type: text/csv');
	header('Content-Disposition: attachment; filename="mailing_labels.csv"');
	#header("Content-type: text/plain");

	if($CONFIGURATION['csv_header'] != NULL and $csv_addresses[0] != NULL) {
		print $CONFIGURATION['csv_header']."\n";
		for($i = 0; $i < sizeof($csv_addresses); $i++) {
			print $csv_addresses[$i]."\n";
		}
	} else {
		$columnTitles = array();
		for($i = 0; $i < $labelLines; $i++) {
			$columnTitles[$i] = "LINE".($i+1);
		}
		$str = '"'.implode('","',$columnTitles)."\"\n";
		print $str;

		foreach($allAddresses as $label) {
			$str = '"'.implode('","',$label)."\"\n";
			print $str;
		}
	}
} elseif($output == 'pdf') {
	header('Content-type: application/pdf');

	# New PDF Object
	$PageSize = array(11,8.5);

	$pdf = new FPDF('L','in', $PageSize);
	$pdf->SetAutoPageBreak(false);

	$LABEL_FONT = $CONFIGURATION['label_font'];
	$LABEL_FONT_SIZE = (float)$CONFIGURATION['label_font_size'];

	$LABEL_ORIGIN_X = (float)$CONFIGURATION['label_origin_x'];
	$LABEL_ORIGIN_Y = (float)$CONFIGURATION['label_origin_y'];

	$LABEL_WIDTH = (float)$CONFIGURATION['label_width'];
	$LABEL_HEIGHT = (float)$CONFIGURATION['label_height'];


	$pdf->AddPage();
	$pdf->SetMargins(.5,.5,.5);
	$pdf->SetFont($LABEL_FONT,'',$LABEL_FONT_SIZE);
	$pdf->SetTextColor(0,0,0);


	$CurrentX = $LABEL_ORIGIN_X;
	$CurrentY = $LABEL_ORIGIN_Y;
	$CurrentRow = 0;
	$CurrentColumn = 0;
	foreach($allAddresses as $label) {
		$lineY = $CurrentY;
		foreach($label as $line) {
			$lineFontSize = $LABEL_FONT_SIZE;
			$pdf->SetXY($CurrentX,$lineY);
			$pdf->Cell(0,.25,$line,0,0,'L');
			# Make the lines even despite variable sizes
			$lineY = $lineY + $LABEL_FONT_SIZE/72;
		}
		$CurrentY += $LABEL_HEIGHT;
		$CurrentRow++;
		if($CurrentRow >= $maxRows) {
			$CurrentRow = 0;
			$CurrentColumn ++;
			$CurrentX+=$LABEL_WIDTH;
			$CurrentY = $LABEL_ORIGIN_Y;
			if($CurrentColumn >= $maxColumns) {
				$CurrentColumn = 0;
				$CurrentRow = 0;
				$CurrentY = $LABEL_ORIGIN_Y;
				$CurrentX = $LABEL_ORIGIN_X;
				$pdf->AddPage();
				$pdf->SetMargins(.5,.5,.5);

			}
		}
	}
#	$pdf->Output('/tmp/www/out.pdf');
	$pdf->Output();
}


?>
