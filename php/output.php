<?php
/*Copyright (c) 2009-2013, Dan "Ducky" Little & GeoMOOSE.org

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

/*
 * GeoMOOSE 2 Output Service
 */
 
$LATLONG_PROJ = ms_newprojectionobj('epsg:4326');
$DEBUG = false;
include('config.php');

# Turn off the warning reporting
if(!$DEBUG) { error_reporting(E_ERROR | E_PARSE); }

# Grab projection from config or use projection sent from mapbook
$projection = $CONFIGURATION['projection'];
if(array_key_exists('projection', $_REQUEST) and isset($_REQUEST['projection'])) {
	$projection = urldecode($_REQUEST['projection']);
}

/*
Assuming we are passing in the following through the dictionary:
	UNIQUEID = timestamp
	SHAPEPATH = path to the temp folder
	SHAPE_WKT = the original query shape in wkt
	queryShape = the final queried shape object
	FINAL_SHAPE_WKT = the final output in wkt
	foundShapes = number of results
	MAP_PROJECTION = the projection of the map/layer
	results = html results
	foundShapesArray = an array of all the results
	SELECT_LAYER = original selected layer (html template only)
	QUERY_LAYER = original queried layer (html template only)
	SELECTION_BUFFER = how much to buffer (html template only)
*/

function outputDatabase($dict, $type) {
	global $CONFIGURATION, $mapbook;
	$filePath = $dict['SHAPEPATH'].'/'.$dict["UNIQUEID"];
	
	$fields = array('geomoose_id text', 'wkt_geometry text', 'attributes text');
	$sqlFilename = $filePath . '.db';

	# make a sqlite connection
	try {
		$sqlite = new PDO('sqlite:'.$sqlFilename);
	} catch(Exception $e) {
		echo "Failed to connect!<br>";
		echo $sqlFilename."<br>";
		echo $e->getMessage();
	}

	# create the featuers table
	$sqlite->beginTransaction();
	$sqlite->exec('create table featurespoint ('.implode(',', $fields).')');
	$sqlite->exec('create table featuresline ('.implode(',', $fields).')');
	$sqlite->exec('create table featurespolygon ('.implode(',', $fields).')');
	$sqlite->commit();
	
	$sqlite->beginTransaction();
	foreach($dict["foundShapesArray"] as $shape) {
		$recordArray = array($shape->text, $shape->toWKT(), json_encode($shape->values));
		switch($shape->type) {
			case MS_SHAPE_POINT:
				$insert_sql = "insert into featurespoint values ('".implode("','", str_replace("'", "''", $recordArray))."')";
				break;
			case MS_SHAPE_LINE:
				$insert_sql = "insert into featuresline values ('".implode("','", str_replace("'", "''", $recordArray))."')";
				break;
			case MS_SHAPE_POYLGON:
			default:
				$insert_sql = "insert into featurespolygon values ('".implode("','", str_replace("'", "''", $recordArray))."')";
				break;
		}
		$sqlite->exec($insert_sql);
	}
	$sqlite->commit();
	
	# Just setting the geometry type for the map file
	switch($dict["queryShape"]->type) {
		case MS_SHAPE_POINT:
			$dict["INPUTTYPE"] = "Point";
			break;
		case MS_SHAPE_LINE:
			$dict["INPUTTYPE"] = "Line";
			break;
		case MS_SHAPE_POLYGON:
		default:
			$dict["INPUTTYPE"] = "Polygon";
			break;
	}
	
	if ($dict['SHAPE_WKT']) { //If they actually drew a shape (not query)
		$sqlite->beginTransaction();
		$sqlite->exec('create table selection (wkt_geometry text, area text)');
		$cursor = $sqlite->prepare("insert into selection values (?,?)");

		$drawnShape = $dict['SHAPE_WKT'];
		
		$shape = ''; 
		$shape_type = '';
		$cursor->bindParam(1, $shape);
		$cursor->bindParam(2, $shape_type);

		$shape = $drawnShape; 
		$shape_type = 'DRAWN';
		$cursor->execute();

		$shape = $dict["queryShape"]->toWKT(); 
		$shape_type = 'QUERY';
		$cursor->execute();
		$sqlite->commit();
		$dict["DATA"] = "DATA 'selection'";
		$dict["CONNECTION"] = "CONNECTION '" . $dict["SHAPEPATH"] . "/" . $dict["UNIQUEID"] . ".db'";
	} else {// Turn off the Selection layer
		$dict["DATA"] = "";
		$dict["CONNECTION"] = "";
	}
	
	# Remove from dictionary (messes with templates)
	unset($dict['queryShape'], $dict['foundShapesArray']);

	# Form the mapfile.
	$mapfile = implode('', file($CONFIGURATION['highlight_map']));
	$mapfile = processTemplate($mapfile, $dict);
	
	$mapfileOut = fopen($filePath.'.map', 'w+');
	fwrite($mapfileOut, $mapfile);
	fclose($mapfileOut);

	# All that work for a dozen lines of output.
	header('Content-type: application/xml');
	print "<results>";
	print "<script><![CDATA[";
	if ($type == "WFS") {
		print " GeoMOOSE.turnLayerOff('highlightPoint');";
		print " GeoMOOSE.turnLayerOff('highlightLine');";
		print " GeoMOOSE.turnLayerOff('highlightPoly');";
		print " GeoMOOSE.turnLayerOff('highlightSelect');";
		print " GeoMOOSE.changeLayerUrl('highlightPoint', CONFIGURATION.mapserver_url + '?map=$filePath.map');";
		print " GeoMOOSE.turnLayerOn('highlightPoint');";
		print " GeoMOOSE.refreshLayers('highlightPoint');";
		print " GeoMOOSE.changeLayerUrl('highlightLine', CONFIGURATION.mapserver_url + '?map=$filePath.map');";
		print " GeoMOOSE.turnLayerOn('highlightLine');";
		print " GeoMOOSE.refreshLayers('highlightLine');";
		print " GeoMOOSE.changeLayerUrl('highlightPoly', CONFIGURATION.mapserver_url + '?map=$filePath.map');";
		print " GeoMOOSE.turnLayerOn('highlightPoly');";
		print " GeoMOOSE.refreshLayers('highlightPoly');";
		print " GeoMOOSE.changeLayerUrl('highlightSelect', CONFIGURATION.mapserver_url + '?map=$filePath.map');";
		print " GeoMOOSE.turnLayerOn('highlightSelect');";
		print " GeoMOOSE.refreshLayers('highlightSelect');";
		print " if (GeoMOOSE.hideParcelDataTable) GeoMOOSE.hideParcelDataTable(); GeoMOOSE.tableLayer='" . $dict["SELECT_LAYER"] . "';";
	} else if ($type == "WMS") {
		print " GeoMOOSE.turnLayerOff('highlight/highlight');";
		print " GeoMOOSE.clearLayerParameters('highlight');";
		print " GeoMOOSE.changeLayerUrl('highlight', CONFIGURATION.mapserver_url);";
		print " GeoMOOSE.updateLayerParameters('highlight', { 'map' : '".$filePath.".map', 'FORMAT' : 'image/png', 'TRANSPARENT' : 'true'});";
		print " GeoMOOSE.turnLayerOn('highlight/highlight');";
		print " GeoMOOSE.refreshLayers('highlight/highlight');";
	}
	print "]]></script>";
	print "<html><![CDATA[";
	print processTemplate($dict['results'], $dict);
	print "]]></html>";
	print "<footer><![CDATA[";
	print $dict['footer'];
	print "]]></footer>";
	print "</results>";
}

function outputHTML($dict) {
	# Remove from dictionary (messes with templates)
	unset($dict['queryShape'], $dict['foundShapesArray']);
	
	header('Content-type: application/xml');
	print "<results>";
	print "<script><![CDATA[";
	print "]]></script>";
	print "<html><![CDATA[";
	print processTemplate($dict['results'], $dict);
	print "]]></html>";
	print "<footer><![CDATA[";
	print $dict['footer'];
	print "]]></footer>";
	print "</results>";
}

function outputMemory($dict) {
	$mode = get_request_icase('mode');
	if($mode == "") {
		if(get_request_icase('service') == 'WMS') {
			$mode = 'map';
		} else {
			$mode = 'search';
		}
	}
	$zoomToFirst = parseBoolean(get_request_icase('zoom_to_first'));

	#
	# Didn't find any results
	# so we're going to just say, "I found nothing" to the user and quit.
	#
	if($dict['foundShapes'] == 0) {
		header('Content-type: text/xml');
		print '<results><html><![CDATA[';
		print implode('', file($CONFIGURATION['query_miss']));
		print ']]></html></results>';
		exit(0);
	}

	if($mode == 'search') {
		header('Content-type: text/xml');
		print "<results n='".$dict['foundShapes']."'>";
		print "<script><![CDATA[";

		print "GeoMOOSE.changeLayerUrl('highlight', './php/" . $dict["fileName"] . ".php');";
		$partial_params = array();
		foreach($_REQUEST as $p => $v) {
			if($p != 'mode' && strtoupper($p) != 'NULL') {
				array_push($partial_params, sprintf("'%s' : '%s'", $p, $v));
			}
			if($p == 'layers') {
				array_push($partial_params, sprintf("'%s' : '%s'", "visible_layers", $v));
			}
		}
		$partial_params[] = "'TRANSPARENT' : 'true'";
		$partial_params[] = "'FORMAT' : 'image/png'";
		print "GeoMOOSE.clearLayerParameters('highlight');";		
		print "GeoMOOSE.updateLayerParameters('highlight', {".implode(',',$partial_params)."});";
		print "GeoMOOSE.turnLayerOn('highlight/highlight');";
		print "GeoMOOSE.refreshLayers('highlight/highlight');";

		# If there is only one results ... zoom to it!
		# or zoom to the first result if requested.
		if($dict['foundShapes'] == 1 or ($dict['foundShapes'] >= 1 and $zoomToFirst == true)) {
			$bounds = $dict['foundShapesArray'][0]->bounds;
			printf('GeoMOOSE.zoomToExtent(%f,%f,%f,%f);Map.zoomOut();Map.zoomOut();', $bounds->minx, $bounds->miny, $bounds->maxx, $bounds->maxy);
		} 
		print "]]></script>";
		print "<html><![CDATA[";
		print $dict['results'];
		print "]]></html>";
		print "<footer><![CDATA[";
		print $dict['footer'];
		print "]]></footer>";
		print "</results>";
	} else if($mode == 'map') {
		# Remove from dictionary (messes with templates)
		$features = $dict['foundShapesArray'];
		unset($dict['queryShape'], $dict['foundShapesArray']);
		
		$mapfile = implode('', file('itemquery/highlight.map'));
		$mapfile = processTemplate($mapfile, $dict);

		$highlight_map = ms_newMapObjFromString($mapfile);
		$polygonsLayer = $highlight_map->getLayerByName('polygons');
		$pointsLayer = $highlight_map->getLayerByName('points');
		$linesLayer = $highlight_map->getLayerByName('lines');

		for($i = 0; $i < sizeof($features); $i++) {
			if($features[$i]->type == MS_SHAPE_POINT) {
				$pointsLayer->addFeature($features[$i]);
			} elseif($features[$i]->type == MS_SHAPE_POLYGON) {
				$polygonsLayer->addFeature($features[$i]);
			} elseif($features[$i]->type == MS_SHAPE_LINE) {
				$linesLayer->addFeature($features[$i]);
			}
		}

		# get the WMS parameters.
		$request = ms_newowsrequestobj();
		$request->loadparams();
		$request->setParameter("LAYERS", "highlight");

		# handle the wms request
		ms_ioinstallstdouttobuffer();

		$highlight_map->owsdispatch($request);
		$contenttype = ms_iostripstdoutbuffercontenttype();

		# put the image out to the stdout with the content-type attached
		header('Content-type: '.$contenttype);
		ms_iogetStdoutBufferBytes();
		ms_ioresethandlers();
	}
}
?>